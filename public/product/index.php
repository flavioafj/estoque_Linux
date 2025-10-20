<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config/config.php';

// Caminhos para os arquivos necessários
$controllerPath = __DIR__ . '/../../src/Controllers/ProductController.php';
$sessionPath = __DIR__ . '/../../src/Helpers/Session.php';

// Verifica se os arquivos existem
if (!file_exists($controllerPath)) {
    error_log("product/index.php: Não encontrou ProductController.php em $controllerPath", 3, __DIR__ . '/../../logs/error.log');
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno do servidor']);
    exit;
}
if (!file_exists($sessionPath)) {
    error_log("product/index.php: Não encontrou Session.php em $sessionPath", 3, __DIR__ . '/../../logs/error.log');
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno do servidor']);
    exit;
}

require_once $controllerPath;
//require_once $sessionPath;

//use Controllers\ProductController;
use Helpers\Session;

// Inicializa a sessão
if (session_status() === PHP_SESSION_NONE) {
    Session::start();
}

// Verifica autenticação
if (!Session::isLoggedIn() || !Session::isAdmin()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autorizado']);
    header('Location: /estoque-sorveteria/login.php');
    exit;
}

// Instancia o controlador
$controller = new ProductController();
$requestUri = $_SERVER['REQUEST_URI'];
$requestMethod = $_SERVER['REQUEST_METHOD'];

// Remove query strings, se houver
$requestUri = strtok($requestUri, '?');

// Log da URI recebida para depuração
error_log("product/index.php: Request URI recebida: $requestUri, Método: $requestMethod", 3, __DIR__ . '/../../logs/product.log');

// Normaliza a URI
$basePath = '/estoque-sorveteria/product/';
if (strpos($requestUri, $basePath) === 0) {
    $requestUri = substr($requestUri, strlen($basePath));
}
error_log("product/index.php: Request URI normalizada: $requestUri", 3, __DIR__ . '/../../logs/product.log');


// Verifica se é POST
if ($requestMethod !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    header('Location: /admin/products.php');
    exit;
}

// Define as rotas
if ($requestUri === '/product/store') {
    try {
        $data = [
            'nome' => $_POST['nome'] ?? null,
            'codigo' => $_POST['codigo'] ?? null,
            'categoria_id' => $_POST['categoria_id'] ?? null,
            'estoque_atual' => $_POST['estoque_atual'] ?? 0,
            'estoque_minimo' => $_POST['estoque_minimo'] ?? null,
            'preco_venda' => $_POST['preco_venda'] ?? null,
            'descricao' => $_POST['descricao'] ?? null,
            'ativo' => isset($_POST['ativo']) ? 1 : 0,
            'unidade_medida_id' => $_POST['unidade_medida_id'] ?? null,
            'estoque_maximo' => $_POST['estoque_maximo'] ?? 100,
            'preco_custo' => $_POST['preco_custo'] ?? 0,
            'margem_lucro' => $_POST['margem_lucro'] ?? 100,
            'fornecedor_principal_id' => $_POST['fornecedor_principal_id'] ?? null
        ];
        
        $controller->store($data);
        
    } catch (Exception $e) {
        error_log("product/index.php: Erro ao criar produto: " . $e->getMessage(), 3, __DIR__ . '/../../logs/error.log');
        $_SESSION['errors'] = ['general' => ['Erro ao criar produto: ' . $e->getMessage()]];
        //header('Location: /admin/products.php');
        echo json_encode(['error' => 'Erro ao criar produto: ' . $e->getMessage()]);
        exit;
    }
} elseif (preg_match('#^/product/update/(\d+)$#', $requestUri, $matches)) {
    $id = (int)$matches[1];
    try {
        $data = [
            'nome' => $_POST['nome'] ?? null,
            'codigo' => $_POST['codigo'] ?? null,
            'categoria_id' => $_POST['categoria_id'] ?? null,
            'estoque_atual' => $_POST['estoque_atual'] ?? null,
            'estoque_minimo' => $_POST['estoque_minimo'] ?? null,
            'preco_venda' => $_POST['preco_venda'] ?? null,
            'descricao' => $_POST['descricao'] ?? null,
            'ativo' => isset($_POST['ativo']) ? 1 : 0,
            'unidade_medida_id' => $_POST['unidade_medida_id'] ?? null,
            'estoque_maximo' => $_POST['estoque_maximo'] ?? null,
            'preco_custo' => $_POST['preco_custo'] ?? null,
            'margem_lucro' => $_POST['margem_lucro'] ?? null,
            'fornecedor_principal_id' => $_POST['fornecedor_principal_id'] ?? null
        ];
        $controller->update($id, $data);
    } catch (Exception $e) {
        error_log("product/index.php: Erro ao atualizar produto ID $id: " . $e->getMessage(), 3, __DIR__ . '/../../logs/error.log');
        $_SESSION['errors'] = ['general' => ['Erro ao atualizar produto: ' . $e->getMessage()]];
        header('Location: /admin/products.php');
        exit;
    }
} elseif (preg_match('#^/product/destroy/(\d+)$#', $requestUri, $matches)) {
    $id = (int)$matches[1];
    try {
        $controller->destroy($id);
    } catch (Exception $e) {
        error_log("product/index.php: Erro ao desativar produto ID $id: " . $e->getMessage(), 3, __DIR__ . '/../../logs/error.log');
        $_SESSION['errors'] = ['general' => ['Erro ao desativar produto: ' . $e->getMessage()]];
        header('Location: /admin/products.php');
        exit;
    }
} elseif (preg_match('#^/product/reactivate/(\d+)$#', $requestUri, $matches)) {
    $id = (int)$matches[1];
    try {
        $controller->reactivate($id);
    } catch (Exception $e) {
        error_log("product/index.php: Erro ao reativar produto ID $id: " . $e->getMessage(), 3, __DIR__ . '/../../logs/error.log');
        $_SESSION['errors'] = ['general' => ['Erro ao reativar produto: ' . $e->getMessage()]];
        // O controller já faz o redirecionamento, mas por segurança:
        header('Location: /admin/products.php?view=inactive');
        exit;
    }
} else {
    error_log("product/index.php: Rota não encontrada: $requestUri", 3, __DIR__ . '/../../logs/product.log');
    http_response_code(404);
    echo json_encode(['error' => 'Rota não encontrada']);
    header('Location: /admin/products.php');
    exit;
}