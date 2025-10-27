<?php  
require_once '../config/config.php';  
use Middleware\Auth;  
use Helpers\Session;  
use Models\Product;  

//require_once SRC_PATH . '/Models/Product.php';
  
Auth::check(); 

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {  
     // --- INÍCIO DO BLOQUEIO DE REQUISIÇÃO DUPLA ---
    if (Session::get('is_processing')) {
        echo json_encode(['success' => false, 'message' => 'Processando requisição anterior.']);
        exit;
    }
    Session::set('is_processing', true);
    // --- FIM DO BLOQUEIO ---

    $action = $_GET['action'];  
    $produtoId = intval($_POST['produto_id']);  
    $quantidade = floatval($_POST['quantidade']);  
    $usuarioId = Session::getUserId();  
  
    if ($quantidade <= 0) {  
        echo json_encode(['success' => false, 'message' => 'Quantidade inválida']);  
        exit;  
    }  
  
    $productModel = new Product();  
    $produto = $productModel->getById($produtoId);  
    if ($produto['estoque_atual'] < $quantidade) {  
        echo json_encode(['success' => false, 'message' => 'Estoque insuficiente']);  
        exit;  
    }  
  
    if ($action === 'saida_direta') {  
        $movimentacao = new \Models\Movimentacao();  
        if ($movimentacao->registrarSaidaDireta($produtoId, $quantidade, $usuarioId)) {  
            Session::set('is_processing', false); // Libera o bloqueio
            echo json_encode(['success' => true]);  
        } else {  
            Session::set('is_processing', false); // Libera o bloqueio
            echo json_encode(['success' => false, 'message' => 'Erro ao registrar saída']);  
        }  
        exit;  
    } elseif ($action === 'add_cart') {  
        Session::addToCart($produtoId, $quantidade); 
        Session::set('is_processing', false); // Libera o bloqueio 
        echo json_encode(['success' => true]);  
        exit;  
    }  
    // Libera o bloqueio caso nenhuma ação seja executada
    Session::set('is_processing', false);
}  
  
if (Session::isAdmin()) {  
    header('Location: /dashboard.php');  
    exit;  
}  
  
$productModel = new Product();  
$produtos = $productModel->getProdutosPorSaidasDesc();  
  
require_once '../templates/header.php';  
require_once '../templates/navigation.php';  
?>  
  
<main class="container mt-4">  
    <h1>Produtos</h1>  
    <!-- Div superior com ícones de últimas saídas -->  
    <div class="last-exits">  
        <a href="/my_exits.php" class="icon">📤 Últimas Saídas</a>  
    </div>  
  
    <div class="product-grid">  
        <?php foreach ($produtos as $produto): ?>  
            <?php include '../templates/product-card.php'; ?>  
        <?php endforeach; ?>  
    </div>  
</main>  
  
<script src="/assets/js/main.js"></script>  
<?php require_once '../templates/footer.php'; ?>  