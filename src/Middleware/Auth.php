<?php
/**
 * Middleware de Autenticação
 * src/Middleware/Auth.php
 */

namespace Middleware;

use Helpers\Session;

class Auth {
    
    /**
     * Verifica se o usuário está autenticado.
     * Se não estiver, define uma mensagem de erro e redireciona para a página de login.
     * 
     * @param string $redirect_url URL para redirecionar caso não esteja logado.
     */
    public static function check($redirect_url = 'login.php') {
        Session::start();
        
        if (!Session::isLoggedIn()) {
            Session::setFlash('error', 'Acesso negado. Por favor, faça login para continuar.');
            header('Location: ' . $redirect_url);
            exit();
        }

        // Opcional: Verificar timeout da sessão a cada requisição
        if (!Session::checkTimeout()) {
            header('Location: ' . $redirect_url);
            exit();
        }
    }

    /**
     * Verifica se o usuário é Administrador.
     * Se não for, redireciona para o dashboard com uma mensagem de erro.
     * 
     * @param string $redirect_url URL para redirecionar caso não seja admin.
     */
    public static function checkAdmin($redirect_url = 'dashboard.php') {
        self::check(); // Primeiro, garante que está logado
        
        if (!Session::isAdmin()) {
            Session::setFlash('error', 'Você não tem permissão para acessar esta página.');
            header('Location: ' . $redirect_url);
            exit();
        }
    }

    public static function checkApiToken() {
        $headers = getallheaders();
        $token = $headers['Authorization'] ?? '';
        if ($token !== 'Bearer ' . SYNC_TOKEN) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Token inválido']);
            exit;
        }
    }
}