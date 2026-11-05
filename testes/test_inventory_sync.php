<?php
/** Inventário real em SQLite isolado: sem .env, banco de produção ou e-mails. */
$root = dirname(__DIR__);
define('LOG_PATH', sys_get_temp_dir() . '/inventory-sync-' . bin2hex(random_bytes(6)));
mkdir(LOG_PATH);
spl_autoload_register(function ($class) use ($root) {
    $file = $root . '/src/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) require_once $file;
});
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
function fails(callable $fn) {
    try { $fn(); } catch (Throwable $e) { return; }
    throw new RuntimeException('Falha esperada.');
}
function database(): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));
    $pdo->sqliteCreateFunction('JSON_UNQUOTE', fn($value) => $value);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec(<<<'SQL'
CREATE TABLE produtos (id INTEGER PRIMARY KEY, nome TEXT, estoque_atual NUMERIC DEFAULT 0);
INSERT INTO produtos VALUES (1, 'Produto A', 3), (2, 'Produto B', 2);
CREATE TABLE tipos_movimentacao (id INTEGER PRIMARY KEY, tipo TEXT);
INSERT INTO tipos_movimentacao VALUES (3, 'ENTRADA'), (7, 'SAIDA');
CREATE TABLE inventarios (id INTEGER PRIMARY KEY AUTOINCREMENT, usuario_id INTEGER, data_inicio TEXT, data_conclusao TEXT, status TEXT);
CREATE TABLE movimentacoes (id INTEGER PRIMARY KEY AUTOINCREMENT, tipo_movimentacao_id INTEGER REFERENCES tipos_movimentacao(id), data_movimentacao TEXT, usuario_id INTEGER, observacoes TEXT, status TEXT, inventario_id INTEGER REFERENCES inventarios(id), valor_total NUMERIC);
CREATE TABLE movimentacao_itens (id INTEGER PRIMARY KEY, movimentacao_id INTEGER REFERENCES movimentacoes(id), produto_id INTEGER REFERENCES produtos(id), quantidade NUMERIC, valor_unitario NUMERIC, valor_total NUMERIC);
CREATE TABLE estoques (id INTEGER PRIMARY KEY, produto_id INTEGER REFERENCES produtos(id), valor_unitario NUMERIC, data_entrada TEXT DEFAULT CURRENT_TIMESTAMP);
INSERT INTO estoques (produto_id, valor_unitario) VALUES (1, 2.50), (1, 2.50), (1, 2.50), (2, 4.25), (2, 4.25);
CREATE TABLE sincronizacao (id INTEGER PRIMARY KEY, tabela TEXT, registro_id INTEGER, acao TEXT, dados TEXT, sincronizado INTEGER, tentativas INTEGER, erro_mensagem TEXT, criado_em TEXT, sincronizado_em TEXT);
CREATE TABLE auditoria (id INTEGER PRIMARY KEY, tabela TEXT, registro_id INTEGER, acao TEXT, dados_anteriores TEXT, dados_novos TEXT, usuario_id INTEGER, ip_address TEXT, user_agent TEXT);
CREATE VIEW vw_produtos_estoque_critico AS SELECT id FROM produtos WHERE 0;
SQL);
    $ref = new ReflectionClass(Models\Database::class);
    $db = $ref->newInstanceWithoutConstructor();
    $ref->getProperty('connection')->setValue($db, $pdo);
    $ref->getProperty('instance')->setValue(null, $db);
    Helpers\SyncQueueHelper::initialize($pdo);
    return $pdo;
}
function rows(PDO $pdo, string $table): int { return (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn(); }
function balance(PDO $pdo, int $id): int { return (int) $pdo->query('SELECT estoque_atual FROM produtos WHERE id = ' . $id)->fetchColumn(); }
function unchanged(PDO $pdo): void {
    foreach (['inventarios', 'movimentacoes', 'movimentacao_itens', 'sincronizacao', 'auditoria'] as $table) check(rows($pdo, $table) === 0, 'Rollback incompleto: ' . $table);
    check(balance($pdo, 1) === 3 && balance($pdo, 2) === 2 && rows($pdo, 'estoques') === 5, 'Estoque não revertido.');
}
try {
    $local = database();
    $result = (new Services\InventoryService())->registrar([1 => '0', 2 => '5'], 7);
    check(count($result['ajustes']) === 2, 'Resumo incompleto.');
    check(balance($local, 1) === 0 && balance($local, 2) === 5 && rows($local, 'estoques') === 5, 'Saldo/unidades locais incorretos.');
    check(rows($local, 'inventarios') === 1 && rows($local, 'movimentacoes') === 2, 'Histórico local incompleto.');
    check(rows($local, 'sincronizacao') === 1, 'Inventário precisa de um único evento.');
    $event = $local->query('SELECT * FROM sincronizacao')->fetch();
    $payload = json_decode($event['dados'], true);
    check($event['acao'] === 'AJUSTE_INVENTARIO' && (int) $event['sincronizado'] === 0, 'Evento não está pendente.');
    check($payload['itens'][1]['diferenca'] === -3 && $payload['itens'][2]['diferenca'] === 3, 'Diferenças incorretas.');
    check($payload['itens'][2]['valor_unitario'] == 4.25, 'Valor decimal perdido.');
    (new Services\InventoryService())->receber($payload);
    check(rows($local, 'inventarios') === 1 && rows($local, 'sincronizacao') === 1, 'Eco reaplicou inventário.');

    $remote = database();
    // IDs locais do histórico são independentes. Uma venda posterior deve ser preservada.
    $remote->exec("INSERT INTO inventarios (id) VALUES (100); UPDATE produtos SET estoque_atual = 1 WHERE id = 2; DELETE FROM estoques WHERE id = 5");
    $receiver = new Services\InventoryService();
    $remote->beginTransaction();
    $received = $receiver->receber($payload);
    check($remote->inTransaction(), 'Recebimento confirmou transação do chamador.');
    check($received['inventario_id'] === 101, 'ID do inventário da origem foi reutilizado.');
    $remote->commit();
    $receiver->receber($payload);
    check(balance($remote, 1) === 0 && balance($remote, 2) === 4, 'Recebimento sobrescreveu operação posterior ou duplicou ajuste.');
    check(rows($remote, 'inventarios') === 2 && rows($remote, 'movimentacoes') === 2, 'Histórico remoto duplicado.');
    check(rows($remote, 'sincronizacao') === 1 && (int) $remote->query('SELECT sincronizado FROM sincronizacao')->fetchColumn() === 1, 'Recebimento gerou novo envio.');
    fails(fn() => $receiver->receber(array_replace($payload, ['user_id' => 8])));
    echo "OK: inventário com entrada/saída, contagem zero, valores decimais, diferenças, IDs independentes, reenvio e eco.\n";

    $pdo = database();
    $pull = new Services\SyncPullService($pdo);
    $pull->prepararRecibos();
    check(count($pull->listarPendentes()) === 0, 'Fila inicial incorreta.');
    $apply = fn($e, $d) => (new Services\InventoryService())->receber($d);
    check($pull->aplicar('origem', $event, $apply), 'PULL não aplicou inventário.');
    check(!$pull->aplicar('origem', $event, $apply), 'PULL repetiu inventário.');
    check(count($pull->pendentesConfirmacao('origem')) === 1, 'Recibo de PULL ausente.');
    check(balance($pdo, 1) === 0 && balance($pdo, 2) === 5, 'Saldo do PULL incorreto.');
    echo "OK: PULL aplica inventário, evita repetição e deixa recibo para ACK.\n";

    foreach (["CREATE TRIGGER fail_queue BEFORE INSERT ON sincronizacao BEGIN SELECT RAISE(ABORT, 'fila indisponível'); END",
        "CREATE TRIGGER fail_stock BEFORE INSERT ON estoques WHEN NEW.produto_id = 2 BEGIN SELECT RAISE(ABORT, 'estoque indisponível'); END"] as $trigger) {
        $pdo = database();
        $pdo->exec($trigger);
        fails(fn() => (new Services\InventoryService())->registrar([1 => 0, 2 => 5], 7));
        unchanged($pdo);
        fails(fn() => (new Services\InventoryService())->receber($payload));
        unchanged($pdo);
    }
    $pdo = database();
    $pdo->beginTransaction();
    (new Services\InventoryService())->receber($payload);
    $pdo->rollBack();
    unchanged($pdo);
    $pdo->exec("CREATE TRIGGER fail_queue BEFORE INSERT ON sincronizacao BEGIN SELECT RAISE(ABORT, 'fila indisponível'); END");
    $pdo->beginTransaction();
    fails(fn() => (new Services\InventoryService())->registrar([1 => 0, 2 => 5], 7));
    check($pdo->inTransaction(), 'Falha interrompeu transação externa.');
    unchanged($pdo);
    $pdo->rollBack();
    echo "OK: falha na fila/unidades reverte inventário, movimentos, saldo, auditoria e recibos; transação externa preservada.\n";

    foreach ([[1 => -1], [1 => 'abc'], [1 => 1.5], [999 => 1], [1 => INF]] as $invalid) {
        $pdo = database();
        fails(fn() => (new Services\InventoryService())->registrar($invalid, 7));
        unchanged($pdo);
    }
    $pdo = database();
    (new Services\InventoryService())->registrar([1 => 3, 2 => ''], 7);
    check(rows($pdo, 'movimentacoes') === 0 && rows($pdo, 'sincronizacao') === 0, 'Contagem sem diferença gerou ajuste.');
    $pdo = database();
    $bad = $payload;
    $bad['itens'][1]['diferenca'] = -4;
    fails(fn() => (new Services\InventoryService())->receber($bad));
    unchanged($pdo);
    echo "OK: validações, saldo insuficiente e contagem sem diferença.\n";
    $origin = database();
    $service = new Services\InventoryService();
    $created = $service->registrar([1 => 0, 2 => 5], 7);
    $originalEvent = $origin->query('SELECT * FROM sincronizacao')->fetch();
    $originalPayload = json_decode($originalEvent['dados'], true);
    $service->desfazer($created['inventario_id'], 7);
    $undoEvent = $origin->query('SELECT * FROM sincronizacao ORDER BY id DESC LIMIT 1')->fetch();
    $undoPayload = json_decode($undoEvent['dados'], true);
    check($undoPayload['desfaz_evento_id'] === $originalPayload['evento_id'], 'Referência do inventário original ausente.');
    check(balance($origin, 1) === 3 && balance($origin, 2) === 2 && rows($origin, 'estoques') === 5, 'Desfazimento local não reverteu estoque.');
    check($origin->query('SELECT status FROM inventarios')->fetchColumn() === 'CANCELADO', 'Inventário local não cancelado.');
    check(rows($origin, 'movimentacoes') === 4 && rows($origin, 'sincronizacao') === 2, 'Desfazimento sem histórico ou evento.');
    fails(fn() => $service->desfazer($created['inventario_id'], 7));
    $service->receber($undoPayload);
    check(rows($origin, 'movimentacoes') === 4, 'Eco duplicou desfazimento.');

    $dest = database();
    $dest->exec('INSERT INTO inventarios (id) VALUES (100)');
    $receiver = new Services\InventoryService();
    fails(fn() => $receiver->receber($undoPayload));
    check(rows($dest, 'movimentacoes') === 0, 'Desfazimento fora de ordem alterou estoque.');
    $originalReceived = $receiver->receber($originalPayload);
    $destId = $originalReceived['inventario_id'];
    // Venda posterior e atraso superior a 24 horas no transporte.
    $dest->exec("UPDATE inventarios SET data_conclusao = '2000-01-01' WHERE id = $destId; UPDATE produtos SET estoque_atual = estoque_atual - 1 WHERE id = 2; DELETE FROM estoques WHERE id = (SELECT MAX(id) FROM estoques WHERE produto_id = 2)");
    $receiver->receber($undoPayload);
    $receiver->receber($undoPayload);
    check(balance($dest, 1) === 3 && balance($dest, 2) === 1, 'Desfazimento remoto perdeu venda posterior ou duplicou ajuste.');
    check($dest->query('SELECT status FROM inventarios WHERE id = ' . $destId)->fetchColumn() === 'CANCELADO', 'Cancelou o inventário errado no destino.');
    check(rows($dest, 'inventarios') === 2 && rows($dest, 'movimentacoes') === 4, 'Desfazimento criou outro inventário ou repetiu movimentos.');
    check(rows($dest, 'sincronizacao') === 2 && (int) $dest->query('SELECT MIN(sincronizado) FROM sincronizacao')->fetchColumn() === 1, 'Desfazimento recebido gerou novo envio.');
    echo "OK: desfazimento local/remoto, IDs distintos, venda posterior, referência original, reenvio, eco e transporte atrasado.\n";

    $pdo = database();
    $pull = new Services\SyncPullService($pdo);
    $pull->prepararRecibos();
    $apply = fn($e, $d) => (new Services\InventoryService())->receber($d);
    $pull->aplicar('origem', $originalEvent, $apply);
    $pull->aplicar('origem', $undoEvent, $apply);
    check(!$pull->aplicar('origem', $undoEvent, $apply), 'PULL repetiu desfazimento.');
    check(balance($pdo, 1) === 3 && balance($pdo, 2) === 2 && count($pull->pendentesConfirmacao('origem')) === 2, 'PULL do desfazimento incorreto.');

    foreach ([false, true] as $received) {
        $pdo = database();
        $service = new Services\InventoryService();
        $created = $service->registrar([1 => 0, 2 => 5], 7);
        $pdo->exec("CREATE TRIGGER fail_undo_queue BEFORE INSERT ON sincronizacao BEGIN SELECT RAISE(ABORT, 'fila indisponível'); END");
        if ($received) {
            // Usa a referência do evento criado nesta base.
            $ref = json_decode($pdo->query('SELECT dados FROM sincronizacao')->fetchColumn(), true);
            $testUndo = array_replace($undoPayload, ['desfaz_evento_id' => $ref['evento_id']]);
            fails(fn() => $service->receber($testUndo));
        } else {
            fails(fn() => $service->desfazer($created['inventario_id'], 7));
        }
        check(balance($pdo, 1) === 0 && balance($pdo, 2) === 5 && rows($pdo, 'estoques') === 5, 'Falha na fila deixou reversão parcial.');
        check($pdo->query('SELECT status FROM inventarios')->fetchColumn() === 'CONCLUIDO', 'Falha na fila deixou inventário cancelado.');
        check(rows($pdo, 'movimentacoes') === 2 && rows($pdo, 'sincronizacao') === 1, 'Falha na fila deixou movimento/recibo de desfazimento.');
    }
    $pdo = database();
    $service = new Services\InventoryService();
    $created = $service->registrar([1 => 0, 2 => 5], 7);
    $pdo->exec("UPDATE inventarios SET data_conclusao = '2000-01-01'");
    fails(fn() => $service->desfazer($created['inventario_id'], 7));
    fails(fn() => $service->desfazer(999, 7));
    $pdo->exec("UPDATE inventarios SET data_conclusao = CURRENT_TIMESTAMP; UPDATE produtos SET estoque_atual = 2 WHERE id = 2");
    fails(fn() => $service->desfazer($created['inventario_id'], 7));
    check(balance($pdo, 1) === 0 && rows($pdo, 'movimentacoes') === 2, 'Saldo insuficiente deixou reversão parcial.');
    $pdo = database();
    $service = new Services\InventoryService();
    $created = $service->registrar([1 => 0, 2 => 5], 7);
    $pdo->beginTransaction();
    $service->desfazer($created['inventario_id'], 7);
    check($pdo->inTransaction(), 'Desfazimento confirmou transação externa.');
    $pdo->rollBack();
    check(balance($pdo, 1) === 0 && balance($pdo, 2) === 5, 'Rollback externo deixou desfazimento aplicado.');
    check($pdo->query('SELECT status FROM inventarios')->fetchColumn() === 'CONCLUIDO', 'Rollback externo deixou cancelamento aplicado.');
    $pdo->exec('DELETE FROM sincronizacao');
    fails(fn() => $service->desfazer($created['inventario_id'], 7));
    check(rows($pdo, 'movimentacoes') === 2 && balance($pdo, 1) === 0, 'Inventário sem referência gerou reversão parcial.');
    echo "OK: PULL do desfazimento, rollback local/remoto, limite de 24h, inventário inexistente e saldo insuficiente.\n";
} finally {
    foreach (glob(LOG_PATH . '/*') as $file) unlink($file);
    rmdir(LOG_PATH);
}
