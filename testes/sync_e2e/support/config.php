<?php
/** Bootstrap exclusivo da cópia temporária; nunca carrega o .env da aplicação. */
ob_start();
$root = dirname(__DIR__);
if (!is_file($root . '/.pytest-sync-sandbox')) throw new RuntimeException('Sandbox de testes ausente.');
define('SRC_PATH', $root . '/src');
define('LOG_PATH', $root . '/logs');
define('SESSION_NAME', 'pytest_sync');
define('SESSION_LIFETIME', 3600);
define('SYNC_TOKEN', getenv('PYTEST_SYNC_TOKEN'));
define('SYNC_URL', getenv('PYTEST_SYNC_URL'));
ini_set('session.save_path', $root . '/sessions');
require_once $root . '/vendor/autoload.php';
spl_autoload_register(function ($class) {
    $path = SRC_PATH . '/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($path)) require_once $path;
});
// Adapta somente duas construções SQL específicas do MySQL ao SQLite.
final class SyncTestPDO extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $query = str_replace(' FOR UPDATE', '', $query);
        $query = preg_replace('/DELETE FROM estoques WHERE produto_id = \? ORDER BY data_entrada (ASC|DESC) LIMIT \?/',
            'DELETE FROM estoques WHERE id IN (SELECT id FROM estoques WHERE produto_id = ? ORDER BY data_entrada $1, id $1 LIMIT ?)', $query);
        return parent::prepare($query, $options);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false {
        $query = str_replace(' FOR UPDATE', '', $query);
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$args);
    }
}
$pdo = new SyncTestPDO('sqlite:' . getenv('PYTEST_SYNC_DB'), null, null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('PRAGMA busy_timeout = 5000');
$pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));
$pdo->sqliteCreateFunction('JSON_UNQUOTE', fn($value) => $value);
$ref = new ReflectionClass(Models\Database::class);
$db = $ref->newInstanceWithoutConstructor();
$ref->getProperty('connection')->setValue($db, $pdo);
$ref->getProperty('instance')->setValue(null, $db);
Helpers\SyncQueueHelper::initialize($pdo);
