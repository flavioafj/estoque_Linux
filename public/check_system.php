<?php
/**
 * Verificador do Sistema
 * Rode este script para diagnosticar problemas
 * public/check_system.php
 */

echo "<h1>Verificação do Sistema de Estoque</h1>";

// Verificar PHP
echo "<h2>1. Versão do PHP</h2>";
echo "Versão: " . PHP_VERSION . "<br>";
echo "Suporte PDO: " . (extension_loaded('pdo') ? 'SIM' : 'NÃO') . "<br>";
echo "Suporte PDO MySQL: " . (extension_loaded('pdo_mysql') ? 'SIM' : 'NÃO') . "<br>";

// Verificar estrutura de pastas
echo "<h2>2. Estrutura de Pastas</h2>";
$folders = [
    'config' => '../config/',
    'src' => '../src/',
    'templates' => '../templates/',
    'logs' => '../logs/',
    'temp' => '../temp/',
    'backups' => '../backups/'
];

foreach ($folders as $name => $path) {
    $exists = is_dir($path);
    $writable = $exists ? is_writable($path) : false;
    echo "$name: " . ($exists ? 'EXISTE' : 'NÃO EXISTE');
    if ($exists) {
        echo " | " . ($writable ? 'GRAVÁVEL' : 'NÃO GRAVÁVEL');
    }
    echo "<br>";
    
    // Criar pasta se não existir
    if (!$exists) {
        mkdir($path, 0755, true);
        echo "  -> Pasta $name criada automaticamente<br>";
    }
}

// Verificar arquivos essenciais
echo "<h2>3. Arquivos Essenciais</h2>";
$files = [
    'Config' => '../config/config.php',
    'Database Config' => '../config/database.php',
    'AuthController' => '../src/Controllers/AuthController.php',
    'User Model' => '../src/Models/User.php',
    'Database Model' => '../src/Models/Database.php',
    'Session Helper' => '../src/Helpers/Session.php'
];

foreach ($files as $name => $path) {
    echo "$name: " . (file_exists($path) ? 'EXISTE' : 'NÃO EXISTE') . "<br>";
}

// Teste de conexão com banco
echo "<h2>4. Teste de Conexão com Banco</h2>";
try {
    require_once '../config/config.php';
    
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET . ";port=" . DB_PORT;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, PDO_OPTIONS);
    
    echo "Conexão com banco: <span style='color: green'>SUCESSO</span><br>";
    
    // Verificar se tabelas existem
    $tables = ['usuarios', 'perfis', 'categorias', 'produtos', 'movimentacoes'];
    foreach ($tables as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        $exists = $stmt->rowCount() > 0;
        echo "Tabela $table: " . ($exists ? 'EXISTE' : 'NÃO EXISTE') . "<br>";
    }
    
    // Verificar usuário admin
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM usuarios WHERE usuario = 'admin'");
    $result = $stmt->fetch();
    echo "Usuário admin: " . ($result['count'] > 0 ? 'EXISTE' : 'NÃO EXISTE') . "<br>";
    
} catch (Exception $e) {
    echo "Conexão com banco: <span style='color: red'>ERRO</span><br>";
    echo "Erro: " . $e->getMessage() . "<br>";
}

// Verificar sessões
echo "<h2>5. Teste de Sessões</h2>";
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
echo "Status da sessão: " . (session_status() === PHP_SESSION_ACTIVE ? 'ATIVA' : 'INATIVA') . "<br>";
echo "Session ID: " . session_id() . "<br>";

// Verificar autoload
echo "<h2>6. Teste de Autoload</h2>";
try {
    $testClasses = [
        'Controllers\AuthController',
        'Models\User',
        'Models\Database',
        'Helpers\Session',
        'Middleware\Auth'
    ];
    
    foreach ($testClasses as $class) {
        if (class_exists($class)) {
            echo "$class: <span style='color: green'>CARREGADA</span><br>";
        } else {
            echo "$class: <span style='color: red'>NÃO CARREGADA</span><br>";
        }
    }
} catch (Exception $e) {
    echo "Erro no autoload: " . $e->getMessage() . "<br>";
}

// Verificar configurações
echo "<h2>7. Configurações</h2>";
$configs = [
    'APP_NAME' => defined('APP_NAME') ? APP_NAME : 'NÃO DEFINIDO',
    'BASE_URL' => defined('BASE_URL') ? BASE_URL : 'NÃO DEFINIDO',
    'ENVIRONMENT' => defined('ENVIRONMENT') ? ENVIRONMENT : 'NÃO DEFINIDO',
    'DB_HOST' => defined('DB_HOST') ? DB_HOST : 'NÃO DEFINIDO',
    'DB_NAME' => defined('DB_NAME') ? DB_NAME : 'NÃO DEFINIDO'
];

foreach ($configs as $name => $value) {
    echo "$name: $value<br>";
}

echo "<h2>Verificação Concluída</h2>";
echo "<p><strong>Se tudo estiver OK acima, o sistema deve funcionar normalmente.</strong></p>";
echo "<p><a href='index.php'>Voltar ao Sistema</a></p>";
?>