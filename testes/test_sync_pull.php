<?php
/** Banco em memória; sem .env, rede, estoque real ou e-mail. */
$root = getenv('ESTOQUE_TEST_ROOT') ?: dirname(__DIR__);
spl_autoload_register(function ($class) use ($root) {
    $path = $root . '/src/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($path)) require_once $path;
});
function check($v, $message) { if (!$v) throw new RuntimeException($message); }
function fail(callable $fn) { try { $fn(); } catch (Throwable $e) { return; } throw new RuntimeException('Falha esperada não ocorreu.'); }
function banco(): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('CREATE TABLE sincronizacao (id INTEGER PRIMARY KEY, tabela TEXT, registro_id INTEGER, acao TEXT, dados TEXT, criado_em TEXT, sincronizado INTEGER DEFAULT 0, tentativas INTEGER DEFAULT 0, sincronizado_em TEXT, erro_mensagem TEXT)');
    $pdo->exec('CREATE TABLE saldo (id INTEGER PRIMARY KEY, unidades INTEGER); INSERT INTO saldo VALUES (2,10),(6,10)');
    return $pdo;
}
function evento(int $id): array {
    return ['id' => $id, 'tabela' => 'movimentacoes', 'registro_id' => 231, 'acao' => 'SAIDA_DIRETA_ADM',
        'dados' => json_encode(['Itens' => [2 => 1, 6 => 1], 'ItensVal' => [2 => 2, 6 => 3], 'totalGeral' => 5, 'UserID' => 1])];
}
function enfileirar(PDO $pdo, array $e, string $data = '2000-01-01 00:00:00') {
    $pdo->prepare('INSERT INTO sincronizacao (id,tabela,registro_id,acao,dados,criado_em) VALUES (?,?,?,?,?,?)')->execute([$e['id'],$e['tabela'],$e['registro_id'],$e['acao'],$e['dados'],$data]);
}
$local = banco(); $remoto = banco();
$l = new Services\SyncPullService($local); $r = new Services\SyncPullService($remoto);
$l->prepararRecibos(); $l->prepararRecibos();
$e = evento(388); enfileirar($remoto, $e);
$aplicar = function ($evento, $dados) use ($local) {
    foreach ($dados['Itens'] as $id => $qtd) $local->prepare('UPDATE saldo SET unidades = unidades - ? WHERE id = ?')->execute([$qtd, $id]);
};
check(count($r->listarPendentes()) === 1, 'Evento antigo foi omitido por data.');
check($l->aplicar('http://servidor/api/sync.php', $e, $aplicar), 'Evento não aplicado.');
check($local->query('SELECT SUM(unidades) FROM saldo')->fetchColumn() == 18, 'Baixa incorreta.');
check($remoto->query('SELECT sincronizado FROM sincronizacao')->fetchColumn() == 0, 'Servidor confirmou antes do ACK.');
check(!$l->aplicar('http://servidor/api/sync.php', $e, $aplicar), 'Evento duplicado reaplicado.');
check($local->query('SELECT SUM(unidades) FROM saldo')->fetchColumn() == 18, 'Reenvio repetiu baixa.');
$recibos = $l->pendentesConfirmacao('http://servidor/api/sync.php');
check(count($recibos) === 1, 'Falha de conexão perderia o recibo.');
$ids = $r->confirmarNoServidor($recibos); // O servidor confirmou, mas simulamos perder a resposta.
check($ids === [388], 'ACK incorreto.');
check($r->confirmarNoServidor($recibos) === [388], 'ACK repetido não é idempotente.');
$l->marcarConfirmados('http://servidor/api/sync.php', $recibos);
check($l->pendentesConfirmacao('http://servidor/api/sync.php') === [], 'Confirmação local não persistiu.');
check($r->listarPendentes() === [], 'Evento confirmado continua disponível.');
echo "OK: commit, baixa única, perda da resposta, retry de ACK e confirmação do servidor.\n";

$e2 = evento(389); enfileirar($remoto, $e2);
fail(fn() => $l->aplicar('http://servidor/api/sync.php', $e2, function ($e, $d) use ($aplicar) { $aplicar($e,$d); throw new RuntimeException('Falha após baixar estoque'); }));
check($local->query('SELECT SUM(unidades) FROM saldo')->fetchColumn() == 18, 'Rollback não reverteu a baixa.');
check($local->query('SELECT COUNT(*) FROM sync_recebimentos WHERE evento_id=389')->fetchColumn() == 0, 'Falha deixou recibo.');
check($r->listarPendentes()[0]['id'] == 389, 'Evento com falha foi confirmado.');
$l->aplicar('http://servidor/api/sync.php', $e2, $aplicar);
check($local->query('SELECT SUM(unidades) FROM saldo')->fetchColumn() == 16, 'Nova tentativa após rollback falhou.');
echo "OK: rollback de estoque e recibo, com nova tentativa segura.\n";

$changed = $e2; $changed['dados'] = '{"Itens":{"2":9}}';
fail(fn() => $l->aplicar('http://servidor/api/sync.php', $changed, $aplicar));
$bad = $e2; $bad['acao'] = 'ACAO_INVALIDA';
fail(fn() => $l->aplicar('http://servidor/api/sync.php', $bad, $aplicar));
$bad['id'] = 390; enfileirar($remoto, $bad);
$blocked = evento(391); enfileirar($remoto, $blocked); $remoto->exec('UPDATE sincronizacao SET tentativas=5 WHERE id=391');
check(array_column($r->listarPendentes(), 'id') === [389], 'Evento inválido/bloqueado entrou no lote.');
$pending = $l->pendentesConfirmacao('http://servidor/api/sync.php');
$invalid = ['id' => 388, 'hash' => str_repeat('0',64)];
fail(fn() => $r->confirmarNoServidor(array_merge($pending,[$invalid])));
check($remoto->query('SELECT sincronizado FROM sincronizacao WHERE id=389')->fetchColumn() == 0, 'ACK inválido confirmou parcialmente.');
fail(fn() => $r->confirmarNoServidor([['id'=>9999,'hash'=>str_repeat('0',64)]]));
fail(fn() => $l->aplicar('http://servidor/api/sync.php', array_replace($e2,['id'=>392,'dados'=>'quebrado']), $aplicar));
check($l->pendentesConfirmacao('http://outro/api/sync.php') === [], 'Recibo cruzou servidores.');
echo "OK: conteúdo divergente, origem, JSON, ação inválida, limite de tentativas e ACK atômico.\n";

foreach ([[200,'<html>Login</html>'],[200,'{"success":false}'],[200,'{"success":"true"}'],[500,'{"success":true}']] as [$code,$body]) fail(fn() => Services\SyncHttpClient::validarResposta($code,$body));
check(Services\SyncHttpClient::validarResposta(200,'{"success":true}')['success'] === true, 'Resposta válida rejeitada.');
echo "OK: HTTP 200 sem JSON de sucesso não confirma sincronização.\n";
