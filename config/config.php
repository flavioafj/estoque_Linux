<?php
/**
 * Configurações Gerais do Sistema
 * config/config.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

// Carrega o arquivo .env
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();


// Iniciar sessão se ainda não iniciada
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Timezone
date_default_timezone_set('America/Sao_Paulo');

// IMPORTANTE: Carregar arquivo de banco de dados PRIMEIRO (define ENVIRONMENT)
require_once __DIR__ . '/database.php';

// Configurações de erro (agora ENVIRONMENT já está definido)
if (ENVIRONMENT === 'local') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Caminhos do sistema
define('ROOT_PATH', dirname(__DIR__));
define('CONFIG_PATH', ROOT_PATH . '/config');
define('SRC_PATH', ROOT_PATH . '/src');
define('PUBLIC_PATH', ROOT_PATH . '/public');
define('TEMPLATE_PATH', ROOT_PATH . '/templates');
define('LOG_PATH', ROOT_PATH . '/logs');
define('TEMP_PATH', ROOT_PATH . '/temp');
define('UPLOAD_PATH', PUBLIC_PATH . '/uploads');

// URLs do sistema - Corrigido para funcionar corretamente
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];

// Detectar se estamos na pasta public ou na raiz do projeto
$scriptName = $_SERVER['SCRIPT_NAME'];
$basePath = '';

if (strpos($scriptName, '/public/') !== false) {
    // Estamos na pasta public, então o base path é até public
    $basePath = dirname($scriptName);
} else {
    // Estamos na raiz, então precisamos adicionar /public
    $basePath = rtrim(dirname($scriptName), '/') . '/public';
}

define('BASE_URL', $protocol . '://' . $host . $basePath);
define('ASSETS_URL', BASE_URL . '/assets');
define('CSS_URL', ASSETS_URL . '/css');
define('JS_URL', ASSETS_URL . '/js');
define('IMG_URL', ASSETS_URL . '/images');

// Configurações da aplicação
define('APP_NAME', 'Sistema de Estoque - Sorveteria');
define('APP_VERSION', '1.0.0');
define('APP_AUTHOR', 'Sua Empresa');
define('APP_EMAIL', 'contato@sorveteria.com');

// Configurações de sessão
define('SESSION_LIFETIME', 3600); // 1 hora
define('SESSION_NAME', 'sorveteria_session');

// Configurações de segurança
define('CSRF_TOKEN_NAME', 'csrf_token');
define('PASSWORD_MIN_LENGTH', 6);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutos

// Configurações de upload
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10MB
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'xml']);

// Configurações de paginação
define('ITEMS_PER_PAGE', 20);

// Configurações de log
define('LOG_LEVEL', 'DEBUG'); // DEBUG, INFO, WARNING, ERROR, CRITICAL
define('LOG_ROTATION', 'daily'); // daily, weekly, monthly

// Autoload de classes
spl_autoload_register(function ($class) {
    $file = SRC_PATH . '/' . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Função auxiliar para debug (verificar se já não foi declarada)
if (!function_exists('dd')) {
    function dd($data, $die = true) {
        echo '<pre>';
        var_dump($data);
        echo '</pre>';
        if ($die) die();
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