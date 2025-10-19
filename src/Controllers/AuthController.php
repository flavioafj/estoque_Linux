<?php
/**
 * Controlador de Autenticação
 * src/Controllers/AuthController.php
 */

namespace Controllers;

use Models\User;
use Helpers\Session;

class AuthController extends BaseController {

    private $userModel;

    public function __construct() {
        $this->userModel = new User();
    }

    /**
     * Exibe a página de login.
     */
    public function showLoginForm() {
        // Se já estiver logado, redireciona para o dashboard
        if (Session::isLoggedIn()) {
            header('Location: dashboard.php');
            exit();
        }
        $this->render('auth/login', ['title' => 'Login']);
    }

    /**
     * Processa a tentativa de login.
     */
    public function login() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: login.php');
            exit();
        }

        // Validação básica
        $username = trim($_POST['usuario'] ?? '');
        $password = $_POST['senha'] ?? '';

        if (empty($username) || empty($password)) {
            Session::setFlash('error', 'Usuário e senha são obrigatórios.');
            header('Location: login.php');
            exit();
        }

        try {
            // Autenticar
            $userData = $this->userModel->authenticate($username, $password);

            if ($userData) {
                // Sucesso - registrar log
                logMessage("Login realizado com sucesso: {$userData['usuario']}", 'INFO', 'auth.log');
                
                $userModel = $this->userModel; 
                $userModel->logAudit('LOGIN', $userData['id'], null, null);

                Session::login($userData);
                header('Location: dashboard.php');
                exit();
            } else {
                // Falha - registrar tentativa
                logMessage("Tentativa de login falhada: {$username}", 'WARNING', 'auth.log');
                
                Session::setFlash('error', 'Credenciais inválidas. Tente novamente.');
                header('Location: login.php');
                exit();
            }
        } catch (Exception $e) {
            // Erro no sistema
            logMessage("Erro no login: " . $e->getMessage(), 'ERROR', 'auth.log');
            
            Session::setFlash('error', 'Erro interno do sistema. Tente novamente.');
            header('Location: login.php');
            exit();
        }
    }

    /**
     * Realiza o logout do usuário.
     */
    public function logout() {
        $userName = Session::getUserName();
        
        Session::logout();

        $userId = Session::getUserId(); // Assuma que existe; se não, adicione em Session.php
        $userModel = $this->userModel;
        $userModel->logAudit('LOGOUT', $userId, null, null);

        Session::setFlash('success', 'Você foi desconectado com sucesso.');
        
        // Registrar logout
        logMessage("Logout realizado: {$userName}", 'INFO', 'auth.log');
        
        header('Location: login.php');
        exit();
    }
}

// Função para logs
function logMessage($message, $level = 'INFO', $file = 'system.log') {
    $date = date('Y-m-d H:i:s');
    $log = "[$date] [$level] $message" . PHP_EOL;
    
    // Criar pasta de logs se não existir
    if (!is_dir(LOG_PATH)) {
        mkdir(LOG_PATH, 0755, true);
    }
    
    file_put_contents(LOG_PATH . '/' . $file, $log, FILE_APPEND);
}