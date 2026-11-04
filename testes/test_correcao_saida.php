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
INSERT INTO tipos_movimentacao VALUES (1, 'ENTRADA'), (2, 'SAIDA'), (3, 'ENTRADA'), (5, 'SAIDA'), (7, 'SAIDA');
CREATE TABLE produtos (id INTEGER PRIMARY KEY, estoque_atual INTEGER DEFAULT 0);
INSERT INTO produtos (id) VALUES (1), (2), (3);
CREATE TABLE movimentacoes (id INTEGER PRIMARY KEY AUTOINCREMENT, tipo_movimentacao_id INTEGER, data_movimentacao TEXT, usuario_id INTEGER, fornecedor_id INTEGER, observacoes TEXT, status TEXT, inventario_id INTEGER, valor_total NUMERIC DEFAULT 0, documento_numero TEXT, corrigida INTEGER DEFAULT 0, movimentacao_original_id INTEGER);
CREATE TABLE movimentacao_itens (id INTEGER PRIMARY KEY AUTOINCREMENT, movimentacao_id INTEGER REFERENCES movimentacoes(id), produto_id INTEGER REFERENCES produtos(id), quantidade INTEGER, valor_unitario NUMERIC, valor_total NUMERIC);
CREATE TABLE estoques (id INTEGER PRIMARY KEY AUTOINCREMENT, produto_id INTEGER REFERENCES produtos(id), valor_unitario NUMERIC, data_entrada TEXT);
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

$pdo = database();
$pdo->exec("INSERT INTO movimentacoes (id,tipo_movimentacao_id,usuario_id) VALUES (10,5,7)");
$pdo->exec("INSERT INTO movimentacao_itens (movimentacao_id,produto_id,quantidade,valor_unitario) VALUES (10,1,4,2.50)");
$service = new Services\CorrecaoSaidaService();
$id = $service->registrar(10, [1 => -2], 7);
check((int)$pdo->query('SELECT corrigida FROM movimentacoes WHERE id=10')->fetchColumn() === 1, 'Original não marcada');
check((float)$pdo->query("SELECT valor_total FROM movimentacoes WHERE id=$id")->fetchColumn() === 5.0, 'Total incorreto');
check($pdo->query('SELECT acao FROM sincronizacao')->fetchColumn() === 'CORRECAO_SAIDA', 'Evento ausente');
mustFail(fn() => $service->registrar(10, [1 => -1], 7));
echo "OK: correção cria ajuste e evento e impede repetição.\n";
$pdo = database();
$pdo->exec("INSERT INTO movimentacoes (id,tipo_movimentacao_id,usuario_id) VALUES (10,5,7)");
$pdo->exec("INSERT INTO movimentacao_itens (movimentacao_id,produto_id,quantidade,valor_unitario) VALUES (10,1,4,2.50)");
$pdo->exec("CREATE TRIGGER falhar_fila BEFORE INSERT ON sincronizacao BEGIN SELECT RAISE(ABORT, 'fila indisponível'); END");
check(!(new Models\Movimentacao())->criarAjusteCompensatorio(10, [1 => -2], 7), 'Falha deveria retornar false');
check((int)$pdo->query('SELECT COUNT(*) FROM movimentacoes')->fetchColumn() === 1, 'Ajuste não revertido');
check((int)$pdo->query('SELECT corrigida FROM movimentacoes WHERE id=10')->fetchColumn() === 0, 'Marca não revertida');
check((int)$pdo->query('SELECT COUNT(*) FROM estoques')->fetchColumn() === 0, 'Unidades não revertidas');
echo "OK: falha na fila reverte ajuste, estoque e marca de correção.\n";
$pdo = database();
$pdo->exec("INSERT INTO movimentacoes (id,tipo_movimentacao_id,usuario_id) VALUES (99,5,7)");
$pdo->exec("INSERT INTO movimentacao_itens (movimentacao_id,produto_id,quantidade,valor_unitario) VALUES (99,1,4,2.50)");
$originalEvento = ['produtoId' => 1, 'quantidade' => 4, 'UserID' => 7];
$ref = $originalEvento + ['_movimento_destino_id' => 99];
$pdo->prepare("INSERT INTO sincronizacao (tabela,registro_id,acao,dados,sincronizado) VALUES ('movimentacoes',10,'SAIDA_DIRETA',?,1)")->execute([json_encode($ref)]);
$dados = ['evento_id' => str_repeat('a',32), 'original_id' => 10, 'original_evento' => $originalEvento, 'usuario_id' => 7, 'itens' => [1 => -2]];
$service = new Services\CorrecaoSaidaService();
$id = $service->receber($dados);
check($service->receber($dados) === $id, 'Reenvio duplicou ajuste');
check((int)$pdo->query('SELECT movimentacao_original_id FROM movimentacoes WHERE id=99')->fetchColumn() === $id, 'Referência remota incorreta');
check((int)$pdo->query('SELECT COUNT(*) FROM movimentacoes')->fetchColumn() === 2, 'Movimentação duplicada');
echo "OK: IDs diferentes no destino e recebimento idempotente.\n";
