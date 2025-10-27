<?php
// public/admin/product_turnover.php

require_once __DIR__ . '/../../config/config.php';
//require_once SRC_PATH . '/Models/Product.php'; 

use Middleware\Auth;
use Helpers\Session;
use Models\Product;

// Middleware: Apenas administradores podem acessar
Auth::checkAdmin();

$product_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$product_id) {
    header('Location: /admin/products.php');
    exit;
}

$product = (new \Product())->getById($product_id);

// Lógica básica
$pageTitle = "Giro de Estoque do Produto ID: " . $product_id;

// Carregar templates
require_once __DIR__ . '/../../templates/header.php';
require_once __DIR__ . '/../../templates/navigation.php';

?>

<main class="container mt-4">
    <h1>Giro de Estoque do Produto ID: <?php echo htmlspecialchars($product_id); ?></h1>
    <h2>Produto: <?php echo htmlspecialchars($product['nome'] ?? 'Desconhecido'); ?></h2>
    
    <!-- Formulário para selecionar período -->
    <form id="period-form">
        <div class="row">
            <div class="col-md-4">
                <label for="start_date">Data Inicial:</label>
                <input type="date" id="start_date" name="start_date" class="form-control">
            </div>
            <div class="col-md-4">
                <label for="end_date">Data Final:</label>
                <input type="date" id="end_date" name="end_date" class="form-control">
            </div>
            <div class="col-md-4 align-self-end">
                <button type="submit" class="btn btn-primary">Atualizar</button>
            </div>
        </div>
    </form>

    <!-- Gráfico -->
    <div class="card mt-4">
        <div class="card-header">Giro de Estoque (por Mês)</div>
        <div class="card-body">
            <div class="canvas-container">
                <canvas id="productTurnoverChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Tabela de Entradas -->
    <div class="card mt-4">
        <div class="card-header">Entradas</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped" id="entradas-table">
                    <thead>
                        <tr>
                            <th>Documento</th>
                            <th>Data</th>
                            <th>Usuário</th>
                            <th>Quantidade</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Tabela de Saídas -->
    <div class="card mt-4">
        <div class="card-header">Saídas</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped" id="saidas-table">
                    <thead>
                        <tr>
                            <th>Documento</th>
                            <th>Data</th>
                            <th>Usuário</th>
                            <th>Quantidade</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<script src="../assets/js/product_turnover.js"></script>

<?php
require_once __DIR__ . '/../../templates/footer.php';
?>