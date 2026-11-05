<?php  
require_once '../config/config.php';  
use Middleware\Auth;  
use Helpers\Session;  
use Models\Database;
use Services\InventoryService;

Auth::check();
Auth::checkProfile2([1, 3]);

$db = Database::getInstance()->getConnection();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['inventario_id'])) {
    try {
        $id = filter_var($_POST['inventario_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) throw new RuntimeException('Inventário inválido.');
        (new InventoryService())->desfazer($id, (int) Session::getUserId());
        Session::setFlash('success', 'Inventário desfeito com sucesso.');
    } catch (Throwable $e) {
        error_log('Falha ao desfazer inventário: ' . $e->getMessage());
        $message = $e instanceof PDOException ? 'Não foi possível desfazer o inventário. Tente novamente.' : $e->getMessage();
        Session::setFlash('error', $message);
    }
    header('Location: /inventory_undo.php');
    exit;
}

// Listar inventários concluídos
$stmt = $db->prepare("
    SELECT i.id, i.data_conclusao, u.nome_completo 
    FROM inventarios i 
    JOIN usuarios u ON i.usuario_id = u.id 
    WHERE i.status = 'CONCLUIDO' 
    ORDER BY i.data_conclusao DESC
");  
$stmt->execute();  
$inventarios = $stmt->fetchAll();  

require_once '../templates/header.php';  
require_once '../templates/navigation.php';  
?>  

<main class="container mt-4">  
    <h1>Desfazer Inventários</h1>  
    <?php if (Session::hasFlash('success')): ?>  
        <div class="alert alert-success"><?php echo Session::getFlash('success'); ?></div>  
    <?php elseif (Session::hasFlash('error')): ?>  
        <div class="alert alert-danger"><?php echo htmlspecialchars(Session::getFlash('error'), ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>  

    <?php if (!empty($inventarios)): ?>  
        <table class="table">  
            <thead>  
                <tr>  
                    <th>ID</th>  
                    <th>Data</th>  
                    <th>Usuário</th>  
                    <th>Ação</th>  
                </tr>  
            </thead>  
            <tbody>  
                <?php foreach ($inventarios as $inventario): ?>  
                    <tr>  
                        <td><?php echo htmlspecialchars($inventario['id']); ?></td>  
                        <td><?php echo htmlspecialchars($inventario['data_conclusao']); ?></td>  
                        <td><?php echo htmlspecialchars($inventario['nome_completo']); ?></td>  
                        <td>  
                            <form method="POST" onsubmit="return confirm('Desfazer este inventário? Esta ação não pode ser revertida.');">  
                                <input type="hidden" name="inventario_id" value="<?php echo $inventario['id']; ?>">  
                                <button type="submit" class="btn btn-danger btn-sm">Desfazer</button>  
                            </form>  
                        </td>  
                    </tr>  
                <?php endforeach; ?>  
            </tbody>  
        </table>  
    <?php else: ?>  
        <p>Nenhum inventário disponível para desfazer.</p>  
    <?php endif; ?>  
    <a href="/inventory.php" class="btn btn-secondary">Voltar ao Inventário</a>  
</main>  

<?php require_once '../templates/footer.php'; ?>  