<?php
/**
 * Script para Corrigir Permissões e Debug Completo
 * public/fix_and_test.php
 */

echo "<h1>Correção de Permissões e Debug de Login</h1>";

// Configurações
$base_path = dirname(__DIR__);
$folders_to_fix = [
    'config',
    'templates', 
    'logs',
    'temp',
    'backups'
];

echo "<h2>1. Corrigindo Permissões das Pastas</h2>";

foreach ($folders_to_fix as $folder) {
    $folder_path = $base_path . '/' . $folder;
    
    if (is_dir($folder_path)) {
        $before = is_writable($folder_path);
        chmod($folder_path, 0755);
        $after = is_writable($folder_path);
        
        echo "Pasta '$folder': ";
        echo "Antes: " . ($before ? 'GRAVÁVEL' : 'NÃO GRAVÁVEL') . " → ";
        echo "Depois: " . ($after ? 'GRAVÁVEL' : 'NÃO GRAVÁVEL');
        echo "<br>";
    }
}

// Carregar configurações para teste
require_once '../config/config.php';

echo "<br><h2>2. Teste Direto de Conexão e Usuário Admin</h2>";

try {
    // Usar a classe Database do sistema
    $db = \Models\Database::getInstance();
    echo "<span style='color: green'>✓ Conexão com Database class: SUCESSO</span><br>";
    
    // Testar usuário admin diretamente
    $sql = "SELECT u.*, p.nome as perfil_nome 
            FROM usuarios u 
            JOIN perfis p ON u.perfil_id = p.id 
            WHERE u.usuario = :username 
            AND u.ativo = 1";
    
    $admin = $db->queryOne($sql, [':username' => 'admin']);
    
    if ($admin) {
        echo "<span style='color: green'>✓ Usuário admin encontrado</span><br>";
        echo "ID: {$admin['id']}, Nome: {$admin['nome_completo']}<br>";
        echo "Email: {$admin['email']}, Perfil: {$admin['perfil_nome']}<br>";
        
        // Testar senha
        if (password_verify('admin123', $admin['senha'])) {
            echo "<span style='color: green'>✓ Senha 'admin123' confere</span><br>";
        } else {
            echo "<span style='color: red'>✗ Senha 'admin123' NÃO confere</span><br>";
            echo "Atualizando senha...<br>";
            
            $nova_senha = password_hash('admin123', PASSWORD_DEFAULT);
            $result = $db->update('usuarios', ['senha' => $nova_senha], 'id = :id', [':id' => $admin['id']]);
            
            if ($result) {
                echo "<span style='color: green'>✓ Senha atualizada com sucesso!</span><br>";
            }
        }
        
    } else {
        echo "<span style='color: red'>✗ Usuário admin NÃO encontrado ou inativo</span><br>";
    }
    
} catch (Exception $e) {
    echo "<span style='color: red'>✗ Erro: " . $e->getMessage() . "</span><br>";
}

echo "<br><h2>3. Teste da Classe User</h2>";

try {
    $userModel = new \Models\User();
    echo "<span style='color: green'>✓ Classe User carregada</span><br>";
    
    // Teste de autenticação
    $userData = $userModel->authenticate('admin', 'admin123');
    
    if ($userData) {
        echo "<span style='color: green'>✓ Autenticação FUNCIONOU!</span><br>";
        echo "<pre>";
        print_r($userData);
        echo "</pre>";
    } else {
        echo "<span style='color: red'>✗ Autenticação FALHOU</span><br>";
        
        // Debug detalhado
        echo "<h3>Debug Detalhado:</h3>";
        
        // Testar se o usuário existe
        $debug_user = $userModel->debugAdmin();
        if ($debug_user) {
            echo "Usuário encontrado no debug:<br>";
            echo "<pre>";
            unset($debug_user['senha']); // Não mostrar senha
            print_r($debug_user);
            echo "</pre>";
        } else {
            echo "Usuário NÃO encontrado no debug<br>";
        }
    }
    
} catch (Exception $e) {
    echo "<span style='color: red'>✗ Erro na classe User: " . $e->getMessage() . "</span><br>";
    echo "<strong>Stack trace:</strong><br><pre>" . $e->getTraceAsString() . "</pre>";
}

echo "<br><h2>4. Teste do Controlador Auth</h2>";

