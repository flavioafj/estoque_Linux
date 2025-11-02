<?php  
require_once '../config/config.php';  
use Middleware\Auth;  
use Helpers\Session;  
use Models\Product;  

//require_once SRC_PATH . '/Models/Product.php';
  
Auth::check(); 
// API: retorna todos os produtos ativos
if (isset($_GET['api']) && $_GET['api'] === 'products') {
    header('Content-Type: application/json');
    $productModel = new Product();
    $produtos = $productModel->getProdutosPorSaidasDesc(); // ou all('ativo = 1')
    echo json_encode(array_values($produtos));
    exit;
}

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
  
  
require_once '../templates/header.php';  
require_once '../templates/navigation.php';  
?>  
  
<main class="container mt-4">  
    <h1>Produtos</h1>  
    <!-- Div superior com ícones de últimas saídas -->  
    <div class="last-exits">  
        <a href="/my_exits.php" class="icon">📤 Últimas Saídas</a>  
    </div>  

    <!-- BUSCA (igual ao inventory.php) -->
    <div class="inventory-controls mb-3">
        <input type="text" id="search" class="form-control w-auto d-inline-block" placeholder="Buscar produto...">
        <button id="clear-search" class="btn btn-outline-secondary btn-sm">Limpar</button>
    </div>
  
    <!-- Status -->
    <div id="status" class="mb-3"></div>

    <!-- Grid (vazio, será preenchido por JS) -->
    <div id="product-grid" class="product-grid"></div>

    <!-- Paginação -->
    <nav id="pagination" class="mt-4" aria-label="Paginação"></nav>
</main>  

<!-- JS principal (não conflita com main.js) -->
<script src="/assets/js/main.js"></script>
<script src="/assets/js/produtos-client.js"></script>

<?php require_once '../templates/footer.php'; ?>