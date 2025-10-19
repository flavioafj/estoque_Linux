<?php
/**
 * Setup Automático do Banco de Dados
 * public/setup.php
 */

set_time_limit(120); // 2 minutos para execução

echo "<h1>Setup Automático do Sistema</h1>";

// Configurações do banco (direto aqui para evitar problemas)
$db_config = [
    'host' => 'localhost',
    'port' => 3306,
    'name' => 'sorveteria_estoque',
    'user' => 'root',
    'pass' => '',
    'charset' => 'utf8mb4'
];

echo "<h2>1. Testando Conexão com MySQL</h2>";

try {
    // Conectar sem especificar banco
    $dsn = "mysql:host={$db_config['host']};port={$db_config['port']};charset={$db_config['charset']}";
    $pdo = new PDO($dsn, $db_config['user'], $db_config['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    echo "<span style='color: green'>✓ Conexão com MySQL estabelecida</span><br>";
    
    // Verificar se o banco existe
    $databases = $pdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array($db_config['name'], $databases)) {
        echo "<span style='color: orange'>⚠ Criando banco de dados '{$db_config['name']}'...</span><br>";
        $pdo->exec("CREATE DATABASE {$db_config['name']} CHARACTER SET {$db_config['charset']} COLLATE {$db_config['charset']}_unicode_ci");
        echo "<span style='color: green'>✓ Banco criado com sucesso</span><br>";
    } else {
        echo "<span style='color: green'>✓ Banco '{$db_config['name']}' já existe</span><br>";
    }
    
} catch (PDOException $e) {
    echo "<span style='color: red'>✗ Erro na conexão: " . $e->getMessage() . "</span><br>";
    echo "<strong>Verifique se o MySQL está rodando no XAMPP!</strong><br>";
    die();
}

echo "<br><h2>2. Conectando com o Banco Específico</h2>";

try {
    // Conectar com o banco específico
    $dsn = "mysql:host={$db_config['host']};dbname={$db_config['name']};port={$db_config['port']};charset={$db_config['charset']}";
    $pdo = new PDO($dsn, $db_config['user'], $db_config['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    echo "<span style='color: green'>✓ Conectado com o banco '{$db_config['name']}'</span><br>";
    
} catch (PDOException $e) {
    echo "<span style='color: red'>✗ Erro ao conectar com banco: " . $e->getMessage() . "</span><br>";
    die();
}

echo "<br><h2>3. Verificando e Criando Tabelas</h2>";

// SQL do schema (versão simplificada para teste)
$schema_sql = "
-- Criar tabela perfis
CREATE TABLE IF NOT EXISTS perfis (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nome VARCHAR(50) NOT NULL UNIQUE,
    descricao TEXT,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Criar tabela usuarios
CREATE TABLE IF NOT EXISTS usuarios (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nome_completo VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    usuario VARCHAR(50) NOT NULL UNIQUE,
    senha VARCHAR(255) NOT NULL,
    perfil_id INT NOT NULL,
    ativo BOOLEAN DEFAULT TRUE,
    ultimo_acesso DATETIME,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (perfil_id) REFERENCES perfis(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

try {
    // Executar criação das tabelas
    $pdo->exec($schema_sql);
    echo "<span style='color: green'>✓ Tabelas criadas/verificadas</span><br>";
    
    // Verificar tabelas criadas
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "Tabelas no banco: " . implode(', ', $tables) . "<br>";
    
} catch (PDOException $e) {
    echo "<span style='color: red'>✗ Erro ao criar tabelas: " . $e->getMessage() . "</span><br>";
}

echo "<br><h2>4. Inserindo Dados Iniciais</h2>";

try {
    // Inserir perfis se não existirem
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM perfis");
    $count = $stmt->fetch()['count'];
    
    if ($count == 0) {
        echo "<span style='color: orange'>⚠ Inserindo perfis padrão...</span><br>";
        $pdo->exec("INSERT INTO perfis (nome, descricao) VALUES 
                   ('Administrador', 'Acesso total ao sistema'),
                   ('Operador', 'Acesso limitado para registro de saídas')");
        echo "<span style='color: green'>✓ Perfis inseridos</span><br>";
    } else {
        echo "<span style='color: green'>✓ Perfis já existem</span><br>";
    }
    
    // Inserir usuário admin se não existir
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM usuarios WHERE usuario = 'admin'");
    $stmt->execute();
    $count = $stmt->fetch()['count'];
    
    if ($count == 0) {
        echo "<span style='color: orange'>⚠ Criando usuário admin...</span><br>";
        $senha = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO usuarios (nome_completo, email, usuario, senha, perfil_id, ativo) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            'Administrador do Sistema',
            'admin@sorveteria.com',
            'admin',
            $senha,
            1,
            1
        ]);
        echo "<span style='color: green'>✓ Usuário admin criado</span><br>";
    } else {
        echo "<span style='color: green'>✓ Usuário admin já existe</span><br>";
    }
    
} catch (PDOException $e) {
    echo "<span style='color: red'>✗ Erro ao inserir dados: " . $e->getMessage() . "</span><br>";
}

echo "<br><h2>5. Teste Final</h2>";

try {
    // Testar autenticação
    $stmt = $pdo->prepare("SELECT u.*, p.nome as perfil_nome FROM usuarios u JOIN perfis p ON u.perfil_id = p.id WHERE u.usuario = 'admin'");
    $stmt->execute();
    $admin = $stmt->fetch();
    
    if ($admin && password_verify('admin123', $admin['senha'])) {
        echo "<span style='color: green'>✓ SETUP CONCLUÍDO COM SUCESSO!</span><br>";
        echo "<br><strong>Credenciais para teste:</strong><br>";
        echo "Usuário: admin<br>";
        echo "Senha: admin123<br>";
        echo "<br><a href='login.php' style='background: #28a745; color: white; padding: 10px 15px; text-decoration: none; border-radius: 5px;'>Ir para Login</a>";
    } else {
        echo "<span style='color: red'>✗ Problema na configuração do usuário admin</span><br>";
    }
    
} catch (PDOException $e) {
    echo "<span style='color: red'>✗ Erro no teste final: " . $e->getMessage() . "</span><br>";
}

echo "<br><br><h2>Próximos Passos</h2>";
echo "<p>Se tudo deu certo acima:</p>";
echo "<ol>";
echo "<li>Substitua o arquivo <code>config/database.php</code> pela versão corrigida</li>";
echo "<li>Acesse o sistema normalmente</li>";
echo "<li>Execute o arquivo completo <code>database_schema.sql</code> para criar todas as tabelas</li>";
echo "</ol>";

echo "<br><p><a href='db_debug.php'>Executar debug completo do banco</a></p>";


var_dump($userModel->debugAdmin());
?>