try {
    $authController = new \Controllers\AuthController();
    echo "<span style='color: green'>✓ AuthController carregado</span><br>";
    
} catch (Exception $e) {
    echo "<span style='color: red'>✗ Erro no AuthController: " . $e->getMessage() . "</span><br>";
}

echo "<br><h2>5. Simulação de Login POST</h2>";

// Simular POST para testar
$_POST['usuario'] = 'admin';
$_POST['senha'] = 'admin123';
$_SERVER['REQUEST_METHOD'] = 'POST';

try {
    ob_start(); // Capturar output
    
    // Simular processo de login sem redirecionamento
    $username = trim($_POST['usuario'] ?? '');
    $password = $_POST['senha'] ?? '';
    
    echo "Dados recebidos:<br>";
    echo "Usuário: '$username'<br>";
    echo "Senha: '$password'<br>";
    
    if (!empty($username) && !empty($password)) {
        $userModel = new \Models\User();
        $userData = $userModel->authenticate($username, $password);
        
        if ($userData) {
            echo "<span style='color: green; font-size: 18px; font-weight: bold'>✓ LOGIN SIMULADO: SUCESSO!</span><br>";
            echo "<div style='background: #d4edda; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
            echo "Dados do usuário autenticado:<br>";
            echo "ID: {$userData['id']}<br>";
            echo "Nome: {$userData['nome_completo']}<br>";
            echo "Email: {$userData['email']}<br>";
            echo "Perfil: {$userData['perfil_nome']}<br>";
            echo "</div>";
        } else {
            echo "<span style='color: red; font-size: 18px; font-weight: bold'>✗ LOGIN SIMULADO: FALHA!</span><br>";
        }
    }
    
    ob_end_flush();
    
} catch (Exception $e) {
    ob_end_clean();
    echo "<span style='color: red'>✗ Erro na simulação: " . $e->getMessage() . "</span><br>";
}

echo "<br><h2>6. Próximos Passos</h2>";

echo "<ol>";
echo "<li><strong>Se o teste acima deu SUCESSO:</strong> O problema pode estar no redirecionamento ou nas sessões.</li>";
echo "<li><strong>Se o teste deu FALHA:</strong> Vamos criar um usuário admin totalmente novo.</li>";
echo "<li><strong>Teste real:</strong> <a href='login.php' target='_blank'>Tentar login real</a></li>";
echo "</ol>";

echo "<br><h2>7. Criar Novo Admin (Forçado)</h2>";

echo "<form method='post' style='background: #f8f9fa; padding: 15px; border-radius: 5px;'>";
echo "<p>Se ainda não funcionar, clique no botão abaixo para forçar a criação de um novo usuário admin:</p>";
echo "<input type='hidden' name='force_create_admin' value='1'>";
echo "<button type='submit' style='background: #dc3545; color: white; padding: 8px 15px; border: none; border-radius: 3px;'>Forçar Criação de Novo Admin</button>";
echo "</form>";

// Processar criação forçada
if (isset($_POST['force_create_admin'])) {
    try {
        $db = \Models\Database::getInstance();
        
        // Deletar admin existente
        $db->delete('usuarios', 'usuario = :usuario', [':usuario' => 'admin']);
        
        // Criar novo
        $nova_senha = password_hash('admin123', PASSWORD_DEFAULT);
        $id = $db->insert('usuarios', [
            'nome_completo' => 'Administrador do Sistema',
            'email' => 'admin@sorveteria.com',
            'usuario' => 'admin',
            'senha' => $nova_senha,
            'perfil_id' => 1,
            'ativo' => 1
        ]);
        
        if ($id) {
            echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; margin: 10px 0; border: 1px solid #c3e6cb;'>";
            echo "<strong>✓ NOVO ADMIN CRIADO COM SUCESSO!</strong><br>";
            echo "ID: $id<br>";
            echo "Usuário: admin<br>";
            echo "Senha: admin123<br>";
            echo "<a href='login.php'>Testar Login Agora</a>";
            echo "</div>";
        }
        
    } catch (Exception $e) {
        echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; margin: 10px 0; border: 1px solid #f5c6cb;'>";
        echo "Erro ao criar admin: " . $e->getMessage();
        echo "</div>";
    }
}

echo "<br><p><a href='login.php'>← Testar Login</a> | <a href='check_system.php'>Verificação Geral</a></p>";
?>