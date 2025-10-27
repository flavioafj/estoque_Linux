<?php  
require_once '../config/config.php';  
use Middleware\Auth;  
use Helpers\Session;  
use Models\Product;  
use Models\Movimentacao;  
use Models\MovimentacaoItem;  

//require_once SRC_PATH . '/Models/Product.php';
  
Auth::check();  
  
if (Session::isAdmin()) {  
    header('Location: /dashboard.php');  
    exit;  
}  
  
$cart = Session::getCart();  
$productModel = new Product();  
$cartItems = [];  
foreach ($cart as $produtoId => $quantidade) {  
    $produto = $productModel->getById($produtoId);  
    if ($produto) {  
        $cartItems[] = ['produto' => $produto, 'quantidade' => $quantidade];  
    }  
}  
  
if ($_SERVER['REQUEST_METHOD'] === 'POST') {  
    if (isset($_POST['action'])) {  
        $action = $_POST['action'];  
        if ($action === 'update') {  
            $produtoId = intval($_POST['produto_id']);  
            $quantidade = floatval($_POST['quantidade']);  
            Session::updateCartItem($produtoId, $quantidade);  
        } elseif ($action === 'remove') {  
            $produtoId = intval($_POST['produto_id']);  
            Session::removeFromCart($produtoId);  
        } elseif ($action === 'confirm') {  
            $usuarioId = Session::getUserId();  
            $movimentacao = new Movimentacao();  
            $tipoSaidaId = 5; // 'Venda'  
            $movimentacaoId = $movimentacao->criar($tipoSaidaId, $usuarioId);  
            if ($movimentacaoId) {  
                $movimentacaoItem = new MovimentacaoItem();  
                $valoresUnitarios = [];  
                foreach ($cart as $id => $qty) {  
                    $valoresUnitarios[$id] = 0;  
                }  
                if ($movimentacaoItem->adicionarItens($movimentacaoId, $cart, $valoresUnitarios)) {  
                    $movimentacao->atualizarValorTotal($movimentacaoId, 0);  
                    Session::clearCart();  
                    Session::setFlash('success', 'Saídas confirmadas com sucesso!');  
                    header('Location: /produtos.php');  
                    exit;  
                }  
            }  
            Session::setFlash('error', 'Erro ao confirmar saídas');  
        }  
    }  
    header('Location: /cart.php');  
    exit;  
}  
  
require_once '../templates/header.php';  
require_once '../templates/navigation.php';  
?>  
  
<main class="container mt-4">  
    <h1>Carrinho</h1>  
    <table class="cart-table">  
        <thead>  
            <tr>  
                <th>Produto</th>  
                <th>Quantidade</th>  
                <th>Ações</th>  
            </tr>  
        </thead>  
        <tbody>  
            <?php foreach ($cartItems as $item): ?>  
                <?php include '../templates/cart-item.php'; ?>  
            <?php endforeach; ?>  
        </tbody>  
    </table>  
    <a href="/produtos.php" class="btn">Voltar para Produtos</a>  
    <button class="btn" onclick="document.getElementById('confirm-form').submit();">Confirmar Saída</button>  
    <form id="confirm-form" method="POST" style="display:none;">  
        <input type="hidden" name="action" value="confirm">  
    </form>  
</main>  
  
<script src="/assets/js/cart.js"></script>  
<?php require_once '../templates/footer.php'; ?>  