<?php
/** Integração isolada: duas bases SQLite, rota HTTP real e scripts/sync2.php real. */
$root = dirname(__DIR__);
$dir = sys_get_temp_dir() . '/user-sync-' . bin2hex(random_bytes(6));
mkdir($dir);
define('LOG_PATH', $dir);
spl_autoload_register(function ($class) use ($root) {
    $file = $root . '/src/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) require_once $file;
});
$checks = 0;
function check($ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
function fails(callable $fn, string $message): void {
    try { $fn(); } catch (Throwable $e) { check(true, $message); return; }
    throw new RuntimeException($message);
}
function connect(string $file): PDO {
    $pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 3000');
    $pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));
    $pdo->sqliteCreateFunction('JSON_UNQUOTE', fn($value) => $value);
    return $pdo;
}
function install(PDO $pdo): void {
    $pdo->exec(<<<'SQL'
CREATE TABLE perfis (id INTEGER PRIMARY KEY, nome TEXT);
INSERT INTO perfis VALUES (1, 'Administrador'), (2, 'Operador'), (3, 'Inventariador');
CREATE TABLE usuarios (id INTEGER PRIMARY KEY AUTOINCREMENT, nome_completo TEXT NOT NULL, usuario TEXT NOT NULL UNIQUE COLLATE NOCASE,
 email TEXT UNIQUE COLLATE NOCASE, senha TEXT NOT NULL, perfil_id INTEGER NOT NULL REFERENCES perfis(id), ativo INTEGER DEFAULT 1,
 ultimo_acesso TEXT, token_recuperacao TEXT, token_expiracao TEXT, criado_em TEXT DEFAULT CURRENT_TIMESTAMP, atualizado_em TEXT DEFAULT CURRENT_TIMESTAMP);
INSERT INTO usuarios (id, nome_completo, usuario, email, senha, perfil_id) VALUES (1, 'Administrador', 'admin', NULL, 'hash-admin-preservado', 1);
CREATE TABLE sessoes (id TEXT PRIMARY KEY, usuario_id INTEGER REFERENCES usuarios(id));
CREATE TABLE historico_teste (usuario_id INTEGER REFERENCES usuarios(id));
CREATE TABLE auditoria (id INTEGER PRIMARY KEY, tabela TEXT, registro_id INTEGER, acao TEXT, dados_anteriores TEXT, dados_novos TEXT, usuario_id INTEGER REFERENCES usuarios(id), ip_address TEXT, user_agent TEXT);
CREATE TABLE sincronizacao (id INTEGER PRIMARY KEY AUTOINCREMENT, tabela TEXT, registro_id INTEGER, acao TEXT, dados TEXT, sincronizado INTEGER DEFAULT 0,
 tentativas INTEGER DEFAULT 0, erro_mensagem TEXT, criado_em TEXT DEFAULT CURRENT_TIMESTAMP, sincronizado_em TEXT);
SQL);
}
function bindDatabase(PDO $pdo): Models\AdminUser {
    $ref = new ReflectionClass(Models\Database::class);
    $db = $ref->newInstanceWithoutConstructor();
    $ref->getProperty('connection')->setValue($db, $pdo);
    $ref->getProperty('instance')->setValue(null, $db);
    Helpers\SyncQueueHelper::initialize($pdo);
    return new Models\AdminUser();
}
function scalar(PDO $pdo, string $sql) { return $pdo->query($sql)->fetchColumn(); }
function httpRequest(string $url, array $payload, string $token = 'token-teste'): array {
    $context = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\nX-Api-Token: Bearer $token\r\n",
        'content' => json_encode($payload), 'ignore_errors' => true, 'timeout' => 5]]);
    $raw = file_get_contents($url, false, $context);
    preg_match('/HTTP\/\S+ (\d+)/', $http_response_header[0], $m);
    return [(int) $m[1], json_decode($raw, true)];
}
function runSync(string $entry = 'sync2.php'): string {
    global $dir, $url;
    $process = proc_open([PHP_BINARY, '-n', '-d', 'extension=pdo', '-d', 'extension=pdo_sqlite', '-d', 'extension=curl', '-d', 'extension=mbstring',
        $dir . '/runner.php', $url, $entry], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Falha ao iniciar sincronizador.');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $status = proc_close($process);
    check($status === 0, 'Sincronizador falhou: ' . $out . $err);
    return $out;
}
$bootstrap = <<<'BOOTSTRAP'
<?php
$root = __ROOT__;
define('LOG_PATH', __DIR__);
define('SYNC_TOKEN', 'token-teste');
spl_autoload_register(function ($class) use ($root) {
 $file = $root . '/src/' . str_replace('\\', '/', $class) . '.php';
 if (is_file($file)) require_once $file;
});
$pdo = new PDO('sqlite:' . __DIR__ . '/' . $databaseFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 3000');
$pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));
$pdo->sqliteCreateFunction('JSON_UNQUOTE', fn($value) => $value);
$ref = new ReflectionClass(Models\Database::class);
$db = $ref->newInstanceWithoutConstructor();
$ref->getProperty('connection')->setValue($db, $pdo);
$ref->getProperty('instance')->setValue(null, $db);
Helpers\SyncQueueHelper::initialize($pdo);
BOOTSTRAP;
file_put_contents($dir . '/bootstrap.php', str_replace('__ROOT__', var_export($root, true), $bootstrap));
file_put_contents($dir . '/router.php', <<<'ROUTER'
<?php
$databaseFile = 'web.sqlite';
require __DIR__ . '/bootstrap.php';
$code = file_get_contents($root . '/public/api/sync.php');
$code = str_replace(["require_once __DIR__ . '/../../config/config.php';", "require_once __DIR__ . '/../../src/Controllers/SyncController.php';"], '', $code);
eval('?>' . $code);
ROUTER);
file_put_contents($dir . '/runner.php', <<<'RUNNER'
<?php
$databaseFile = 'local.sqlite';
require __DIR__ . '/bootstrap.php';
define('SYNC_URL', $argv[1]);
// Executa o ponto de entrada legado também, sem carregar configuração de produção.
$entry = $argv[2] ?? 'sync2.php';
$code = file_get_contents($root . '/scripts/' . $entry);
if ($entry === 'sync.php') {
 if (!str_contains($code, "require __DIR__ . '/sync2.php';")) throw new RuntimeException('Entrada legada não usa sync2.php.');
 $code = file_get_contents($root . '/scripts/sync2.php');
}
$code = str_replace(["require_once __DIR__ . '/../config/config.php';", "require_once __DIR__ . '/../src/Models/Database.php';"], '', $code);
eval('?>' . $code);
RUNNER);
$server = null;
try {
    $web = connect($dir . '/web.sqlite'); install($web);
    $local = connect($dir . '/local.sqlite'); install($local);
    $admin = bindDatabase($web);
    $admin->addOperator(['nome_completo' => 'Operador web A', 'usuario' => 'web_a'], 1);
    $admin->addOperator(['nome_completo' => 'Operador web B', 'usuario' => 'web_b', 'email' => ''], 1);
    $admin->addOperator(['nome_completo' => 'Operador web C', 'usuario' => 'web_c', 'email' => 'c@example.com'], 1);
    check((int) scalar($web, 'SELECT COUNT(*) FROM sincronizacao WHERE sincronizado = 0') === 3, 'Cadastros não foram enfileirados.');
    $creation = $web->query('SELECT * FROM sincronizacao WHERE registro_id = 2')->fetch();
    foreach ($web->query('SELECT dados FROM sincronizacao')->fetchAll(PDO::FETCH_COLUMN) as $payload) {
        check(!str_contains($payload, 'senha') && !str_contains($payload, 'token_'), 'Fila contém credenciais.');
    }
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) throw new RuntimeException('Não foi possível reservar porta local.');
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $server = proc_open([PHP_BINARY, '-n', '-d', 'extension=pdo', '-d', 'extension=pdo_sqlite', '-d', 'extension=mbstring', '-S', $address, $dir . '/router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $dir . '/server.log', 'a'], 2 => ['file', $dir . '/server.log', 'a']], $serverPipes);
    if (!is_resource($server)) throw new RuntimeException('Servidor de teste não iniciou.');
    $ready = false;
    for ($i = 0; $i < 50; $i++) {
        $s = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($s) { fclose($s); $ready = true; break; }
        usleep(20000);
    }
    check($ready, 'Servidor não respondeu.');
    $url = 'http://' . $address . '/api/sync.php';
    runSync();
    check((int) scalar($local, 'SELECT COUNT(*) FROM usuarios WHERE ativo = 1') === 4, 'PULL não cadastrou usuários.');
    check((int) scalar($local, 'SELECT COUNT(*) FROM usuarios WHERE perfil_id = 2 AND email IS NULL') === 2, 'E-mails opcionais não preservados.');
    check(scalar($local, 'SELECT email FROM usuarios WHERE id = 4') === 'c@example.com', 'E-mail informado não preservado.');
    check((int) scalar($web, 'SELECT COUNT(*) FROM sincronizacao WHERE sincronizado = 0') === 0, 'ACK não confirmou cadastros na origem.');
    check((int) scalar($local, 'SELECT COUNT(*) FROM sincronizacao WHERE sincronizado = 0') === 0, 'PULL gerou eco.');
    $user = bindDatabase($local);
    check($user->authenticateOnlyUser('web_a', 'senha')['id'] === 2, 'Operador recebido não autentica sem senha.');
    runSync();
    check((int) scalar($local, 'SELECT COUNT(*) FROM usuarios') === 4, 'Reexecução duplicou usuários.');
    $webAdmin = bindDatabase($web);
    $webAdmin->deactivateUser(2, 1);
    $local->exec("INSERT INTO sessoes VALUES ('aberta', 2); INSERT INTO historico_teste VALUES (2)");
    runSync();
    check((int) scalar($local, 'SELECT ativo FROM usuarios WHERE id = 2') === 0, 'PULL não desativou usuário.');
    check((int) scalar($local, 'SELECT COUNT(*) FROM sessoes WHERE usuario_id = 2') === 0, 'Sessões não removidas.');
    check((int) scalar($local, 'SELECT COUNT(*) FROM historico_teste WHERE usuario_id = 2') === 1, 'Histórico perdido.');
    check(bindDatabase($local)->authenticateOnlyUser('web_a', 'senha') === false, 'Usuário excluído ainda autentica.');

    // Reentrega do cadastro após a exclusão não reativa a conta, mesmo com outro ID de fila.
    $receiver = new Services\SyncPullService($local);
    $creation['id'] = 999;
    $receiver->aplicar('reentrega-teste', $creation, fn($e, $d) => (new Services\UserSyncService($local))->receber($e['acao'], (int) $e['registro_id'], $d));
    check((int) scalar($local, 'SELECT ativo FROM usuarios WHERE id = 2') === 0, 'Cadastro atrasado reativou conta.');

    // Criação e exclusão locais percorrem o PUSH real na mesma execução.
    $localAdmin = bindDatabase($local);
    $localAdmin->addOperator(['nome_completo' => 'Operador local', 'usuario' => 'local_a'], 1);
    $id = (int) scalar($local, "SELECT id FROM usuarios WHERE usuario = 'local_a'");
    $localCreation = $local->query("SELECT * FROM sincronizacao WHERE registro_id = $id AND acao = 'INSERT'")->fetch();
    $localAdmin->deactivateUser($id, 1);
    runSync('sync.php');
    check((int) scalar($web, "SELECT ativo FROM usuarios WHERE id = $id") === 0, 'PUSH não cadastrou/desativou usuário.');
    check((int) scalar($local, 'SELECT COUNT(*) FROM sincronizacao WHERE sincronizado = 0') === 0, 'PUSH não confirmou fila.');
    check((int) scalar($web, 'SELECT COUNT(*) FROM sincronizacao WHERE sincronizado = 0') === 0, 'PUSH gerou eco.');
    $payload = ['tabela' => 'usuarios', 'registro_id' => $id, 'acao' => 'INSERT', 'dados' => json_decode($localCreation['dados'], true)];
    $receipts = (int) scalar($web, 'SELECT COUNT(*) FROM sincronizacao');
    for ($i = 0; $i < 2; $i++) {
        [$status, $body] = httpRequest($url, $payload);
        check($status === 200 && $body['success'] === true, 'Reenvio PUSH falhou.');
    }
    check((int) scalar($web, "SELECT ativo FROM usuarios WHERE id = $id") === 0, 'PUSH repetido reativou conta.');
    check((int) scalar($web, 'SELECT COUNT(*) FROM sincronizacao') === $receipts, 'Reenvio duplicou recibo.');
    [$status] = httpRequest($url, $payload, 'invalido');
    check($status === 401, 'PUSH sem autenticação aceito.');
    $payload['dados']['nome_completo'] = 'Conteúdo alterado';
    [$status] = httpRequest($url, $payload);
    check($status === 500, 'UUID reutilizado com conteúdo diferente aceito.');
    $payload['dados']['evento_id'] = bin2hex(random_bytes(16));
    $payload['dados']['perfil_id'] = 1;
    [$status] = httpRequest($url, $payload);
    check($status === 500, 'Cadastro de administrador aceito via sincronização de operadores.');
    $payload['dados'] = json_decode($localCreation['dados'], true);
    $payload['dados']['senha'] = 'indevida';
    [$status] = httpRequest($url, $payload);
    check($status === 500, 'Campo senha aceito.');
    check(scalar($web, 'SELECT senha FROM usuarios WHERE id = 1') === 'hash-admin-preservado', 'Credencial de administrador alterada.');

    $service = new Services\UserSyncService($web);
    fails(fn() => $service->receber('INSERT', $id, json_decode($localCreation['dados'], true)), 'Recebimento sem transação aceito.');
    $web->beginTransaction();
    $conflict = json_decode($localCreation['dados'], true);
    $conflict['evento_id'] = bin2hex(random_bytes(16)); $conflict['usuario'] = 'outro';
    fails(fn() => $service->receber('INSERT', $id, $conflict), 'Colisão de ID aceita.');
    $conflict['id'] = 999;
    $conflict['usuario'] = 'web_b';
    fails(fn() => $service->receber('INSERT', 999, $conflict), 'Mesmo usuário com outro ID aceito.');
    $deletion = ['evento_id' => bin2hex(random_bytes(16)), 'id' => 1, 'usuario' => 'admin', 'perfil_id' => 1, 'ativo' => 0];
    fails(fn() => $service->receber('UPDATE', 1, $deletion), 'Último administrador desativado.');
    $deletion['id'] = 999; $deletion['usuario'] = 'ausente'; $deletion['perfil_id'] = 2;
    fails(fn() => $service->receber('UPDATE', 999, $deletion), 'Exclusão sem cadastro confirmada.');
    $web->rollBack();

    // Falha da fila deve desfazer conta, auditoria, desativação e remoção de sessões.
    $isolated = connect(':memory:'); install($isolated);
    $model = bindDatabase($isolated);
    $model->addOperator(['nome_completo' => 'Existente', 'usuario' => 'existente'], 1);
    $isolated->exec("INSERT INTO sessoes VALUES ('sessao', 2); CREATE TRIGGER fail_queue BEFORE INSERT ON sincronizacao BEGIN SELECT RAISE(ABORT, 'fila indisponível'); END");
    fails(fn() => $model->addOperator(['nome_completo' => 'Falha', 'usuario' => 'falha'], 1), 'Cadastro aceito sem fila.');
    check((int) scalar($isolated, 'SELECT COUNT(*) FROM usuarios') === 2, 'Cadastro parcial após falha.');
    fails(fn() => $model->deactivateUser(2, 1), 'Exclusão aceita sem fila.');
    check((int) scalar($isolated, 'SELECT ativo FROM usuarios WHERE id = 2') === 1, 'Exclusão parcial após falha.');
    check((int) scalar($isolated, 'SELECT COUNT(*) FROM sessoes') === 1, 'Rollback não restaurou sessão.');
    check((int) scalar($isolated, 'SELECT COUNT(*) FROM auditoria') === 1, 'Auditoria parcial após falha.');
    // Recibo de PULL também não pode sobreviver à falha no recibo do serviço.
    $pull = new Services\SyncPullService($isolated); $pull->prepararRecibos();
    $event = $localCreation; $event['registro_id'] = 50;
    $data = json_decode($event['dados'], true); $data['id'] = 50; $data['usuario'] = 'rollback_remoto';
    $event['dados'] = json_encode($data);
    fails(fn() => $pull->aplicar('rollback', $event, fn($e, $d) => (new Services\UserSyncService($isolated))->receber($e['acao'], (int) $e['registro_id'], $d)), 'Recebimento aceito sem recibo.');
    check((int) scalar($isolated, 'SELECT COUNT(*) FROM usuarios WHERE id = 50') === 0, 'Conta remota parcial.');
    check((int) scalar($isolated, 'SELECT COUNT(*) FROM sync_recebimentos') === 0, 'Recibo de PULL parcial.');
    echo "OK: $checks verificações — cadastro/exclusão web ↔ local, HTTP/API e sincronizador reais, ACK, reenvio, login, e-mail opcional, colisões, sem eco e rollback.\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); fclose($serverPipes[0]); proc_close($server); }
    foreach (glob($dir . '/*') as $file) unlink($file);
    rmdir($dir);
}
