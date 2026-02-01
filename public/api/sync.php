<?php
/**
 * Endpoint da API de Sincronização
 * public/api/sync.php
 */



require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../src/Controllers/SyncController.php';

use Controllers\SyncController;

header('Content-Type: application/json');

$controller = new SyncController();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->receiveSync();
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $controller->sendSync();
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
}