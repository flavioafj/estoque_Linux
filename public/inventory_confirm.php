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
$db = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS, PDO_OPTIONS);  

$ajustes = []; // Para resumo de confirmação  

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quantidade'])) {  
    // Criar registro de inventário
    $stmt = $db->prepare("INSERT INTO inventarios (usuario_id, data_inicio, data_conclusao, status) VALUES (?, NOW(), NOW(), 'CONCLUIDO')");  
    $stmt->execute([$usuarioId]);  
    $inventarioId = $db->lastInsertId();  

    foreach ($_POST['quantidade'] as $produtoId => $quantidadeInformada) {  
        if ($quantidadeInformada == "") continue; // Ignora inválidos  

        $quantidadeInformada = floatval($quantidadeInformada);  
        if ($quantidadeInformada < 0) continue; // Ignora inválidos  

        $produto = $productModel->getById(intval($produtoId));  
        if (!$produto) continue;  

        $estoqueAtual = floatval($produto['estoque_atual']);  
        $diferenca = abs($estoqueAtual - $quantidadeInformada);  

        if ($diferenca == 0) continue; // Sem ajuste necessário  

        if ($estoqueAtual > $quantidadeInformada) {  
            // Saída: Ajuste (-)  
            if ($movimentacaoModel->registrarAjusteSaida($produtoId, $diferenca, $usuarioId, "Ajuste de Inventário (-)", $inventarioId)) {  
                $ajustes[] = "Produto {$produto['nome']}: Saída de {$diferenca} (estoque ajustado para {$quantidadeInformada})";  
            }  
        } elseif ($estoqueAtual < $quantidadeInformada) {  
            // Entrada: Ajuste (+)  
            if ($movimentacaoModel->registrarAjusteEntrada($produtoId, $diferenca, $usuarioId, "Ajuste de Inventário (+)", $inventarioId)) {  
                $ajustes[] = "Produto {$produto['nome']}: Entrada de {$diferenca} (estoque ajustado para {$quantidadeInformada})";  
            }  
        }  
    }  
    // Limpar quantidades temporárias (se usar Abordagem 1 - LocalStorage)
    Session::set('inventory_quantities', []);  
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
        <a href="/inventory_undo.php" class="btn btn-warning">Desfazer Inventários</a>  
    <?php else: ?>  
        <p>Nenhum ajuste necessário ou dados inválidos.</p>  
    <?php endif; ?>  
    <a href="/inventory.php" class="btn btn-secondary">Voltar ao Inventário</a>  
    <a href="/logout.php" class="btn btn-primary">Sair</a>  
</main>  

<?php require_once '../templates/footer.php'; ?>  