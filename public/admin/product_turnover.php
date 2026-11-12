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

$product = (new Product())->getById($product_id);

// Lógica básica
$pageTitle = "Giro de Estoque do Produto ID: " . $product_id;

// Carregar templates
require_once __DIR__ . '/../../templates/header.php';
require_once __DIR__ . '/../../templates/navigation.php';

?>

<style>
.turnover-header { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }
.turnover-toggle { display: inline-flex; align-items: center; gap: 8px; margin: 0; cursor: pointer; font-size: 14px; }
.turnover-toggle input { position: absolute; opacity: 0; width: 1px; height: 1px; }
.turnover-slider { position: relative; width: 44px; height: 24px; border-radius: 24px; background: #64748b; transition: background .2s; flex-shrink: 0; }
.turnover-slider::before { content: ''; position: absolute; width: 18px; height: 18px; left: 3px; top: 3px; background: #fff; border-radius: 50%; transition: transform .2s; }
.turnover-toggle input:checked + .turnover-slider { background: #2563eb; }
.turnover-toggle input:checked + .turnover-slider::before { transform: translateX(20px); }
.turnover-toggle input:focus-visible + .turnover-slider { outline: 3px solid #2563eb; outline-offset: 3px; }
</style>

<main class="container mt-4">
    <h1>Giro de Estoque do Produto ID: <?php echo htmlspecialchars($product_id); ?></h1>
    <h2>Produto: <?php echo htmlspecialchars($product['nome'] ?? 'Desconhecido'); ?></h2>
    
    <section class="card my-4" aria-labelledby="productSuppliersTitle">
        <div class="card-header"><h3 class="h5" id="productSuppliersTitle">Fornecedores deste produto</h3></div>
        <div class="card-body" data-product-suppliers data-produto-id="<?= (int) $product_id ?>" data-csrf="<?= htmlspecialchars(Session::getCSRFToken(), ENT_QUOTES, 'UTF-8') ?>"></div>
    </section>
    <script src="/assets/js/product_suppliers.js" defer></script>
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
        <div class="card-header turnover-header">
            <span>Giro de Estoque (por Mês)</span>
            <label class="turnover-toggle">
                <span>Quantidade</span>
                <input type="checkbox" id="turnover-price-toggle" role="switch" aria-label="Exibir giro de estoque por preço">
                <span class="turnover-slider" aria-hidden="true"></span>
                <span>Preço (R$)</span>
            </label>
        </div>
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
                            <th>Preço unitário</th>
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
                            <th>Preço unitário</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<script src="/assets/js/product_turnover.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/product_turnover.js'); ?>"></script>

<?php
require_once __DIR__ . '/../../templates/footer.php';
?>