<?php
/** Integração isolada: duas bases SQLite, rota HTTP real e scripts/sync2.php real. */
$root = dirname(__DIR__);
$dir = sys_get_temp_dir() . '/supplier-sync-' . bin2hex(random_bytes(6));
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
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/admin/suppliers.php') {
 define('SESSION_NAME', 'supplier_test'); define('SESSION_LIFETIME', 3600); define('CSRF_TOKEN_NAME', 'csrf_token'); define('APP_NAME', 'Teste');
 ini_set('session.save_path', __DIR__);
 session_start();
 $profile = (int) ($_SERVER['HTTP_X_TEST_PROFILE'] ?? 0);
 $_SESSION = $profile ? ['logged_in' => true, 'user_id' => 1, 'user_profile' => $profile, 'user_name' => 'Admin teste', 'login_time' => time(), 'csrf_token' => 'csrf-teste'] : [];
 $code = file_get_contents($root . '/public/admin/suppliers.php');
 $code = str_replace("require_once __DIR__ . '/../../config/config.php';", '', $code);
 $code = str_replace('__DIR__', var_export($root . '/public/admin', true), $code);
 eval('?>' . $code);
 return;
}
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
function installSuppliers(PDO $pdo): void {
    $columns = [];
    foreach (Services\SupplierData::LIMITS as $name => $limit) $columns[] = $name . ' TEXT' . (in_array($name, ['cnpj', 'cpf'], true) ? ' UNIQUE' : '');
    $pdo->exec('CREATE TABLE fornecedores (id INTEGER PRIMARY KEY AUTOINCREMENT, ' . implode(', ', $columns) . ", tipo TEXT NOT NULL DEFAULT 'PJ', observacoes TEXT, ativo INTEGER DEFAULT 1, criado_por INTEGER REFERENCES usuarios(id), criado_em TEXT DEFAULT CURRENT_TIMESTAMP, atualizado_em TEXT DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec('CREATE TABLE notas_teste (fornecedor_id INTEGER REFERENCES fornecedores(id))');
}
function model(PDO $pdo): Models\AdminFornecedor { bindDatabase($pdo); return new Models\AdminFornecedor(); }
function pageRequest(string $base, string $method = 'GET', array $body = [], int $profile = 1): array {
    $context = stream_context_create(['http' => ['method' => $method, 'header' => "Content-Type: application/x-www-form-urlencoded\r\nX-Test-Profile: $profile\r\n",
        'content' => $method === 'POST' ? http_build_query($body) : '', 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 5]]);
    $raw = file_get_contents($base . '/admin/suppliers.php', false, $context);
    preg_match('/HTTP\/\S+ (\d+)/', $http_response_header[0], $m);
    return [(int) $m[1], $raw];
}
$server = null;
try {
    $web = connect($dir . '/web.sqlite'); install($web); installSuppliers($web);
    $local = connect($dir . '/local.sqlite'); install($local); installSuppliers($local);
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
    $base = 'http://' . $address; $url = $base . '/api/sync.php';
    [$status] = pageRequest($base, 'GET', [], 0); check($status === 302, 'Visitante acessou administração.');
    [$status] = pageRequest($base, 'POST', ['action' => 'create', 'razao_social' => 'Negado'], 2); check($status === 302, 'Operador cadastrou fornecedor.');
    [$status, $html] = pageRequest($base);
    check($status === 200, 'Página administrativa não abriu.');
    foreach (array_keys(Services\SupplierData::LIMITS) as $field) check(str_contains($html, 'name="' . $field . '"'), 'Campo ausente: ' . $field);
    check(str_contains($html, 'name="tipo"') && str_contains($html, 'name="observacoes"'), 'Tipo/observações ausentes.');
    pageRequest($base, 'POST', ['action' => 'create', 'razao_social' => 'Sem CSRF']);
    check((int) scalar($web, 'SELECT COUNT(*) FROM fornecedores') === 0, 'Cadastro sem CSRF aceito.');
    $post = ['action' => 'create', 'csrf_token' => 'csrf-teste', 'razao_social' => 'Fornecedor mínimo'];
    [$status] = pageRequest($base, 'POST', $post); check($status === 302, 'Cadastro mínimo pela página falhou.');
    $minimal = $web->query('SELECT * FROM fornecedores WHERE id = 1')->fetch();
    check($minimal['tipo'] === 'PJ' && $minimal['email'] === null && $minimal['cnpj'] === null && $minimal['cpf'] === null, 'Opcionais/padrões incorretos.');
    check((int) $minimal['criado_por'] === 1 && $minimal['criado_em'] !== null, 'Autor/datas não gerados.');
    $full = ['razao_social' => 'Fornecedor completo <script>alert(1)</script>', 'nome_fantasia' => 'Fantasia', 'tipo' => 'PJ', 'cnpj' => '12.345.678/0001-90',
        'cpf' => '123.456.789-00', 'email' => 'contato@example.com', 'telefone' => '(11) 1234-5678', 'celular' => '(11) 91234-5678',
        'endereco' => 'Rua de teste, 10', 'cidade' => 'São Paulo', 'estado' => 'sp', 'cep' => '01234-567', 'observacoes' => "Linha 1\nLinha 2"];
    [$status] = pageRequest($base, 'POST', ['action' => 'create', 'csrf_token' => 'csrf-teste'] + $full);
    check($status === 302, 'Cadastro completo pela página falhou.');
    model($web)->addSupplier(['razao_social' => 'Pessoa física', 'tipo' => 'PF'], 1);
    check((int) scalar($web, 'SELECT COUNT(*) FROM sincronizacao WHERE sincronizado = 0') === 3, 'Cadastros não enfileirados.');
    $creation = $web->query('SELECT * FROM sincronizacao WHERE registro_id = 2')->fetch();
    [$status, $html] = pageRequest($base);
    check(!str_contains($html, '<script>alert(1)</script>') && str_contains($html, '&lt;script&gt;'), 'HTML do fornecedor não escapado.');
    $admin = model($web);
    fails(fn() => $admin->addSupplier(['razao_social' => ' '], 1), 'Razão social vazia aceita.');
    fails(fn() => $admin->addSupplier(['razao_social' => 'Nome', 'tipo' => 'XX'], 1), 'Tipo inválido aceito.');
    fails(fn() => $admin->addSupplier(['razao_social' => 'Nome', 'email' => 'inválido'], 1), 'E-mail inválido aceito.');
    fails(fn() => $admin->addSupplier(['razao_social' => 'Nome', 'estado' => 'S'], 1), 'UF inválida aceita.');
    foreach (Services\SupplierData::LIMITS as $field => $limit) {
        fails(fn() => $admin->addSupplier(array_replace(['razao_social' => 'Nome'], [$field => str_repeat('a', $limit + 1)]), 1), 'Limite não aplicado: ' . $field);
    }
    fails(fn() => $admin->addSupplier(['razao_social' => 'Outro', 'cnpj' => '12345678000190'], 1), 'CNPJ duplicado sem pontuação aceito.');
    fails(fn() => $admin->addSupplier(['razao_social' => 'Outro', 'cpf' => '12345678900'], 1), 'CPF duplicado sem pontuação aceito.');
    runSync();
    check((int) scalar($local, 'SELECT COUNT(*) FROM fornecedores') === 3, 'PULL não cadastrou fornecedores.');
    $received = $local->query('SELECT * FROM fornecedores WHERE id = 2')->fetch();
    foreach (Services\SupplierData::normalize($full) as $field => $value) check($received[$field] === $value, 'Campo não sincronizado: ' . $field);
    check((int) $received['criado_por'] === 1 && $received['criado_em'] === $web->query('SELECT criado_em FROM fornecedores WHERE id = 2')->fetchColumn(), 'Metadados não preservados.');
    check((int) scalar($web, 'SELECT COUNT(*) FROM sincronizacao WHERE sincronizado = 0') === 0, 'ACK não confirmou fila.');
    check((int) scalar($local, 'SELECT COUNT(*) FROM sincronizacao WHERE sincronizado = 0') === 0, 'PULL gerou eco.');
    $local->exec('INSERT INTO notas_teste VALUES (2)');
    [$status] = pageRequest($base, 'POST', ['action' => 'delete', 'csrf_token' => 'incorreto', 'id' => 2]);
    check((int) scalar($web, 'SELECT ativo FROM fornecedores WHERE id = 2') === 1, 'Exclusão sem CSRF aceita.');
    [$status] = pageRequest($base, 'POST', ['action' => 'delete', 'csrf_token' => 'csrf-teste', 'id' => 2]);
    check($status === 302, 'Exclusão pela página falhou.');
    runSync();
    check((int) scalar($local, 'SELECT ativo FROM fornecedores WHERE id = 2') === 0, 'PULL não desativou fornecedor.');
    check((int) scalar($local, 'SELECT COUNT(*) FROM notas_teste WHERE fornecedor_id = 2') === 1, 'Histórico não preservado.');
    check(count(model($local)->getAll()) === 2, 'Fornecedor inativo continua nas seleções de cadastro.');
    $pull = new Services\SyncPullService($local);
    $creation['id'] = 999;
    $pull->aplicar('reentrega', $creation, fn($e, $d) => (new Services\SupplierSyncService($local))->receber($e['acao'], (int) $e['registro_id'], $d));
    check((int) scalar($local, 'SELECT ativo FROM fornecedores WHERE id = 2') === 0, 'Cadastro atrasado reativou fornecedor.');
    runSync();
    $localAdmin = model($local);
    $id = $localAdmin->addSupplier(['razao_social' => 'Fornecedor local', 'tipo' => 'PF'], 1);
    $event = $local->query("SELECT * FROM sincronizacao WHERE registro_id = $id AND acao = 'INSERT'")->fetch();
    $localAdmin->deactivateSupplier($id, 1);
    runSync('sync.php');
    check((int) scalar($web, "SELECT ativo FROM fornecedores WHERE id = $id") === 0, 'PUSH não cadastrou/desativou fornecedor.');
    $count = (int) scalar($web, 'SELECT COUNT(*) FROM sincronizacao');
    $payload = ['tabela' => 'fornecedores', 'registro_id' => $id, 'acao' => 'INSERT', 'dados' => json_decode($event['dados'], true)];
    for ($i = 0; $i < 2; $i++) { [$status, $body] = httpRequest($url, $payload); check($status === 200 && $body['success'] === true, 'Reenvio PUSH falhou.'); }
    check((int) scalar($web, 'SELECT COUNT(*) FROM sincronizacao') === $count, 'Recibos duplicados.');
    check((int) scalar($web, "SELECT ativo FROM fornecedores WHERE id = $id") === 0, 'PUSH repetido reativou fornecedor.');
    check((int) scalar($web, 'SELECT COUNT(*) FROM sincronizacao WHERE sincronizado = 0') === 0, 'PUSH gerou eco.');
    [$status] = httpRequest($url, $payload, 'inválido'); check($status === 401, 'API sem autenticação aceitou evento.');
    $payload['dados']['razao_social'] = 'Alterado';
    [$status] = httpRequest($url, $payload); check($status === 500, 'UUID alterado aceito.');
    $payload['dados']['evento_id'] = bin2hex(random_bytes(16));
    [$status] = httpRequest($url, $payload); check($status === 500, 'Colisão de ID aceita.');
    $payload['dados']['campo_inesperado'] = 'teste';
    [$status] = httpRequest($url, $payload); check($status === 500, 'Campo inesperado aceito.');
    $isolated = connect(':memory:'); install($isolated); installSuppliers($isolated);
    $admin = model($isolated); $admin->addSupplier(['razao_social' => 'Existente'], 1);
    $isolated->exec("CREATE TRIGGER fail_queue BEFORE INSERT ON sincronizacao BEGIN SELECT RAISE(ABORT, 'fila indisponível'); END");
    fails(fn() => $admin->addSupplier(['razao_social' => 'Falha'], 1), 'Cadastro sem fila aceito.');
    fails(fn() => $admin->deactivateSupplier(1, 1), 'Exclusão sem fila aceita.');
    check((int) scalar($isolated, 'SELECT COUNT(*) FROM fornecedores') === 1 && (int) scalar($isolated, 'SELECT ativo FROM fornecedores WHERE id = 1') === 1, 'Rollback da conta falhou.');
    check((int) scalar($isolated, 'SELECT COUNT(*) FROM auditoria') === 1, 'Auditoria parcial.');
    $pull = new Services\SyncPullService($isolated); $pull->prepararRecibos();
    fails(fn() => $pull->aplicar('rollback', $event, fn($e, $d) => (new Services\SupplierSyncService($isolated))->receber($e['acao'], (int) $e['registro_id'], $d)), 'Recebimento sem recibo aceito.');
    check((int) scalar($isolated, "SELECT COUNT(*) FROM fornecedores WHERE id = $id") === 0 && (int) scalar($isolated, 'SELECT COUNT(*) FROM sync_recebimentos') === 0, 'Rollback remoto falhou.');
    echo "OK: $checks verificações — página real, admin/CSRF, campos obrigatórios/opcionais, inclusão/exclusão web ↔ local, API e sincronizador reais, ACK, reenvio, histórico, sem eco e rollback.\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); fclose($serverPipes[0]); proc_close($server); }
    foreach (glob($dir . '/*') as $file) unlink($file);
    rmdir($dir);
}
