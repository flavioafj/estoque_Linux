<?php
/** Integração isolada: duas bases SQLite, rota HTTP real e scripts/sync2.php real. */
$root = dirname(__DIR__);
$dir = sys_get_temp_dir() . '/nfe-supplier-sync-' . bin2hex(random_bytes(6));
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
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (in_array($path, ['/admin/suppliers.php', '/movimentacao/processa_cadastro.php', '/movimentacao/registrar_entrada_nfe.php'], true)) {
 define('SRC_PATH', $root . '/src');
 define('SESSION_NAME', 'supplier_test'); define('SESSION_LIFETIME', 3600); define('CSRF_TOKEN_NAME', 'csrf_token'); define('APP_NAME', 'Teste');
 ini_set('session.save_path', __DIR__);
 session_start();
 $profile = (int) ($_SERVER['HTTP_X_TEST_PROFILE'] ?? 0);
 $_SESSION = $profile ? ['logged_in' => true, 'user_id' => 1, 'user_profile' => $profile, 'user_name' => 'Admin teste', 'login_time' => time(), 'csrf_token' => 'csrf-teste'] : [];
 $code = file_get_contents($root . '/public' . $path);
 $code = str_replace("require_once __DIR__ . '/../../config/config.php';", '', $code);
 $code = str_replace('__DIR__', var_export(dirname($root . '/public' . $path), true), $code);
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
function installNfe(PDO $pdo): void {
    $pdo->exec(<<<'SQL'
CREATE TABLE tipos_movimentacao (id INTEGER PRIMARY KEY, tipo TEXT); INSERT INTO tipos_movimentacao VALUES (1, 'ENTRADA');
CREATE TABLE produtos (id INTEGER PRIMARY KEY, nome TEXT, estoque_atual NUMERIC DEFAULT 0); INSERT INTO produtos VALUES (1, 'Produto teste', 0);
CREATE VIEW vw_produtos_estoque_critico AS SELECT * FROM produtos WHERE 0;
CREATE TABLE movimentacoes (id INTEGER PRIMARY KEY AUTOINCREMENT, tipo_movimentacao_id INTEGER, data_movimentacao TEXT, usuario_id INTEGER REFERENCES usuarios(id), fornecedor_id INTEGER REFERENCES fornecedores(id), observacoes TEXT, status TEXT, inventario_id INTEGER, valor_total NUMERIC, documento_numero TEXT);
CREATE TABLE movimentacao_itens (id INTEGER PRIMARY KEY AUTOINCREMENT, movimentacao_id INTEGER REFERENCES movimentacoes(id), produto_id INTEGER REFERENCES produtos(id), quantidade NUMERIC, valor_unitario NUMERIC, valor_total NUMERIC);
CREATE TABLE estoques (id INTEGER PRIMARY KEY AUTOINCREMENT, produto_id INTEGER REFERENCES produtos(id), valor_unitario NUMERIC, data_entrada TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE notas_fiscais (id INTEGER PRIMARY KEY AUTOINCREMENT, movimentacao_id INTEGER REFERENCES movimentacoes(id), numero_nota TEXT UNIQUE, chave_acesso TEXT UNIQUE, data_emissao TEXT, fornecedor_id INTEGER REFERENCES fornecedores(id), valor_total NUMERIC, processado INTEGER, usuario_id INTEGER REFERENCES usuarios(id));
CREATE TABLE itens_nota (id INTEGER PRIMARY KEY AUTOINCREMENT, nome_xml TEXT UNIQUE, produto_id INTEGER REFERENCES produtos(id));
SQL);
}
function formNfe(string $path, array $body, int $profile = 1): array {
    global $base;
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\nX-Test-Profile: $profile\r\n", 'content' => http_build_query($body), 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 5]]);
    $raw = file_get_contents($base . '/movimentacao/' . $path, false, $ctx);
    preg_match('/HTTP\/\S+ (\d+)/', $http_response_header[0], $m);
    return [(int) $m[1], json_decode($raw, true)];
}
$server = null;
try {
    $web = connect($dir . '/web.sqlite'); install($web); installSuppliers($web); installNfe($web);
    $local = connect($dir . '/local.sqlite'); install($local); installSuppliers($local); installNfe($local);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) throw new RuntimeException('Não foi possível reservar porta local.');
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $server = proc_open([PHP_BINARY, '-n', '-d', 'extension=pdo', '-d', 'extension=pdo_sqlite', '-d', 'extension=mbstring', '-S', $address, $dir . '/router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $dir . '/server.log', 'a'], 2 => ['file', $dir . '/server.log', 'a']], $serverPipes);
    if (!is_resource($server)) throw new RuntimeException('Servidor de teste não iniciou.');
    $ready = false;
    for ($i = 0; $i < 50; $i++) { $s = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1); if ($s) { fclose($s); $ready = true; break; } usleep(20000); }
    check($ready, 'Servidor não respondeu.');
    $base = 'http://' . $address; $url = $base . '/api/sync.php';
    $ajax = ['cnpj' => '12.345.678/0001-90', 'razao_social' => 'Emitente inicial', 'fornecedor_dados' => ['nome_fantasia' => 'Fantasia', 'cidade' => 'São Paulo', 'estado' => 'sp', 'email' => 'contato@example.com']];
    [$status] = formNfe('processa_cadastro.php', $ajax, 0); check($status === 403, 'AJAX sem login aceito.');
    [$status, $body] = formNfe('processa_cadastro.php', $ajax);
    check($status === 200 && $body['success'] === true, 'Cadastro AJAX falhou.');
    check((int) scalar($web, 'SELECT COUNT(*) FROM sincronizacao') === 1, 'AJAX não enfileirou fornecedor.');
    formNfe('processa_cadastro.php', $ajax);
    check((int) scalar($web, 'SELECT COUNT(*) FROM fornecedores') === 1 && (int) scalar($web, 'SELECT COUNT(*) FROM sincronizacao') === 1, 'AJAX repetido duplicou fornecedor/evento.');
    $post = ['fornecedor_cnpj' => $ajax['cnpj'], 'fornecedor_razao_social' => 'Emitente atualizado', 'nota_numero' => '100', 'nota_chave_acesso' => str_repeat('1', 44),
        'nota_data_emissao' => '2026-11-09', 'nota_valor_total' => 7, 'id_fornecedor_sistema' => 1,
        'fornecedor_dados' => ['cidade' => 'Campinas', 'email' => ''], 'produtos' => [['id_produto_sistema' => 1, 'quantidade' => 2, 'valor_unitario' => 3.5, 'nome_xml' => 'Produto XML']]];
    formNfe('registrar_entrada_nfe.php', $post);
    check((int) scalar($web, 'SELECT COUNT(*) FROM notas_fiscais') === 1, 'Entrada da nota falhou.');
    $events = $web->query('SELECT * FROM sincronizacao ORDER BY id')->fetchAll();
    check(array_column($events, 'acao') === ['INSERT', 'UPDATE', 'ENTRADA_NFE'], 'Ordem fornecedor/atualização/nota incorreta.');
    $changes = json_decode($events[1]['dados'], true);
    check($changes['alteracoes']['razao_social'] === 'Emitente atualizado' && $changes['alteracoes']['cidade'] === 'Campinas', 'Atualização não enfileirada.');
    check(!isset($changes['alteracoes']['email']), 'XML vazio apagaria e-mail.');
    $note = json_decode($events[2]['dados'], true);
    check($note['fornecedor'] === 1 && $note['fornecedor_evento_id'] === $changes['evento_id'], 'NF-e sem dependência do fornecedor.');
    runSync();
    check((int) scalar($local, 'SELECT COUNT(*) FROM fornecedores') === 1, 'Fornecedor duplicado pela NF-e no PULL.');
    check(scalar($local, 'SELECT razao_social FROM fornecedores') === 'Emitente atualizado', 'Nome não atualizado no PULL.');
    check(scalar($local, 'SELECT email FROM fornecedores') === 'contato@example.com' && scalar($local, 'SELECT cidade FROM fornecedores') === 'Campinas', 'Opcionais não preservados.');
    check((int) scalar($local, 'SELECT COUNT(*) FROM notas_fiscais') === 1 && (int) scalar($local, 'SELECT fornecedor_id FROM notas_fiscais') === 1, 'Nota não vinculada ao fornecedor recebido.');
    check((int) scalar($local, 'SELECT estoque_atual FROM produtos') === 2, 'Estoque incorreto no PULL.');
    check((int) scalar($local, 'SELECT COUNT(*) FROM sincronizacao WHERE sincronizado = 0') === 0, 'Recebimento gerou eco.');
    runSync();
    check((int) scalar($local, 'SELECT estoque_atual FROM produtos') === 2, 'Reexecução duplicou estoque.');

    // Cadastro sem botão AJAX também gera evento antes da nota, agora no sentido local → web.
    bindDatabase($local);
    $supplier = (new Services\NfeSupplierService($local))->save(['cnpj' => '98765432000199', 'razao_social' => 'Fornecedor local'], 1);
    $update = (new Services\NfeSupplierService($local))->save(['cnpj' => '98765432000199', 'razao_social' => 'Local atualizado', 'telefone' => '11999999999'], 1, $supplier['id']);
    $localNote = array_replace($note, ['fornecedor' => $supplier['id'], 'fornecedor_evento_id' => $update['evento_id'], 'cnpj' => '98765432000199', 'razao_social' => 'Local atualizado', 'numero' => '200', 'chave_acesso' => str_repeat('2', 44)]);
    Helpers\SyncQueueHelper::queueChange('notas_fiscais', 2, 'ENTRADA_NFE', $localNote);
    runSync();
    check((int) scalar($web, 'SELECT COUNT(*) FROM fornecedores') === 2 && scalar($web, 'SELECT razao_social FROM fornecedores WHERE id = 2') === 'Local atualizado', 'Cadastro/atualização PUSH falhou.');
    check((int) scalar($web, 'SELECT COUNT(*) FROM notas_fiscais') === 2 && (int) scalar($web, 'SELECT fornecedor_id FROM notas_fiscais WHERE numero_nota = 200') === 2, 'NF-e PUSH duplicou/perdeu fornecedor.');
    [$status] = httpRequest($url, ['tabela' => 'notas_fiscais', 'registro_id' => 2, 'acao' => 'ENTRADA_NFE', 'dados' => $localNote]);
    check($status === 200 && (int) scalar($web, 'SELECT estoque_atual FROM produtos') === 4, 'Reenvio NF-e duplicou estoque.');
    $badNote = array_replace($localNote, ['numero' => '300', 'chave_acesso' => str_repeat('3', 44), 'fornecedor_evento_id' => str_repeat('a', 32)]);
    [$status] = httpRequest($url, ['tabela' => 'notas_fiscais', 'registro_id' => 3, 'acao' => 'ENTRADA_NFE', 'dados' => $badNote]);
    check($status === 500 && (int) scalar($web, 'SELECT COUNT(*) FROM notas_fiscais') === 2, 'NF-e aplicada antes do evento de fornecedor.');

    // Falha ao gravar a nota no receptor não pode confirmar nem manter estoque parcial.
    $web->exec("CREATE TRIGGER fail_received_note BEFORE INSERT ON notas_fiscais BEGIN SELECT RAISE(ABORT, 'nota indisponível'); END");
    $failedReceived = array_replace($localNote, ['numero' => '301', 'chave_acesso' => str_repeat('5', 44)]);
    [$status] = httpRequest($url, ['tabela' => 'notas_fiscais', 'registro_id' => 301, 'acao' => 'ENTRADA_NFE', 'dados' => $failedReceived]);
    check($status === 500 && (int) scalar($web, 'SELECT estoque_atual FROM produtos') === 4 && (int) scalar($web, 'SELECT COUNT(*) FROM notas_fiscais') === 2, 'Receptor confirmou nota com falha ou deixou estoque parcial.');
    $web->exec('DROP TRIGGER fail_received_note');

    // Falha ao enfileirar a nota desfaz inclusive o novo fornecedor e seu evento.
    $counts = [];
    foreach (['fornecedores', 'sincronizacao', 'notas_fiscais', 'movimentacoes', 'movimentacao_itens', 'estoques', 'auditoria', 'itens_nota'] as $table) $counts[$table] = scalar($web, "SELECT COUNT(*) FROM $table");
    $stock = scalar($web, 'SELECT estoque_atual FROM produtos');
    $web->exec("CREATE TRIGGER fail_nfe_queue BEFORE INSERT ON sincronizacao WHEN NEW.acao = 'ENTRADA_NFE' BEGIN SELECT RAISE(ABORT, 'fila nota indisponível'); END");
    $failingPost = array_replace($post, ['fornecedor_cnpj' => '11111111000111', 'fornecedor_razao_social' => 'Rollback fornecedor', 'id_fornecedor_sistema' => 'cadastrar_novo', 'nota_numero' => '400', 'nota_chave_acesso' => str_repeat('4', 44)]);
    $failingPost['produtos'][0]['nome_xml'] = 'Mapeamento rollback';
    formNfe('registrar_entrada_nfe.php', $failingPost);
    foreach ($counts as $table => $count) check(scalar($web, "SELECT COUNT(*) FROM $table") === $count, 'Rollback parcial: ' . $table);
    check(scalar($web, 'SELECT estoque_atual FROM produtos') === $stock, 'Rollback não restaurou estoque.');
    $web->exec('DROP TRIGGER fail_nfe_queue');

    // Falha no evento de fornecedor também desfaz alterações feitas pelo AJAX.
    $web->exec("CREATE TRIGGER fail_supplier_queue BEFORE INSERT ON sincronizacao WHEN NEW.tabela = 'fornecedores' BEGIN SELECT RAISE(ABORT, 'fila fornecedor indisponível'); END");
    [$status] = formNfe('processa_cadastro.php', array_replace($ajax, ['razao_social' => 'Não deve persistir']));
    check($status === 400 && scalar($web, 'SELECT razao_social FROM fornecedores WHERE id = 1') === 'Emitente atualizado', 'AJAX deixou atualização parcial.');
    $web->exec('DROP TRIGGER fail_supplier_queue');
    // O caminho sem botão AJAX também precisa criar fornecedor e nota juntos.
    formNfe('registrar_entrada_nfe.php', $failingPost);
    check((int) scalar($web, 'SELECT COUNT(*) FROM fornecedores') === 3 && (int) scalar($web, 'SELECT COUNT(*) FROM notas_fiscais') === 3, 'Cadastro direto pela entrada não persistiu.');
    $last = $web->query('SELECT acao FROM sincronizacao ORDER BY id DESC LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
    check($last === ['ENTRADA_NFE', 'INSERT'], 'Cadastro direto não antecedeu a nota na fila.');
    runSync();
    check((int) scalar($local, 'SELECT COUNT(*) FROM fornecedores') === 3 && (int) scalar($local, "SELECT fornecedor_id FROM notas_fiscais WHERE numero_nota = '400'") === 3, 'Cadastro direto da nota não sincronizou.');
    // AJAX também atualiza dados existentes e enfileira somente os campos alterados.
    $changedAjax = $ajax;
    $changedAjax['razao_social'] = 'Emitente atualizado';
    $changedAjax['fornecedor_dados'] = ['telefone' => '1133334444'];
    [$status] = formNfe('processa_cadastro.php', $changedAjax);
    check($status === 200 && scalar($web, 'SELECT telefone FROM fornecedores WHERE id = 1') === '1133334444', 'Atualização AJAX não persistiu.');
    $lastUpdate = json_decode($web->query("SELECT dados FROM sincronizacao WHERE tabela = 'fornecedores' ORDER BY id DESC LIMIT 1")->fetchColumn(), true);
    check($lastUpdate['alteracoes'] === ['telefone' => '1133334444'], 'AJAX não enfileirou delta de contato.');
    runSync();
    check(scalar($local, 'SELECT telefone FROM fornecedores WHERE id = 1') === '1133334444', 'Atualização AJAX não sincronizou.');
    // Reativação por NF-e, inclusive sem mudança de nome ou contato.
    model($web)->deactivateSupplier(1, 1);
    runSync();
    check((int) scalar($local, 'SELECT ativo FROM fornecedores WHERE id = 1') === 0, 'Inativação prévia não sincronizou.');
    $reactivatePost = array_replace($post, ['nota_numero' => '500', 'nota_chave_acesso' => str_repeat('6', 44), 'id_fornecedor_sistema' => 'cadastrar_novo']);
    formNfe('registrar_entrada_nfe.php', $reactivatePost);
    check((int) scalar($web, 'SELECT ativo FROM fornecedores WHERE id = 1') === 1 && (int) scalar($web, 'SELECT COUNT(*) FROM fornecedores') === 3, 'NF-e não reativou o mesmo fornecedor.');
    $activation = $web->query("SELECT * FROM sincronizacao WHERE tabela = 'fornecedores' ORDER BY id DESC LIMIT 1")->fetch();
    $activationData = json_decode($activation['dados'], true);
    check($activationData['antes']['ativo'] === 0 && $activationData['alteracoes'] === ['ativo' => 1], 'Reativação não enfileirada sem outros dados alterados.');
    runSync();
    check((int) scalar($local, 'SELECT ativo FROM fornecedores WHERE id = 1') === 1, 'PULL não reativou fornecedor.');
    check((int) scalar($local, "SELECT fornecedor_id FROM notas_fiscais WHERE numero_nota = '500'") === 1, 'Nota perdeu vínculo após reativação.');
    // Reentrega de reativação antiga não desfaz uma exclusão posterior.
    model($web)->deactivateSupplier(1, 1);
    runSync();
    $pull = new Services\SyncPullService($local);
    $activation['id'] = 9999;
    $pull->aplicar('reativacao-atrasada', $activation, fn($e, $d) => (new Services\SupplierSyncService($local))->receber($e['acao'], (int) $e['registro_id'], $d));
    check((int) scalar($local, 'SELECT ativo FROM fornecedores WHERE id = 1') === 0, 'Reativação antiga desfez exclusão posterior.');
    // AJAX também reativa e envia o evento no PUSH.
    bindDatabase($local);
    $r = (new Services\NfeSupplierService($local))->save(['cnpj' => '12345678000190', 'razao_social' => 'Emitente atualizado'], 1);
    runSync();
    check((int) scalar($web, 'SELECT ativo FROM fornecedores WHERE id = 1') === 1, 'PUSH não reativou fornecedor.');
    // Uma falha na fila da nota deve desfazer a reativação e seu evento.
    model($web)->deactivateSupplier(1, 1);
    runSync();
    $queueBefore = scalar($web, 'SELECT COUNT(*) FROM sincronizacao');
    $web->exec("CREATE TRIGGER fail_reactivation BEFORE INSERT ON sincronizacao WHEN NEW.acao = 'ENTRADA_NFE' BEGIN SELECT RAISE(ABORT, 'fila indisponível'); END");
    $reactivatePost['nota_numero'] = '501'; $reactivatePost['nota_chave_acesso'] = str_repeat('7', 44);
    formNfe('registrar_entrada_nfe.php', $reactivatePost);
    check((int) scalar($web, 'SELECT ativo FROM fornecedores WHERE id = 1') === 0 && scalar($web, 'SELECT COUNT(*) FROM sincronizacao') === $queueBefore, 'Falha na nota deixou reativação parcial.');
    $web->exec('DROP TRIGGER fail_reactivation');
    // Nome/contato conflitante no destino não pode ser sobrescrito em silêncio.
    bindDatabase($local);
    $newUpdate = (new Services\NfeSupplierService($local))->save(['cnpj' => '98765432000199', 'razao_social' => 'Novo nome local'], 1, 2);
    $event = $local->query("SELECT * FROM sincronizacao WHERE tabela = 'fornecedores' ORDER BY id DESC LIMIT 1")->fetch();
    $web->exec("UPDATE fornecedores SET razao_social = 'Nome alterado na web' WHERE id = 2");
    [$status] = httpRequest($url, ['tabela' => 'fornecedores', 'registro_id' => 2, 'acao' => 'UPDATE', 'dados' => json_decode($event['dados'], true)]);
    check($status === 500 && scalar($web, 'SELECT razao_social FROM fornecedores WHERE id = 2') === 'Nome alterado na web', 'Conflito de atualização sobrescrito.');
    // XML com acentos e informações do emitente atravessa o parser.
    $xml = '<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe"><NFe><infNFe Id="NFe123"><emit><CNPJ>12345678000190</CNPJ><xNome>Razão São João</xNome><xFant>Fantasia</xFant><enderEmit><xLgr>Rua São José</xLgr><nro>10</nro><xMun>São Paulo</xMun><UF>SP</UF><CEP>01234567</CEP><fone>1199999999</fone></enderEmit></emit><ide><nNF>1</nNF><dhEmi>2026-11-09</dhEmi></ide><total><ICMSTot><vNF>7</vNF></ICMSTot></total></infNFe></NFe></nfeProc>';
    file_put_contents($dir . '/nota.xml', $xml);
    $parsed = (new Helpers\NFeProcessor())->extractDataForVerification($dir . '/nota.xml');
    check($parsed['fornecedor']['razao_social'] === 'Razão São João' && $parsed['fornecedor']['cidade'] === 'São Paulo', 'Parser perdeu acentos/campos.');
    echo "OK: $checks verificações — AJAX e entrada NF-e reais, fornecedor INSERT/UPDATE antes da nota, PUSH/PULL, dependências, opcionais, reenvio, conflitos e rollback completo.\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); fclose($serverPipes[0]); proc_close($server); }
    foreach (glob($dir . '/*') as $file) unlink($file);
    rmdir($dir);
}
