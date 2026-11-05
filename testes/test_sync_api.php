<?php
/** Exercita a rota real em servidor PHP local, com SQLite temporário e token de teste. */
$root = getenv('ESTOQUE_TEST_ROOT') ?: dirname(__DIR__);
require_once $root . '/src/Services/SyncPullService.php';
$dir = sys_get_temp_dir() . '/sync-api-' . bin2hex(random_bytes(6));
mkdir($dir);
$pdo = new PDO('sqlite:' . $dir . '/db.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE sincronizacao (id INTEGER PRIMARY KEY, tabela TEXT, registro_id INTEGER, acao TEXT, dados TEXT, criado_em TEXT, sincronizado INTEGER DEFAULT 0, tentativas INTEGER DEFAULT 0, sincronizado_em TEXT, erro_mensagem TEXT)');
$pdo->exec("INSERT INTO sincronizacao (id,tabela,registro_id,acao,dados,criado_em) VALUES (388,'movimentacoes',231,'SAIDA_DIRETA_ADM','{}','2000-01-01')");
$pdo->exec('CREATE TABLE produtos (id INTEGER PRIMARY KEY, nome TEXT, categoria_id INTEGER, unidade_medida_id INTEGER, ativo INTEGER DEFAULT 1)');
$pdo->exec(<<<'SQL'
ALTER TABLE produtos ADD COLUMN estoque_atual NUMERIC DEFAULT 0;
CREATE TABLE tipos_movimentacao (id INTEGER PRIMARY KEY, tipo TEXT);
INSERT INTO tipos_movimentacao VALUES (3, 'ENTRADA'), (7, 'SAIDA');
CREATE TABLE inventarios (id INTEGER PRIMARY KEY AUTOINCREMENT, usuario_id INTEGER, data_inicio TEXT, data_conclusao TEXT, status TEXT);
CREATE TABLE movimentacoes (id INTEGER PRIMARY KEY AUTOINCREMENT, tipo_movimentacao_id INTEGER, data_movimentacao TEXT, usuario_id INTEGER, observacoes TEXT, status TEXT, inventario_id INTEGER, valor_total NUMERIC);
CREATE TABLE movimentacao_itens (id INTEGER PRIMARY KEY, movimentacao_id INTEGER, produto_id INTEGER, quantidade NUMERIC, valor_unitario NUMERIC, valor_total NUMERIC);
CREATE TABLE estoques (id INTEGER PRIMARY KEY, produto_id INTEGER, valor_unitario NUMERIC, data_entrada TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE auditoria (id INTEGER PRIMARY KEY, tabela TEXT, registro_id INTEGER, acao TEXT, dados_anteriores TEXT, dados_novos TEXT, usuario_id INTEGER, ip_address TEXT, user_agent TEXT);
CREATE VIEW vw_produtos_estoque_critico AS SELECT id FROM produtos WHERE 0;
SQL);
$event = $pdo->query('SELECT * FROM sincronizacao')->fetch(PDO::FETCH_ASSOC);
$hash = Services\SyncPullService::hashEvento($event);
$router = <<<'ROUTER'
<?php
$root = __ROOT__;
define('SYNC_TOKEN', 'token-local-de-teste');
define('LOG_PATH', __DIR__);
spl_autoload_register(function ($class) use ($root) {
 $path = $root . '/src/' . str_replace('\\', '/', $class) . '.php';
 if (is_file($path)) require_once $path;
});
$pdo = new PDO('sqlite:' . __DIR__ . '/db.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->sqliteCreateFunction('JSON_UNQUOTE', fn($v) => $v);
$ref = new ReflectionClass(Models\Database::class);
$db = $ref->newInstanceWithoutConstructor();
$ref->getProperty('connection')->setValue($db, $pdo);
$ref->getProperty('instance')->setValue(null, $db);
// Executa a rota real, substituindo somente o bootstrap de produção.
$code = file_get_contents($root . '/public/api/sync.php');
$code = str_replace("require_once __DIR__ . '/../../config/config.php';", '', $code);
$code = str_replace("require_once __DIR__ . '/../../src/Controllers/SyncController.php';", '', $code);
eval('?>' . $code);
ROUTER;
file_put_contents($dir . '/router.php', str_replace('__ROOT__', var_export($root, true), $router));
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$socket) throw new RuntimeException('Não foi possível reservar porta local.');
$address = stream_socket_get_name($socket, false); fclose($socket);
$proc = proc_open([PHP_BINARY,'-n','-d','extension=pdo','-d','extension=pdo_sqlite','-S',$address,$dir.'/router.php'], [0=>['pipe','r'],1=>['file',$dir.'/server.log','a'],2=>['file',$dir.'/server.log','a']], $pipes);
if (!is_resource($proc)) throw new RuntimeException('Servidor local não iniciou.');
function request(string $url, string $method='GET', ?array $body=null, string $token='token-local-de-teste'): array {
 $context=stream_context_create(['http'=>['method'=>$method,'header'=>"Content-Type: application/json\r\nX-Api-Token: Bearer $token\r\n",'content'=>$body===null?'':json_encode($body),'ignore_errors'=>true,'timeout'=>3]]);
 $raw=file_get_contents($url,false,$context);
 preg_match('/HTTP\/\S+ (\d+)/',$http_response_header[0],$m);
 return [(int)$m[1],json_decode($raw,true)];
}
function check($v,$m){if(!$v)throw new RuntimeException($m);}
try {
 $ready=false;
 for($i=0;$i<50;$i++) { $s=@stream_socket_client('tcp://'.$address,$errno,$error,0.1); if($s){fclose($s);$ready=true;break;} usleep(20000); }
 check($ready,'Servidor local não respondeu.');
 $url='http://'.$address.'/api/sync.php';
 [$status,$body]=request($url.'?protocol=2');
 check($status===200 && $body['protocol']===2 && count($body['data'])===1,'GET protocolo 2 falhou.');
 [$status,$body]=request($url);
 check($status===409,'Cliente antigo não foi bloqueado.');
 $ack=['recebimentos'=>[['id'=>388,'hash'=>$hash]]];
 [$status,$body]=request($url.'?ack=1','POST',$ack,'token-invalido');
 check($status===401 && !str_contains(json_encode($body),'token-local-de-teste'),'ACK sem autenticação aceito ou token exposto.');
 [$status,$body]=request($url.'?ack=1','POST',['recebimentos'=>[['id'=>388,'hash'=>str_repeat('0',64)]]]);
 check($status===409,'Hash divergente aceito.');
 check($pdo->query('SELECT sincronizado FROM sincronizacao')->fetchColumn()==0,'ACK inválido alterou fila.');
 for($i=0;$i<2;$i++) {
  [$status,$body]=request($url.'?ack=1','POST',$ack);
  check($status===200 && $body['success']===true && $body['confirmed']===[388],'ACK não é idempotente pela API.');
 }
 [$status,$body]=request($url.'?protocol=2');
 check($status===200 && $body['data']===[],'Evento confirmado retornou pela API.');
 $product = ['tabela' => 'produtos', 'registro_id' => 42, 'acao' => 'INSERT',
  'dados' => ['id' => 42, 'nome' => 'Produto API', 'categoria_id' => 1, 'unidade_medida_id' => 1, 'ativo' => 1]];
 for ($i = 0; $i < 2; $i++) {
  [$status, $body] = request($url, 'POST', $product);
  check($status === 200 && $body['success'] === true, 'PUSH de produto ou repetição falhou.');
 }
 check((int) $pdo->query('SELECT COUNT(*) FROM produtos')->fetchColumn() === 1, 'PUSH duplicou produto.');
 $product['acao'] = 'UPDATE';
 $product['dados'] = ['ativo' => 0];
 [$status, $body] = request($url, 'POST', $product);
 check($status === 200 && $pdo->query('SELECT ativo FROM produtos WHERE id = 42')->fetchColumn() == 0, 'Inativação via API falhou.');
 $product['dados'] = ['ativo' => 1];
 [$status, $body] = request($url, 'POST', $product);
 check($status === 200 && $pdo->query('SELECT ativo FROM produtos WHERE id = 42')->fetchColumn() == 1, 'Reativação via API falhou.');
 $product['acao'] = 'DELETE';
 $product['dados'] = [];
 [$status, $body] = request($url, 'POST', $product);
 check($status === 200 && $pdo->query('SELECT ativo FROM produtos WHERE id = 42')->fetchColumn() == 0, 'DELETE via API falhou.');
 [$status, $body] = request($url . '?protocol=2');
 check($body['data'] === [], 'Produto recebido gerou eco.');
 $inventory = ['tabela' => 'movimentacoes', 'registro_id' => 1, 'acao' => 'AJUSTE_INVENTARIO',
  'dados' => ['evento_id' => str_repeat('a', 32), 'user_id' => 7, 'data' => '2026-11-05 12:00:00',
   'itens' => [42 => ['diferenca' => 2, 'valor_unitario' => 4.25, 'valor_total' => 8.50]]]];
 for ($i = 0; $i < 2; $i++) {
  [$status, $body] = request($url, 'POST', $inventory);
  check($status === 200 && $body['success'] === true, 'PUSH/reenvio de inventário falhou: ' . json_encode($body));
 }
 check((int) $pdo->query('SELECT estoque_atual FROM produtos WHERE id = 42')->fetchColumn() === 2, 'PUSH duplicou ajuste de inventário.');
 check((int) $pdo->query('SELECT COUNT(*) FROM inventarios')->fetchColumn() === 1, 'PUSH duplicou histórico de inventário.');
 check((int) $pdo->query('SELECT COUNT(*) FROM estoques')->fetchColumn() === 2, 'PUSH duplicou unidades do inventário.');
 check((int) $pdo->query("SELECT COUNT(*) FROM sincronizacao WHERE acao = 'AJUSTE_INVENTARIO'")->fetchColumn() === 1, 'PUSH duplicou recibo do inventário.');
 [$status, $body] = request($url . '?protocol=2');
 check($body['data'] === [], 'Inventário recebido gerou eco.');
 $undo = $inventory;
 $undo['dados']['evento_id'] = str_repeat('b', 32);
 $undo['dados']['desfaz_evento_id'] = $inventory['dados']['evento_id'];
 $undo['dados']['itens'][42]['diferenca'] = -2;
 for ($i = 0; $i < 2; $i++) {
  [$status, $body] = request($url, 'POST', $undo);
  check($status === 200 && $body['success'] === true, 'PUSH/reenvio de desfazimento falhou: ' . json_encode($body));
 }
 check((int) $pdo->query('SELECT estoque_atual FROM produtos WHERE id = 42')->fetchColumn() === 0, 'Desfazimento não restaurou saldo.');
 check((int) $pdo->query('SELECT COUNT(*) FROM estoques')->fetchColumn() === 0, 'Desfazimento não removeu unidades.');
 check($pdo->query('SELECT status FROM inventarios')->fetchColumn() === 'CANCELADO', 'Desfazimento não cancelou inventário.');
 check((int) $pdo->query('SELECT COUNT(*) FROM movimentacoes')->fetchColumn() === 2, 'Reenvio duplicou movimento inverso.');
 [$status, $body] = request($url . '?protocol=2');
 check($body['data'] === [], 'Desfazimento recebido gerou eco.');
 echo "OK API: desfazimento, cancelamento do inventário, reenvio e ausência de eco.\n";
 echo "OK API: inventário, ajuste de estoque/unidades, reenvio e ausência de eco.\n";
 echo "OK API: produtos, repetição de cadastro, inativação, reativação e exclusão lógica.\n";
 echo "OK API: rota real, autenticação, protocolo, hash, confirmação persistente e repetição de ACK.\n";
} finally {
 proc_terminate($proc); fclose($pipes[0]); proc_close($proc);
 $pdo=null;
 foreach(glob($dir.'/*') as $file)unlink($file);
 rmdir($dir);
}
