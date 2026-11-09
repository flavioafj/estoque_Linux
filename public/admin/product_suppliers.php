<?php
require_once __DIR__ . '/../../config/config.php';

use Helpers\Session;
use Models\Database;
use Services\ProductSupplierService;

header('Content-Type: application/json; charset=utf-8');
Session::start();
if (!Session::isLoggedIn() || !Session::isAdmin()) {
    http_response_code(403); echo json_encode(['success' => false, 'message' => 'Acesso negado.']); exit;
}
\Middleware\Auth::check();
try {
    $pdo = Database::getInstance()->getConnection();
    $service = new ProductSupplierService($pdo);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (!is_string($token) || !Session::validateCSRFToken($token)) throw new RuntimeException('Formulário expirado. Atualize a página.');
        $product = filter_var($_POST['produto_id'] ?? null, FILTER_VALIDATE_INT);
        $supplier = filter_var($_POST['fornecedor_id'] ?? null, FILTER_VALIDATE_INT);
        $action = $_POST['action'] ?? '';
        if (!$product || !$supplier || !in_array($action, ['add', 'remove', 'principal'], true)) throw new RuntimeException('Operação inválida.');
        $service->set($product, $supplier, $action !== 'remove', (int) Session::getUserId(), $action === 'principal');
        echo json_encode(['success' => true]);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $side = isset($_GET['produto_id']) ? 'produto' : 'fornecedor';
        $id = filter_var($_GET[$side . '_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id <= 0) throw new RuntimeException('Identificador inválido.');
        $choices = $side === 'produto'
            ? $pdo->query('SELECT id, razao_social AS nome FROM fornecedores WHERE ativo = 1 ORDER BY razao_social')->fetchAll(PDO::FETCH_ASSOC)
            : $pdo->query('SELECT id, nome FROM produtos WHERE ativo = 1 ORDER BY nome')->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $service->listFor($side, $id), 'choices' => $choices], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } else { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Método inválido.']); }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : 'Não foi possível atualizar os vínculos.']);
}
