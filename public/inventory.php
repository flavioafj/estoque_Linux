<?php  
require_once '../config/config.php';  
use Middleware\Auth;  
use Helpers\Session;  
use Models\Product;  
  
Auth::check();  
Auth::checkProfile2([1, 3]);
  
$productModel = new Product();  
$produtos = $productModel->getProdutosPorSaidasDesc();  
  
require_once '../templates/header.php';  
require_once '../templates/navigation.php';  
?>  
  
<main class="container mt-4">  
    <h1>Inventário</h1>  
    <div class="inventory-controls">  
        <input type="text" id="search" placeholder="Buscar produto...">  
        <button id="sort-btn">Ordenar Alfabeticamente</button>  
    </div>  
  
    <form method="POST" action="/inventory_confirm.php">  
        <div class="product-grid">  
            <?php foreach ($produtos as $produto): ?>  
                <?php include '../templates/inventory-product-card.php'; ?>  
            <?php endforeach; ?>  
        </div>  
        <button type="submit" class="btn btn-primary">Submeter Inventário</button>  
    </form>  
</main>  
  
<script src="/assets/js/inventory.js"></script>  
<?php require_once '../templates/footer.php'; ?>  