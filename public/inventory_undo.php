<?php  
require_once '../config/config.php';  
use Middleware\Auth;  
use Helpers\Session;  
use Models\Movimentacao;
use Models\MovimentacaoItem ;
use Models\Estoque;
use Models\Product;  

Auth::check();  
Auth::checkProfile2([1, 3]); // Apenas admin e inventariante podem desfazer  



$db = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS, PDO_OPTIONS);  
$movimentacaoModel = new Movimentacao();  
$usuarioId = Session::getUserId();  

// Processar desfazimento
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['inventario_id'])) {  
    $inventarioId = intval($_POST['inventario_id']);  
    $stmt = $db->prepare("SELECT status, data_conclusao FROM inventarios WHERE id = ?");  
    $stmt->execute([$inventarioId]);  
    $inventario = $stmt->fetch(); 
    
    if (strtotime($inventario['data_conclusao']) < strtotime('-24 hours')) {
        Session::setFlash('error', 'Inventário não pode ser desfeito após 24 horas.');
        header('Location: /inventory_undo.php');
        exit;
    }

    if ($inventario && $inventario['status'] === 'CONCLUIDO') {  
        // Buscar movimentações do inventário
        $stmt = $db->prepare("
            SELECT m.id, m.tipo_movimentacao_id, mi.produto_id, mi.quantidade 
            FROM movimentacoes m 
            JOIN movimentacao_itens mi ON m.id = mi.movimentacao_id 
            WHERE m.inventario_id = ?
        ");  
        $stmt->execute([$inventarioId]);  
        $movimentacoes = $stmt->fetchAll();  

        $success = true;  
        foreach ($movimentacoes as $mov) {  
            $tipoOriginal = $mov['tipo_movimentacao_id'];  
            $produtoId = $mov['produto_id'];  
            $quantidade = $mov['quantidade'];  

            // Inverter: entrada (3) vira saída (7), saída (7) vira entrada (3)
            $tipoInverso = $tipoOriginal === 3 ? 7 : 3;  
            $observacao = $tipoInverso === 3 ? "Desfazer Inventário: Entrada (+)" : "Desfazer Inventário: Saída (-)";  
            $qtdEst = new Estoque();
            $val = $qtdEst->getValorUltimo($produtoId);
            // Criar movimentação inversa
            $movId = $movimentacaoModel->criar($tipoInverso, $usuarioId, $observacao, null, $inventarioId);  
            if ($movId) {  
                $item = new MovimentacaoItem();  
                if (!$item->adicionarItens($movId, [$produtoId => $quantidade], [$produtoId => $val], 1)) {  
                    $success = false;  
                }  
                $movimentacaoModel->atualizarValorTotal($movId, 0);  
            } else {  
                $success = false;  
            }  
        }  

        if ($success) {  
            // Marcar inventário como cancelado
            $stmt = $db->prepare("UPDATE inventarios SET status = 'CANCELADO', atualizado_em = NOW() WHERE id = ?");  
            $stmt->execute([$inventarioId]);  
            Session::setFlash('success', 'Inventário desfeito com sucesso.');  
        } else {  
            Session::setFlash('error', 'Erro ao desfazer inventário.');  
        }  
    } else {  
        Session::setFlash('error', 'Inventário inválido ou já cancelado.');  
    }  
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
        <div class="alert alert-danger"><?php echo Session::getFlash('error'); ?></div>  
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