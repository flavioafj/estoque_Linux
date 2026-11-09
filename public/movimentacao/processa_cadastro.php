<?php
require_once __DIR__ . '/../../config/config.php';

use Helpers\Session;
use Models\Database;
use Services\NfeSupplierService;

header('Content-Type: application/json');
Session::start();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Session::isLoggedIn() || !Session::isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acesso negado ou método inválido.']);
    exit;
}
try {
    $data = is_array($_POST['fornecedor_dados'] ?? null) ? $_POST['fornecedor_dados'] : [];
    $data['cnpj'] = $_POST['cnpj'] ?? '';
    $data['razao_social'] = $_POST['razao_social'] ?? '';
    $result = (new NfeSupplierService(Database::getInstance()->getConnection()))->save($data, (int) Session::getUserId());
    echo json_encode(['success' => true, 'message' => 'Fornecedor cadastrado/atualizado e sincronização agendada.', 'fornecedor_id' => $result['id']]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Não foi possível salvar o fornecedor. Verifique os dados e tente novamente.']);
    error_log('Cadastro de fornecedor pela NF-e: ' . get_class($e));
}
