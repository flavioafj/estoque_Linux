<?php  
require_once '../config/config.php';  
use Middleware\Auth;  
use Helpers\Session;  
use Services\InventoryService;

Auth::check();
Auth::checkProfile(3);

$ajustes = [];
$erro = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quantidade'])) {
    try {
        if (!is_array($_POST['quantidade'])) throw new RuntimeException('Contagens de inventário inválidas.');
        $resultado = (new InventoryService())->registrar($_POST['quantidade'], (int) Session::getUserId());
        $ajustes = $resultado['ajustes'];
        Session::set('inventory_quantities', []);
    } catch (Throwable $e) {
        error_log('Falha ao confirmar inventário: ' . $e->getMessage());
        $erro = 'Não foi possível concluir o inventário. Nenhum ajuste foi confirmado. Verifique as contagens e tente novamente.';
    }
}

require_once '../templates/header.php';  
require_once '../templates/navigation.php';  
?>  

<main class="container mt-4">  
    <h1>Confirmação de Inventário</h1>  
    <?php if ($erro !== null): ?>
        <p class="alert alert-danger"><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></p>
    <?php elseif (!empty($ajustes)): ?>
        <p>Inventário processado com sucesso. Ajustes realizados:</p>  
        <ul>  
            <?php foreach ($ajustes as $ajuste): ?>  
                <li><?php echo htmlspecialchars($ajuste); ?></li>  
            <?php endforeach; ?>  
        </ul>  
        <a href="/inventory_undo.php" class="btn btn-warning">Desfazer Inventários</a>  
    <?php else: ?>  
        <p>Nenhum ajuste necessário ou dados inválidos.</p>  
    <?php endif; ?>  
    <a href="/inventory.php" class="btn btn-secondary">Voltar ao Inventário</a>  
    <a href="/logout.php" class="btn btn-primary">Sair</a>  
</main>  

<?php require_once '../templates/footer.php'; ?>  