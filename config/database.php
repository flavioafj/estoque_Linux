<?php
/**
 * Configuração do Banco de Dados
 * config/database.php
 */

require_once __DIR__ . '/../vendor/autoload.php';


// Detectar ambiente (local ou produção)
$is_local = ($_SERVER['SERVER_NAME'] === 'localhost' || $_SERVER['SERVER_NAME'] === '127.0.0.1');

if ($is_local) {
    // Configurações para ambiente LOCAL
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'sorveteria_estoque');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_PORT', 3306);
    define('DB_CHARSET', 'utf8mb4');
    define('ENVIRONMENT', 'local');
} else {
    // Configurações para ambiente WEB/PRODUÇÃO
    define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
    define('DB_NAME', getenv('DB_NAME') ?: 'sorveteria_estoque');
    define('DB_USER', getenv('DB_USER') ?: 'admin');
    define('DB_PASS', getenv('DB_PASSWORD') ?: getenv('DB_PASS') ?: 'rosapapi2');
    define('DB_PORT', getenv('DB_PORT') ?: 3306);
    define('DB_CHARSET', 'utf8mb4');
    define('ENVIRONMENT', 'production');
}

// Configurações PDO
define('PDO_OPTIONS', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
]);

// Configurações de sincronização
define('SYNC_ENABLED', true);
define('SYNC_INTERVAL', 300); // 5 minutos em segundos
define('SYNC_URL', getenv('SYNC_URL') ?: 'https://seu-servidor.com/api/sync');
define('SYNC_TOKEN', getenv('SYNC_TOKEN') ?: 'seu_token_secreto');
if (ENVIRONMENT === 'production' && (empty(SYNC_URL) || empty(SYNC_TOKEN))) {
        die('Erro: SYNC_URL e SYNC_TOKEN são obrigatórios em produção.');
}

// Configurações de backup
define('BACKUP_ENABLED', true);
define('BACKUP_PATH', dirname(__DIR__) . '/backups/');
define('BACKUP_DAYS_TO_KEEP', 30);

