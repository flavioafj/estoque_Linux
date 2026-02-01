<?php  
require_once '../config/config.php';  
use Middleware\Auth;  
use Helpers\Session;  
  
Auth::check();  
  
if (Session::isAdmin()) {  
    header('Location: /estoque-sorveteria/public/dashboard.php');  
    exit;  
}  
  
$usuarioId = Session::getUserId();  
$db = \Models\Database::getInstance(); // Assumindo Database class existe de BaseModel  
$sql = "  
    SELECT m.data_movimentacao, p.nome as produto, mi.quantidade  
    FROM movimentacoes m  
    JOIN movimentacao_itens mi ON m.id = mi.movimentacao_id  
    JOIN produtos p ON mi.produto_id = p.id  
    JOIN tipos_movimentacao tm ON m.tipo_movimentacao_id = tm.id  
    WHERE m.usuario_id = ? AND tm.tipo = 'SAIDA'  
    ORDER BY m.data_movimentacao DESC  
    LIMIT 50  
";  
$stmt = $db->prepare($sql);  
$stmt->execute([$usuarioId]);  
$saidas = $stmt->fetchAll(\PDO::FETCH_ASSOC);  
  
require_once '../templates/header.php';  
require_once '../templates/navigation.php';  
?>  
  
<main class="container mt-4">  
    <h1>Minhas Saídas Recentes</h1>  
    <table class="exits-table">  
        <thead>  
            <tr>  
                <th>Data</th>  
                <th>Produto</th>  
                <th>Quantidade</th>  
            </tr>  
        </thead>  
        <tbody>  
            <?php foreach ($saidas as $saida): ?>  
                <tr>  
                    <td><?php echo htmlspecialchars($saida['data_movimentacao']); ?></td>  
                    <td><?php echo htmlspecialchars($saida['produto']); ?></td>  
                    <td><?php echo htmlspecialchars($saida['quantidade']); ?></td>  
                </tr>  
            <?php endforeach; ?>  
        </tbody>  
    </table>  
</main>  
  
<?php require_once '../templates/footer.php'; ?>  