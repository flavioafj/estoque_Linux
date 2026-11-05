<?php
// Este roteador existe apenas dentro da cópia temporária criada pelo pytest.
require_once __DIR__ . '/config/config.php';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/health') { echo 'ready'; return; }
if ($path === '/api/sync.php') { require __DIR__ . '/public/api/sync.php'; return; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' ||
    !hash_equals(SYNC_TOKEN, $_SERVER['HTTP_X_TEST_TOKEN'] ?? '')) {
    http_response_code(403); return;
}
$_POST = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
$_FILES = [];
Helpers\Session::start();
$_SESSION = ['user_id' => 1, 'user_profile' => 1, 'logged_in' => true, 'login_time' => time()];
$routes = [
    '/actions/product' => 'public/product/index.php',
    '/actions/inactivate' => 'public/product/index.php',
    '/actions/reactivate' => 'public/product/index.php',
    '/actions/entry' => 'public/movimentacao/registrar_entrada.php',
    '/actions/nfe' => 'public/movimentacao/registrar_entrada_nfe.php',
    '/actions/inventory' => 'public/inventory_confirm.php',
    '/actions/undo' => 'public/inventory_undo.php',
];
if ($path === '/actions/exit') {
    $_SESSION['user_profile'] = 2;
    (new Controllers\ExitController())->saidaDireta();
} elseif ($path === '/actions/admin_exit') {
    require_once SRC_PATH . '/Controllers/MovimentacaoController.php';
    (new App\Controllers\MovimentacaoController())->registrarSaida($_POST);
} elseif (isset($routes[$path])) {
    if ($path === '/actions/product') $_SERVER['REQUEST_URI'] = '/product/store';
    if ($path === '/actions/inactivate') $_SERVER['REQUEST_URI'] = '/product/destroy/' . (int) $_POST['id'];
    if ($path === '/actions/reactivate') $_SERVER['REQUEST_URI'] = '/product/reactivate/' . (int) $_POST['id'];
    if ($path === '/actions/inventory') $_SESSION['user_profile'] = 3;
    $entry = __DIR__ . '/' . $routes[$path];
    chdir(dirname($entry));
    require $entry;
} else {
    http_response_code(404);
}
