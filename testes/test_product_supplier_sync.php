<?php
/** Integração isolada: duas bases SQLite, rota HTTP real e scripts/sync2.php real. */
$root = dirname(__DIR__);
$dir = sys_get_temp_dir() . '/product-supplier-sync-' . bin2hex(random_bytes(6));
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
    $pdo->exec('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 3000');
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
    // O processo de teste mantém o singleton vivo além de uma requisição web.
    // Encerra seu cursor de leitura antes de outro processo confirmar a fila SQLite.
    $ref = new ReflectionClass(Models\Database::class);
    $instance = $ref->getProperty('instance')->getValue();
    if ($instance) {
        $statement = $ref->getProperty('statement')->getValue($instance);
        if ($statement instanceof PDOStatement) $statement->closeCursor();
    }
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
$route = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (in_array($route, ['/admin/suppliers.php', '/admin/product_suppliers.php'], true)) {
 define('SESSION_NAME', 'supplier_test'); define('SESSION_LIFETIME', 3600); define('CSRF_TOKEN_NAME', 'csrf_token'); define('APP_NAME', 'Teste');
 ini_set('session.save_path', __DIR__);
 session_start();
 $profile = (int) ($_SERVER['HTTP_X_TEST_PROFILE'] ?? 0);
 $_SESSION = $profile ? ['logged_in' => true, 'user_id' => 1, 'user_profile' => $profile, 'user_name' => 'Admin teste', 'login_time' => time(), 'csrf_token' => 'csrf-teste'] : [];
 $code = file_get_contents($root . '/public' . $route);
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
    $pdo->exec('CREATE TABLE IF NOT EXISTS produtos (id INTEGER PRIMARY KEY AUTOINCREMENT, nome TEXT, codigo TEXT, ativo INTEGER DEFAULT 1, fornecedor_principal_id INTEGER REFERENCES fornecedores(id), estoque_atual NUMERIC DEFAULT 0, categoria_id INTEGER, unidade_medida_id INTEGER, criado_por INTEGER, criado_em TEXT DEFAULT CURRENT_TIMESTAMP, atualizado_em TEXT DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE produto_fornecedores (id INTEGER PRIMARY KEY AUTOINCREMENT, produto_id INTEGER REFERENCES produtos(id), fornecedor_id INTEGER REFERENCES fornecedores(id), ativo INTEGER NOT NULL DEFAULT 1, criado_por INTEGER REFERENCES usuarios(id), criado_em TEXT DEFAULT CURRENT_TIMESTAMP, atualizado_em TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE(produto_id, fornecedor_id))');
    $pdo->exec('CREATE TABLE notas_teste (fornecedor_id INTEGER REFERENCES fornecedores(id))');
}
function model(PDO $pdo): Models\AdminFornecedor { bindDatabase($pdo); return new Models\AdminFornecedor(); }
function pageRequest(string $base, string $method = 'GET', array $body = [], int $profile = 1, ?int $editId = null): array {
    $context = stream_context_create(['http' => ['method' => $method, 'header' => "Content-Type: application/x-www-form-urlencoded\r\nX-Test-Profile: $profile\r\n",
        'content' => $method === 'POST' ? http_build_query($body) : '', 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 5]]);
    $raw = file_get_contents($base . '/admin/suppliers.php' . ($editId === null ? '' : '?edit=' . $editId), false, $context);
    preg_match('/HTTP\/\S+ (\d+)/', $http_response_header[0], $m);
    return [(int) $m[1], $raw];
}
function relationRequest(string $method, array $data, int $profile = 1): array {
    global $base;
    $ctx = stream_context_create(['http' => ['method' => $method, 'header' => "Content-Type: application/x-www-form-urlencoded\r\nX-Test-Profile: $profile\r\n",
        'content' => $method === 'POST' ? http_build_query($data) : '', 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 5]]);
    $raw = file_get_contents($base . '/admin/product_suppliers.php' . ($method === 'GET' ? '?' . http_build_query($data) : ''), false, $ctx);
    preg_match('/HTTP\/\S+ (\d+)/', $http_response_header[0], $m);
    return [(int) $m[1], json_decode($raw, true)];
}
$server = null;
try {
    $web = connect($dir . '/web.sqlite'); install($web); installSuppliers($web);
    $local = connect($dir . '/local.sqlite'); install($local); installSuppliers($local);
    foreach ([$web, $local] as $pdo) {
        $pdo->exec("INSERT INTO produtos (id,nome,codigo,ativo,categoria_id,unidade_medida_id) VALUES (1,'Produto A','A',1,1,1),(2,'Produto B','B',1,1,1)");
        $pdo->exec("INSERT INTO fornecedores (id,razao_social,cnpj,tipo,ativo,criado_por) VALUES (1,'Fornecedor A','11111111000111','PJ',1,1),(2,'Fornecedor B','22222222000122','PJ',1,1)");
    }
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) throw new RuntimeException('Porta de teste indisponível.');
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $server = proc_open([PHP_BINARY,'-n','-d','extension=pdo','-d','extension=pdo_sqlite','-d','extension=mbstring','-S',$address,$dir.'/router.php'],
        [0=>['pipe','r'],1=>['file',$dir.'/server.log','a'],2=>['file',$dir.'/server.log','a']], $serverPipes);
    if (!is_resource($server)) throw new RuntimeException('Servidor não iniciou.');
    $ready = false;
    for ($i=0;$i<50;$i++) { $s=@stream_socket_client('tcp://'.$address,$errno,$error,0.1); if($s){fclose($s);$ready=true;break;} usleep(20000); }
    check($ready, 'Servidor indisponível.');
    $base = 'http://'.$address; $url=$base.'/api/sync.php';
    $add = ['action'=>'add','produto_id'=>1,'fornecedor_id'=>1,'csrf_token'=>'csrf-teste'];
    [$status] = relationRequest('POST', $add, 0); check($status===403,'Visitante associou produto.');
    [$status] = relationRequest('POST', $add, 2); check($status===403,'Operador associou produto.');
    [$status] = relationRequest('POST', array_replace($add,['csrf_token'=>'errado'])); check($status===400,'CSRF inválido aceito.');
    [$status,$body] = relationRequest('POST',$add); check($status===200 && $body['success']===true,'Associação falhou.');
    $count=scalar($web,'SELECT COUNT(*) FROM sincronizacao');
    relationRequest('POST',$add);
    check(scalar($web,'SELECT COUNT(*) FROM sincronizacao')===$count,'Associação repetida gerou nova pendência.');
    relationRequest('POST',array_replace($add,['action'=>'principal']));
    check((int)scalar($web,'SELECT fornecedor_principal_id FROM produtos WHERE id=1')===1,'Principal não definido.');
    [$status,$body]=relationRequest('GET',['produto_id'=>1]);
    check($status===200 && count($body['data'])===1 && (int)$body['data'][0]['principal']===1,'Card não retorna fornecedor/principal.');
    [$status,$html]=pageRequest($base);
    check($status===200 && str_contains($html,'supplierProductsModal') && str_contains($html,'>Produtos</button>'),'Modal Produtos não está na tela de fornecedores.');
    runSync();
    check((int)scalar($local,'SELECT COUNT(*) FROM produto_fornecedores')===1 && (int)scalar($local,'SELECT fornecedor_principal_id FROM produtos WHERE id=1')===1,'PULL não aplicou vínculo/principal.');
    check((int)scalar($local,'SELECT COUNT(*) FROM sincronizacao WHERE sincronizado=0')===0,'PULL gerou eco.');
    $oldEvent=$web->query("SELECT * FROM sincronizacao WHERE tabela='produto_fornecedores' ORDER BY id DESC LIMIT 1")->fetch();
    relationRequest('POST',array_replace($add,['action'=>'remove']));
    check(scalar($web,'SELECT fornecedor_principal_id FROM produtos WHERE id=1')===null,'Desvincular principal não limpou a indicação.');
    runSync();
    check((int)scalar($local,'SELECT ativo FROM produto_fornecedores')===0 && scalar($local,'SELECT fornecedor_principal_id FROM produtos WHERE id=1')===null,'PULL não desativou/limpou principal.');
    // Reentrega de evento antigo não reativa a associação.
    $pull=new Services\SyncPullService($local); $oldEvent['id']=9999;
    $pull->aplicar('reentrega',$oldEvent,fn($e,$d)=>(new Services\ProductSupplierService($local))->receber($e['acao'],(int)$e['registro_id'],$d));
    check((int)scalar($local,'SELECT ativo FROM produto_fornecedores')===0,'Reentrega reativou vínculo removido.');
    bindDatabase($local);
    $service=new Services\ProductSupplierService($local);
    $service->set(1,1,true,1);
    runSync();
    check((int)scalar($web,'SELECT ativo FROM produto_fornecedores')===1 && (int)scalar($web,'SELECT COUNT(*) FROM produto_fornecedores')===1,'PUSH não reativou o mesmo par.');
    $event=$local->query("SELECT * FROM sincronizacao WHERE tabela='produto_fornecedores' ORDER BY id DESC LIMIT 1")->fetch();
    $payload=['tabela'=>'produto_fornecedores','registro_id'=>1,'acao'=>'UPDATE','dados'=>json_decode($event['dados'],true)];
    $before=scalar($web,'SELECT COUNT(*) FROM sincronizacao');
    for($i=0;$i<2;$i++){[$status]=httpRequest($url,$payload);check($status===200,'PUSH repetido falhou.');}
    check(scalar($web,'SELECT COUNT(*) FROM sincronizacao')===$before,'PUSH repetido duplicou recibos.');
    $payload['dados']['ativo']=0;
    [$status]=httpRequest($url,$payload);check($status===500,'Evento com UUID adulterado aceito.');
    bindDatabase($web);
    $service=new Services\ProductSupplierService($web);
    $service->set(1,2,true,1,true);
    runSync();
    check((int)scalar($local,'SELECT fornecedor_principal_id FROM produtos WHERE id=1')===2,'Troca de principal não sincronizou.');
    // Dois fornecedores por produto e dois produtos por fornecedor.
    $service->set(2,2,true,1);
    [$status,$body]=relationRequest('GET',['fornecedor_id'=>2]);
    check($status===200 && count($body['data'])===2,'Modal não retorna vários produtos por fornecedor.');
    model($web)->deactivateSupplier(2,1);
    check(count($service->listFor('produto',1))===1,'Fornecedor inativo não ocultado.');
    check((int)scalar($web,'SELECT COUNT(*) FROM produto_fornecedores WHERE fornecedor_id=2')===2,'Inativação apagou vínculos.');
    fails(fn()=>$service->set(1,2,true,1),'Fornecedor inativo associado.');
    // Cadastro de fornecedor com seleção de produtos (evento do pai precede o vínculo).
    [$status]=pageRequest($base,'POST',['action'=>'create','csrf_token'=>'csrf-teste','razao_social'=>'Novo fornecedor','produtos'=>[1,2]]);
    check($status===302,'Cadastro de fornecedor com produtos falhou.');
    check((int)scalar($web,'SELECT COUNT(*) FROM produto_fornecedores WHERE fornecedor_id=3')===2,'Seleção de produtos no cadastro não aplicada.');
    // Cadastro do produto com seleção de fornecedores e fornecedor principal.
    bindDatabase($web);
    $p=(new Models\Product())->create(['nome'=>'Novo produto','categoria_id'=>1,'unidade_medida_id'=>1,'ativo'=>1,'fornecedores'=>[1,3],'fornecedor_principal_id'=>3]);
    check((int)scalar($web,"SELECT COUNT(*) FROM produto_fornecedores WHERE produto_id=$p")===2,'Cadastro do produto não associou fornecedores.');
    runSync();
    check((int)scalar($local,"SELECT COUNT(*) FROM produto_fornecedores WHERE produto_id=$p")===2,'Cadastros e vínculos não sincronizaram juntos.');
    // Falha na fila desfaz vínculo, auditoria e alteração de principal.
    $web->exec("CREATE TRIGGER fail_link_queue BEFORE INSERT ON sincronizacao WHEN NEW.tabela='produto_fornecedores' BEGIN SELECT RAISE(ABORT,'fila indisponível'); END");
    $count=scalar($web,'SELECT COUNT(*) FROM auditoria');
    fails(fn()=>$service->set(1,1,true,1,true),'Principal alterado sem fila.');
    check((int)scalar($web,'SELECT fornecedor_principal_id FROM produtos WHERE id=1')===2 && scalar($web,'SELECT COUNT(*) FROM auditoria')===$count,'Rollback de principal/auditoria falhou.');
    $web->exec('DROP TRIGGER fail_link_queue');
    // Atualização do principal pelo formulário antigo também cria o vínculo.
    (new Models\Product())->update(2,['fornecedor_principal_id'=>1]);
    check((int)scalar($web,'SELECT COUNT(*) FROM produto_fornecedores WHERE produto_id=2 AND fornecedor_id=1 AND ativo=1')===1,'Atualização do principal não criou vínculo.');
    runSync();
    check((int)scalar($local,'SELECT fornecedor_principal_id FROM produtos WHERE id=2')===1,'Atualização pelo produto não sincronizou.');
    check((int)scalar($web,'SELECT COUNT(*) FROM sincronizacao WHERE sincronizado=0')===0,'Origem ficou pendente após ACK.');
    echo "OK: $checks verificações — vínculos N:N, endpoint real, admin/CSRF, cadastros, principal, remoção/reativação, PUSH/PULL, reenvio, ausência de eco e rollback.\n";
} finally {
    if(is_resource($server)){proc_terminate($server);fclose($serverPipes[0]);proc_close($server);}
    foreach(glob($dir.'/*') as $file)unlink($file);
    rmdir($dir);
}
