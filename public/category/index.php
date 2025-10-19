<?php
// Define o cabeçalho de resposta, embora os redirecionamentos sejam mais comuns aqui.
header('Content-Type: application/json; charset=utf-8');

// Carrega a configuração principal e o autoloader
require_once __DIR__ . '/../../config/config.php';

// Caminhos para os arquivos necessários
$controllerPath = __DIR__ . '/../../src/Controllers/CategoryController.php';
$sessionPath = __DIR__ . '/../../src/Helpers/Session.php';

// Verifica se os arquivos essenciais existem
if (!file_exists($controllerPath) || !file_exists($sessionPath)) {
    error_log("category/index.php: Arquivo de controlador ou sessão não encontrado.", 3, __DIR__ . '/../../logs/error.log');
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno do servidor.']);
    exit;
}

require_once $controllerPath;
require_once $sessionPath;

use Helpers\Session;

// Inicializa a sessão
if (session_status() === PHP_SESSION_NONE) {
    Session::start();
}

// Pega a URI e o método da requisição
$requestUri = $_SERVER['REQUEST_URI'];
$requestMethod = $_SERVER['REQUEST_METHOD'];

// Remove query strings da URI (ex: ?view=inactive)
$requestUri = strtok($requestUri, '?');

// Normaliza a URI removendo o caminho base do projeto
$basePath = '/category/';
if (strpos($requestUri, $basePath) === 0) {
    $action = substr($requestUri, strlen($basePath));
} else {
    $action = '';
}

// Instancia o controlador. O construtor já verifica se o usuário é admin.
try {
    $controller = new CategoryController();
} catch (Exception $e) {
    Session::set('errors', ['auth' => [$e->getMessage()]]);
    header('Location: /admin/login.php');
    exit;
}


// Apenas métodos POST são permitidos para modificar dados
if ($requestMethod !== 'POST') {
    http_response_code(405); // Method Not Allowed
    Session::set('errors', ['request' => ['Método não permitido.']]);
    header('Location: /admin/categories.php');
    exit;
}

// Roteamento baseado na ação
try {
    if ($action === 'store') {
        $controller->store();
    } elseif (preg_match('#^update/(\d+)$#', $action, $matches)) {
        $id = (int)$matches[1];
        $controller->update($id);
    } elseif (preg_match('#^destroy/(\d+)$#', $action, $matches)) {
        $id = (int)$matches[1];
        $controller->destroy($id);
    } elseif (preg_match('#^reactivate/(\d+)$#', $action, $matches)) {
        $id = (int)$matches[1];
        $controller->reactivate($id);
    } else {
        http_response_code(404); // Not Found
        throw new Exception("Rota de categoria não encontrada: $action");
    }
} catch (Exception $e) {
    error_log("Erro no roteador de categoria: " . $e->getMessage(), 3, __DIR__ . '/../../logs/error.log');
    Session::set('errors', ['general' => ['Ocorreu um erro: ' . $e->getMessage()]]);
    header('Location: /admin/categories.php');
    exit;
}

?>