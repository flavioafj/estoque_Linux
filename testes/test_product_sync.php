<?php
/** Teste isolado em SQLite, sem configuração ou banco de produção. */
$root = dirname(__DIR__);
define('LOG_PATH', sys_get_temp_dir() . '/product-sync-' . bin2hex(random_bytes(6)));
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
function database() {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));
    $pdo->exec('CREATE TABLE produtos (id INTEGER PRIMARY KEY AUTOINCREMENT, nome TEXT, codigo TEXT, categoria_id INTEGER, unidade_medida_id INTEGER, ativo INTEGER DEFAULT 1, estoque_atual NUMERIC DEFAULT 0, criado_por INTEGER, criado_em TEXT, atualizado_em TEXT);
    CREATE TABLE auditoria (id INTEGER PRIMARY KEY, tabela TEXT, registro_id INTEGER, acao TEXT, dados_anteriores TEXT, dados_novos TEXT, usuario_id INTEGER, ip_address TEXT, user_agent TEXT);
    CREATE TABLE sincronizacao (id INTEGER PRIMARY KEY, tabela TEXT, registro_id INTEGER, acao TEXT, dados TEXT, sincronizado INTEGER, tentativas INTEGER, erro_mensagem TEXT, criado_em TEXT, sincronizado_em TEXT);');
    $ref = new ReflectionClass(Models\Database::class);
    $db = $ref->newInstanceWithoutConstructor();
    $ref->getProperty('connection')->setValue($db, $pdo);
    $ref->getProperty('instance')->setValue(null, $db);
    Helpers\SyncQueueHelper::initialize($pdo);
    return $pdo;
}
try {
    $local = database();
    $model = new Models\Product();
    $id = $model->create(['nome' => 'Teste', 'codigo' => 'P1', 'categoria_id' => 1, 'unidade_medida_id' => 1]);
    $model->delete($id);
    $model->update($id, ['ativo' => 1]);
    $events = $local->query('SELECT * FROM sincronizacao ORDER BY id')->fetchAll();
    check(count($events) === 3, 'Cadastro/inativação/reativação não enfileirados.');
    check(json_decode($events[0]['dados'], true)['id'] === (int) $id, 'ID ausente.');
    check(json_decode($events[1]['dados'], true)['ativo'] === 0, 'Inativação ausente.');
    $remote = database();
    $service = new Services\ProductSyncService($remote);
    $pull = new Services\SyncPullService($remote);
    $pull->prepararRecibos();
    foreach ($events as $event) {
        $apply = fn($e, $d) => $service->receber($e['acao'], (int) $e['registro_id'], $d);
        check($pull->aplicar('test', $event, $apply), 'Evento não aplicado.');
        check(!$pull->aplicar('test', $event, $apply), 'Evento repetido reaplicado.');
    }
    check((int) $remote->query('SELECT ativo FROM produtos')->fetchColumn() === 1, 'Reativação não aplicada.');
    check((int) $remote->query('SELECT COUNT(*) FROM sincronizacao')->fetchColumn() === 0, 'Recebimento gerou eco.');
    $remote->beginTransaction();
    $service->receber('INSERT', (int) $id, json_decode($events[0]['dados'], true));
    fails(fn() => $service->receber('INSERT', (int) $id, array_replace(json_decode($events[0]['dados'], true), ['nome' => 'Outro'])));
    $service->receber('DELETE', (int) $id, []);
    check((int) $remote->query('SELECT ativo FROM produtos')->fetchColumn() === 0, 'DELETE não inativou.');
    fails(fn() => $service->receber('UPDATE', 999, ['ativo' => 0]));
    fails(fn() => $service->receber('UPDATE', (int) $id, ['campo_invalido' => 1]));
    $remote->rollBack();
    check((int) $remote->query('SELECT ativo FROM produtos')->fetchColumn() === 1, 'Rollback remoto falhou.');

    // Falha na fila precisa desfazer tanto o produto quanto a auditoria.
    $pdo = database();
    $pdo->exec("CREATE TRIGGER fail_queue BEFORE INSERT ON sincronizacao BEGIN SELECT RAISE(ABORT, 'fila indisponível'); END");
    fails(fn() => (new Models\Product())->create(['nome' => 'Rollback', 'categoria_id' => 1, 'unidade_medida_id' => 1]));
    check((int) $pdo->query('SELECT COUNT(*) FROM produtos')->fetchColumn() === 0, 'Cadastro parcial.');
    check((int) $pdo->query('SELECT COUNT(*) FROM auditoria')->fetchColumn() === 0, 'Auditoria parcial.');
    $pdo->exec("INSERT INTO produtos (id, nome, ativo) VALUES (1, 'Existente', 1)");
    $pdo->beginTransaction();
    fails(fn() => (new Models\Product())->delete(1));
    check($pdo->inTransaction(), 'Transação externa interrompida.');
    check((int) $pdo->query('SELECT ativo FROM produtos')->fetchColumn() === 1, 'Inativação parcial.');
    $pdo->rollBack();
    echo "OK: cadastro, inativação, reativação, PUSH repetido, PULL, IDs, colisões, ausência de eco e rollback.\n";
} finally {
    foreach (glob(LOG_PATH . '/*') as $file) unlink($file);
    rmdir(LOG_PATH);
}
