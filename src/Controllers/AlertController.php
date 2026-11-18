<?php
namespace Controllers;

use Models\Alert;
use Helpers\Session;

class AlertController extends BaseController {
    private $alertModel;

    public function __construct() {
        //parent::__construct();
        $this->alertModel = new Alert();
    }

    public function getPending() {
        if (!Session::isLoggedIn()) {
            $this->jsonResponse(['error' => 'Não autorizado'], 401);
            return;
        }
        $alerts = $this->alertModel->getPendingAlerts();
        return json_encode($alerts);
    }

    public function markAsRead() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['error' => 'Método não permitido'], 405);
            return;
        }
        if (!$_SESSION['logged_in'] || $_SESSION['user_profile'] != 1) {
            $this->jsonResponse(['error' => 'Permissão negada'], 403);
            return;
        }

        $alert_id = filter_input(INPUT_POST, 'alert_id', FILTER_VALIDATE_INT);
        $usuario_id = $_SESSION['user_id'];
        if ($alert_id && $usuario_id) {
            $this->alertModel->markAsRead($alert_id, $usuario_id);
            return json_encode(['success' => true]);
        } else {
            return json_encode(['error' => 'Dados inválidos']);
        }
    }

    public function markAllAsRead() {
        $usuarioId = Session::getUserId();
        $token = $_POST['csrf_token'] ?? '';
        if (!Session::isLoggedIn() || !Session::isAdmin() || !$usuarioId
            || !is_string($token) || !Session::validateCSRFToken($token)) {
            http_response_code(403);
            echo 'Permissão negada.';
            return;
        }

        try {
            $updated = $this->alertModel->markAllAsRead((int) $usuarioId);
        } catch (\PDOException $e) {
            error_log('AlertController::markAllAsRead: ' . $e->getMessage());
            $updated = false;
        }

        if ($updated) {
            Session::setFlash('success', 'Todos os alertas pendentes foram marcados como lidos.');
        } else {
            Session::setFlash('danger', 'Não foi possível marcar todos os alertas como lidos.');
        }

        header('Location: /alerts', true, 303);
    }

    public function renderAlerts() {
        if (!Session::isLoggedIn()) {
            header('Location: /login');
            exit;
        }
        $alerts = $this->alertModel->getPendingAlerts();
        $is_admin = (Session::get('perfil_id') == 1);
        $this->render('alerts_estoque', ['alerts' => $alerts, 'is_admin' => $is_admin]);
    }
}