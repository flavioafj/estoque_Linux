<?php  
require_once '../config/config.php';  
use Middleware\Auth;  
use Helpers\Session;  
use Models\Product;  
use Models\Movimentacao;  
  
Auth::check();  
Auth::checkProfile(3);  
  
$productModel = new Product();  
$movimentacaoModel = new Movimentacao();  
$usuarioId = Session::getUserId();  
  
$ajustes = []; // Para resumo de confirmação  
  
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quantidade'])) {  
    foreach ($_POST['quantidade'] as $produtoId => $quantidadeInformada) {  
        $quantidadeInformada = floatval($quantidadeInformada);  
        if ($quantidadeInformada < 0) continue; // Ignora inválidos  
  
        $produto = $productModel->getById(intval($produtoId));  
        if (!$produto) continue;  
  
        $estoqueAtual = floatval($produto['estoque_atual']);  
        $diferenca = abs($estoqueAtual - $quantidadeInformada);  
  
        if ($diferenca == 0) continue; // Sem ajuste necessário  
  
        if ($estoqueAtual > $quantidadeInformada) {  
            // Saída: Ajuste (-)  
            if ($movimentacaoModel->registrarAjusteSaida($produtoId, $diferenca, $usuarioId)) {  
                $ajustes[] = "Produto {$produto['nome']}: Saída de {$diferenca} (estoque ajustado para {$quantidadeInformada})";  
            }  
        } elseif ($estoqueAtual < $quantidadeInformada) {  
            // Entrada: Ajuste (+)  
            if ($movimentacaoModel->registrarAjusteEntrada($produtoId, $diferenca, $usuarioId)) {  
                $ajustes[] = "Produto {$produto['nome']}: Entrada de {$diferenca} (estoque ajustado para {$quantidadeInformada})";  
            }  
        }  
    }  
}  
  
require_once '../templates/header.php';  
require_once '../templates/navigation.php';  
?>  
  
<main class="container mt-4">  
    <h1>Confirmação de Inventário</h1>  
    <?php if (!empty($ajustes)): ?>  
        <p>Inventário processado com sucesso. Ajustes realizados:</p>  
        <ul>  
            <?php foreach ($ajustes as $ajuste): ?>  
                <li><?php echo htmlspecialchars($ajuste); ?></li>  
            <?php endforeach; ?>  
        </ul>  
    <?php else: ?>  
        <p>Nenhum ajuste necessário ou dados inválidos.</p>  
    <?php endif; ?>  
    <a href="/inventory.php" class="btn btn-secondary">Voltar ao Inventário</a>  
    <a href="/logout.php" class="btn btn-secondary">SAIR</a>  
</main>  
  
<?php require_once '../templates/footer.php'; ?>  