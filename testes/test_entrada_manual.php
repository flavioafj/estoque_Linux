<?php
/** Teste isolado: não carrega .env, não acessa bancos reais e não envia e-mails. */
$root = getenv('ESTOQUE_TEST_ROOT') ?: dirname(__DIR__);
define('LOG_PATH', sys_get_temp_dir() . '/entrada-manual-' . bin2hex(random_bytes(6)));
mkdir(LOG_PATH);
require $root . '/vendor/autoload.php';
spl_autoload_register(function ($class) use ($root) {
    $path = $root . '/src/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($path)) require_once $path;
});

// SQLite executa os modelos reais; apenas a sintaxe de bloqueio MySQL é removida.
class TestPDO extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $query = str_replace(' FOR UPDATE', '', $query);
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}
function check($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
function database(): PDO {
    $pdo = new TestPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));
    $pdo->sqliteCreateFunction('JSON_UNQUOTE', fn($v) => $v);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec(<<<'SQL'
CREATE TABLE tipos_movimentacao (id INTEGER PRIMARY KEY, tipo TEXT);
INSERT INTO tipos_movimentacao VALUES (1, 'ENTRADA'), (2, 'SAIDA');
CREATE TABLE produtos (id INTEGER PRIMARY KEY, estoque_atual INTEGER DEFAULT 0);
INSERT INTO produtos (id) VALUES (1), (2), (3);
CREATE TABLE movimentacoes (id INTEGER PRIMARY KEY AUTOINCREMENT, tipo_movimentacao_id INTEGER, data_movimentacao TEXT, usuario_id INTEGER, fornecedor_id INTEGER, observacoes TEXT, status TEXT, inventario_id INTEGER, valor_total NUMERIC DEFAULT 0, documento_numero TEXT);
CREATE TABLE movimentacao_itens (id INTEGER PRIMARY KEY AUTOINCREMENT, movimentacao_id INTEGER REFERENCES movimentacoes(id), produto_id INTEGER REFERENCES produtos(id), quantidade INTEGER, valor_unitario NUMERIC, valor_total NUMERIC);
CREATE TABLE estoques (id INTEGER PRIMARY KEY AUTOINCREMENT, produto_id INTEGER REFERENCES produtos(id), valor_unitario NUMERIC);
CREATE TABLE sincronizacao (id INTEGER PRIMARY KEY AUTOINCREMENT, tabela TEXT, registro_id INTEGER, acao TEXT, dados TEXT, sincronizado INTEGER, tentativas INTEGER, erro_mensagem TEXT, criado_em TEXT, sincronizado_em TEXT);
CREATE TABLE auditoria (id INTEGER PRIMARY KEY AUTOINCREMENT, tabela TEXT, registro_id INTEGER, acao TEXT, dados_anteriores TEXT, dados_novos TEXT, usuario_id INTEGER, ip_address TEXT, user_agent TEXT);
CREATE VIEW vw_produtos_estoque_critico AS SELECT id FROM produtos WHERE 0;
SQL);
    $ref = new ReflectionClass(Models\Database::class);
    $db = $ref->newInstanceWithoutConstructor();
    $ref->getProperty('connection')->setValue($db, $pdo);
    $ref->getProperty('instance')->setValue(null, $db);
    Helpers\SyncQueueHelper::initialize($pdo);
    return $pdo;
}
function countRows(PDO $pdo, string $table): int {
    return (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
}
function mustFail(callable $fn) {
    try { $fn(); } catch (Throwable $e) { return; }
    throw new RuntimeException('Esperava falha.');
}
function emptyEntry(PDO $pdo) {
    foreach (['movimentacoes', 'movimentacao_itens', 'estoques', 'sincronizacao', 'auditoria'] as $table) {
        check(countRows($pdo, $table) === 0, 'Rollback incompleto: ' . $table);
    }
    check((int) $pdo->query('SELECT SUM(estoque_atual) FROM produtos')->fetchColumn() === 0, 'Saldo não revertido.');
}
try {
    $data = ['tipo_movimentacao_id' => 1, 'user_id' => 7, 'observacao' => 'Devolução balcão', 'itens' => [1 => '2', 2 => '3', 3 => '0'], 'valores' => [1 => '4.50', 2 => '2.00', 3 => '0']];
    $local = database();
    $service = new Services\EntradaManualService();
    $id = $service->registrar($data);
    $event = $local->query('SELECT * FROM sincronizacao')->fetch();
    $payload = json_decode($event['dados'], true);
    check($event['acao'] === 'ENTRADA_MANUAL' && (int) $event['sincronizado'] === 0, 'Evento não está pendente.');
    check(count($payload['itens']) === 2 && $payload['total'] == 15, 'Itens ou total incorretos.');
    check($payload['observacao'] === 'Devolução balcão', 'Observação perdida.');
    check(countRows($local, 'estoques') === 5 && countRows($local, 'movimentacao_itens') === 2, 'Entrada local incorreta.');
    check($service->receber($payload) === $id && countRows($local, 'movimentacoes') === 1, 'Eco duplicou origem.');

    $remote = database();
    $receiver = new Services\EntradaManualService();
    $remote->exec("INSERT INTO movimentacoes (id) VALUES (100)");
    $remote->beginTransaction(); // Mesmo contrato dos receptores PUSH/PULL.
    $remoteId = $receiver->receber($payload);
    check($remote->inTransaction(), 'Serviço confirmou transação do chamador.');
    $remote->commit();
    check($remoteId !== $id, 'Destino deve gerar seu próprio ID.');
    $receiver->receber($payload);
    check(countRows($remote, 'movimentacoes') === 2 && countRows($remote, 'estoques') === 5, 'Reenvio duplicou entrada.');
    check(countRows($remote, 'sincronizacao') === 1, 'Reenvio duplicou recibo.');
    check((int) $remote->query('SELECT sincronizado FROM sincronizacao')->fetchColumn() === 1, 'Recibo gerou novo envio.');
    check((float) $remote->query('SELECT valor_total FROM movimentacoes WHERE id = 101')->fetchColumn() === 15.0, 'Total remoto incorreto.');
    check((int) $remote->query('SELECT SUM(estoque_atual) FROM produtos')->fetchColumn() === 5, 'Saldo remoto incorreto.');
    echo "OK: entrada local, recebimento, observação, valores, reenvio e eco.\n";

    $pdo = database();
    $pdo->exec("CREATE TRIGGER fail_queue BEFORE INSERT ON sincronizacao BEGIN SELECT RAISE(FAIL, 'Falha simulada na fila'); END");
    mustFail(fn() => (new Services\EntradaManualService())->registrar($data));
    emptyEntry($pdo);
    echo "OK: falha ao enfileirar reverte movimentação, itens, unidades e saldo.\n";

    $pdo = database();
    $pdo->exec("CREATE TRIGGER fail_stock BEFORE INSERT ON estoques WHEN NEW.produto_id = 2 BEGIN SELECT RAISE(FAIL, 'Falha simulada no estoque'); END");
    mustFail(fn() => (new Services\EntradaManualService())->registrar($data));
    emptyEntry($pdo);
    echo "OK: falha em unidade de estoque reverte toda a entrada.\n";

    $pdo = database();
    $pdo->exec("CREATE TRIGGER fail_receipt BEFORE INSERT ON sincronizacao BEGIN SELECT RAISE(FAIL, 'Falha simulada no recibo'); END");
    mustFail(fn() => (new Services\EntradaManualService())->receber($payload));
    emptyEntry($pdo);
    echo "OK: falha no recibo reverte entrada recebida.\n";

    $pdo = database();
    $pdo->beginTransaction();
    (new Services\EntradaManualService())->receber($payload);
    $pdo->rollBack();
    emptyEntry($pdo);
    (new Services\EntradaManualService())->receber($payload);
    check(countRows($pdo, 'movimentacoes') === 1, 'Recibo revertido impediu nova tentativa.');
    echo "OK: rollback externo reverte entrada e recibo, permitindo nova tentativa.\n";

    $pdo = database();
    $service = new Services\EntradaManualService();
    foreach ([['tipo_movimentacao_id' => 2], ['itens' => [1 => -1]], ['itens' => [1 => 1.5]], ['itens' => [1 => 0]], ['valores' => [1 => -1, 2 => 2]], ['itens' => [999 => 1], 'valores' => [999 => 3]], ['total' => 999]] as $invalid) {
        mustFail(fn() => $service->registrar(array_replace($data, $invalid)));
        emptyEntry($pdo);
    }
    mustFail(fn() => $service->receber(array_replace($payload, ['evento_id' => ''])));
    $service->registrar(array_replace($data, ['observacao' => '', 'itens' => [1 => 1], 'valores' => [1 => 0]]));
    check($pdo->query('SELECT observacoes FROM movimentacoes')->fetchColumn() === 'Entrada manual.', 'Observação padrão incorreta.');
    echo "OK: validações, valor zero e observação padrão.\n";
} finally {
    foreach (glob(LOG_PATH . '/*') as $file) unlink($file);
    rmdir(LOG_PATH);
}
