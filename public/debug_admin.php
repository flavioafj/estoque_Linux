<?php
/**
 * Debug e Correção do Usuário Admin
 * public/debug_admin.php
 */

// Configuração direta do banco (sem dependências)
$db_config = [
    'host' => 'localhost',
    'port' => 3306,
    'name' => 'sorveteria_estoque',
    'user' => 'root',
    'pass' => '',
    'charset' => 'utf8mb4'
];

echo "<h1>Debug do Usuário Admin</h1>";

try {
    // Conectar com o banco
    $dsn = "mysql:host={$db_config['host']};dbname={$db_config['name']};port={$db_config['port']};charset={$db_config['charset']}";
    $pdo = new PDO($dsn, $db_config['user'], $db_config['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    echo "<span style='color: green'>✓ Conectado com o banco</span><br><br>";
    
} catch (PDOException $e) {
    echo "<span style='color: red'>✗ Erro de conexão: " . $e->getMessage() . "</span><br>";
    die();
}

echo "<h2>1. Verificando Usuário Admin Atual</h2>";

// Buscar usuário admin atual
$stmt = $pdo->prepare("SELECT u.*, p.nome as perfil_nome 
                      FROM usuarios u 
                      LEFT JOIN perfis p ON u.perfil_id = p.id 
                      WHERE u.usuario = 'admin'");
$stmt->execute();
$admin = $stmt->fetch();

if ($admin) {
    echo "<span style='color: green'>✓ Usuário admin encontrado</span><br>";
    echo "<pre>";
    echo "ID: " . $admin['id'] . "\n";
    echo "Nome: " . $admin['nome_completo'] . "\n";
    echo "Email: " . $admin['email'] . "\n";
    echo "Usuário: " . $admin['usuario'] . "\n";
    echo "Ativo: " . ($admin['ativo'] ? 'SIM' : 'NÃO') . "\n";
    echo "Perfil ID: " . $admin['perfil_id'] . "\n";
    echo "Perfil Nome: " . ($admin['perfil_nome'] ?: 'NÃO ENCONTRADO') . "\n";
    echo "Hash da Senha: " . substr($admin['senha'], 0, 50) . "...\n";
    echo "Criado em: " . $admin['criado_em'] . "\n";
    echo "</pre>";
} else {
    echo "<span style='color: red'>✗ Usuário admin NÃO encontrado</span><br>";
}

echo "<h2>2. Teste de Senha</h2>";

if ($admin) {
    $senha_teste = 'admin123';
    if (password_verify($senha_teste, $admin['senha'])) {
        echo "<span style='color: green'>✓ Senha 'admin123' está CORRETA</span><br>";
    } else {
        echo "<span style='color: red'>✗ Senha 'admin123' está INCORRETA</span><br>";
        echo "Vamos recriar a senha...<br>";
        
        $nova_senha = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE usuarios SET senha = :senha WHERE id = :id");
        $stmt->execute([':senha' => $nova_senha, ':id' => $admin['id']]);
        
        echo "<span style='color: green'>✓ Senha atualizada com sucesso!</span><br>";
    }
} else {
    echo "<span style='color: orange'>⚠ Não é possível testar senha - usuário não existe</span><br>";
}

echo "<h2>3. Verificando Perfis</h2>";

$stmt = $pdo->query("SELECT * FROM perfis ORDER BY id");
$perfis = $stmt->fetchAll();

if ($perfis) {
    echo "<span style='color: green'>✓ Perfis encontrados:</span><br>";
    foreach ($perfis as $perfil) {
        echo "ID: {$perfil['id']} - Nome: {$perfil['nome']}<br>";
    }
} else {
    echo "<span style='color: red'>✗ Nenhum perfil encontrado</span><br>";
    echo "Criando perfis padrão...<br>";
    
    $stmt = $pdo->prepare("INSERT INTO perfis (nome, descricao) VALUES (?, ?)");
    $stmt->execute(['Administrador', 'Acesso total ao sistema']);
    $stmt->execute(['Operador', 'Acesso limitado para registro de saídas']);
    
    echo "<span style='color: green'>✓ Perfis criados!</span><br>";
}

echo "<h2>4. Recriando Usuário Admin (se necessário)</h2>";

if (!$admin || !$admin['perfil_nome']) {
    echo "<span style='color: orange'>⚠ Recriando usuário admin...</span><br>";
    
    // Deletar admin existente (se houver)
    if ($admin) {
        $pdo->prepare("DELETE FROM usuarios WHERE id = :id")->execute([':id' => $admin['id']]);
    }
    
    // Criar novo admin
    $nova_senha = password_hash('admin123', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO usuarios (nome_completo, email, usuario, senha, perfil_id, ativo) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        'Administrador do Sistema',
        'admin@sorveteria.com',
        'admin',
        $nova_senha,
        1,
        1
    ]);
    
    echo "<span style='color: green'>✓ Usuário admin recriado!</span><br>";
}

echo "<h2>5. Teste Final de Autenticação</h2>";

// Buscar admin novamente
$stmt = $pdo->prepare("SELECT u.*, p.nome as perfil_nome 
                      FROM usuarios u 
                      JOIN perfis p ON u.perfil_id = p.id 
                      WHERE u.usuario = 'admin' AND u.ativo = 1");
$stmt->execute();
$admin_final = $stmt->fetch();

if ($admin_final && password_verify('admin123', $admin_final['senha'])) {
    echo "<span style='color: green; font-size: 18px; font-weight: bold'>✓ SUCESSO! Usuário admin configurado corretamente!</span><br><br>";
    echo "<div style='background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; border-radius: 5px;'>";
    echo "<strong>Credenciais para login:</strong><br>";
    echo "Usuário: <code>admin</code><br>";
    echo "Senha: <code>admin123</code><br>";
    echo "</div><br>";
    echo "<a href='login.php' style='background: #28a745; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Testar Login</a>";
} else {
    echo "<span style='color: red; font-size: 18px; font-weight: bold'>✗ ERRO! Problema na configuração do admin</span><br>";
}

echo "<h2>6. Informações Extras</h2>";
echo "Hash de teste para 'admin123': " . password_hash('admin123', PASSWORD_DEFAULT) . "<br>";
echo "Versão PHP: " . PHP_VERSION . "<br>";
echo "Data atual: " . date('Y-m-d H:i:s') . "<br>";

echo "<br><p><a href='login.php'>← Voltar para Login</a> | <a href='check_system.php'>Verificação Completa do Sistema</a></p>";
?>