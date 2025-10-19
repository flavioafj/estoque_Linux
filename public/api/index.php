<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config/config.php';
//require_once __DIR__ . '/../../src/autoload.php'; uso futuro
// Caminhos para os arquivos necessários
$controllerPath = __DIR__ . '/../../src/Controllers/ProductController.php';
$sessionPath = __DIR__ . '/../../src/Helpers/Session.php';

use Controllers\ReportController;
use Helpers\Session;
use Controllers\AlertController;
use Controllers\AuditController;

// Verifica se os arquivos existem
if (!file_exists($controllerPath)) {
    error_log("api/index.php: Não encontrou ProductController.php em $controllerPath", 3, __DIR__ . '/../../logs/error.log');
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno do servidor']);
    exit;
}
if (!file_exists($sessionPath)) {
    error_log("api/index.php: Não encontrou Session.php em $sessionPath", 3, __DIR__ . '/../../logs/error.log');
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno do servidor']);
    exit;
}

require_once $controllerPath;
require_once $sessionPath;
require_once __DIR__ . '/../../src/Controllers/AlertController.php';
require_once __DIR__ . '/../../src/Models/Alert.php';


// Inicializa a sessão (se autenticação for necessária)
if (session_status() === PHP_SESSION_NONE) {
    Session::start();
}

// Instancia o controlador
$controller = new ProductController();
$requestUri = $_SERVER['REQUEST_URI'];

// Remove query strings, se houver
$requestUri = strtok($requestUri, '?');

// Normaliza a URI para suportar diferentes bases
$basePath = '/estoque-sorveteria/';
if (strpos($requestUri, $basePath) === 0) {
    $requestUri = substr($requestUri, strlen($basePath));
}

// Define as rotas da API
if ($requestUri === 'api/products') {
    try {
        $products = $controller->productModel->getAll();
        echo json_encode($products);
    } catch (Exception $e) {
        error_log("api/index.php: Erro ao listar produtos: " . $e->getMessage(), 3, __DIR__ . '/../../logs/error.log');
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao buscar produtos']);
    }
} elseif (preg_match('#^/api/product/(\d+)$#', $requestUri, $matches)) {
    $id = (int)$matches[1];
    try {
        $product = $controller->productModel->getById($id);
        if ($product) {
            echo json_encode($product);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Produto não encontrado']);
        }
    } catch (Exception $e) {
        error_log("api/index.php: Erro ao buscar produto ID $id: " . $e->getMessage(), 3, __DIR__ . '/../../logs/error.log');
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao buscar produto']);
    }
} elseif (preg_match('#^/api/reports/custom$#', $requestUri)) {
    $reportController = new ReportController();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code(200);
        $reportController->generate();
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Método não permitido']);
    }
} elseif (preg_match('#^/api/alerts$#', $requestUri)) {
    $controller = new AlertController();
    http_response_code(200);
    echo $controller->getPending();
} elseif (preg_match('#^/api/alerts/mark-read$#', $requestUri)) {
    $controller = new AlertController();
    http_response_code(200);
    echo $controller->markAsRead();
} elseif (preg_match('#^/api/audit$#', $requestUri)) {
    require_once __DIR__ . '/../../src/Controllers/AuditController.php';
    
    $json_data = file_get_contents('php://input');
    $data = json_decode($json_data, true);

    if ($data === null) {
        // Se a decodificação falhar, retorna um erro
        http_response_code(400); // Bad Request
        echo json_encode(['error' => 'JSON inválido na requisição.']);
        exit;
    }
    $controller = new AuditController(); 
    $controller->getLogs($data);
} elseif (preg_match('#^/api/dashboard/summary$#', $requestUri)) {
    require_once __DIR__ . '/../../src/Controllers/DashboardController.php';
    $controller = new Controllers\DashboardController();
    $controller->getSummary();
} elseif (preg_match('#^/api/dashboard/low-stock$#', $requestUri)) {
    require_once __DIR__ . '/../../src/Controllers/DashboardController.php';
    $controller = new Controllers\DashboardController();
    $controller->getLowStock();
} elseif (preg_match('#^/api/dashboard/recent-movements$#', $requestUri)) {
    require_once __DIR__ . '/../../src/Controllers/DashboardController.php';
    $controller = new Controllers\DashboardController();
    $controller->getRecentMovements();
} elseif (preg_match('#^/api/dashboard/stock-turnover$#', $requestUri)) {
    require_once __DIR__ . '/../../src/Controllers/DashboardController.php';
    $controller = new Controllers\DashboardController();
    $controller->getStockTurnover();
 } elseif (preg_match('/\/api\/product\/turnover\/(\d+)/', $requestUri, $matches)) {
    require_once __DIR__ . '/../../src/Controllers/DashboardController.php';
    $controller = new Controllers\DashboardController();
    $controller->getProductTurnover($matches[1]);
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Rota não encontrada']);
}
