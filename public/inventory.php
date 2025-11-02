<?php
require_once '../config/config.php';
use Middleware\Auth;

Auth::check();
Auth::checkProfile2([1, 3]);

require_once '../templates/header.php';
require_once '../templates/navigation.php';
?>

<main class="container mt-4">
    <h1>Inventário</h1>

    <!-- Controles -->
    <div class="inventory-controls mb-3">
        <input type="text" id="search" class="form-control w-auto d-inline-block" placeholder="Buscar produto...">
        <button id="clear-search" class="btn btn-outline-secondary btn-sm">Limpar Busca</button>
        <button id="clear-inventory" class="btn btn-warning btn-sm">Limpar Inventário</button>
    </div>

    

    <!-- Status -->
    <div id="status" class="mb-3"></div>

    <!-- Grid + Formulário -->
    <form id="inventory-form" method="POST" action="/inventory_confirm.php">
        <div id="product-grid" class="product-grid"></div>

        <div class="mt-4 text-center">
            <button type="submit" class="btn btn-primary btn-lg">Submeter Inventário</button>
        </div>
    </form>

    <!-- Paginação -->
    <nav id="pagination" class="mt-4" aria-label="Paginação"></nav>
</main>

<script src="/assets/js/inventory-client.js"></script>
<?php require_once '../templates/footer.php'; ?>