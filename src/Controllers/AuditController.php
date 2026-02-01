<?php
/**
 * Controlador de Auditoria
 * src/Controllers/AuditController.php
 */

namespace Controllers;

use Models\Audit;
use Middleware\Auth;

class AuditController extends BaseController {
    private $auditModel;

    public function __construct() {
        Auth::checkAdmin(); // Restringe a administradores
        $this->auditModel = new Audit();
    }

    public function getLogs($data = []) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Método não permitido']);
            exit;
        }

        $filters = $data;
        $logs = $this->auditModel->getLogs($filters);
        echo json_encode($logs);
    }
}