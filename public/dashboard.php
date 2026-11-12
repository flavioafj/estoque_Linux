<?php
// public/dashboard.php

require_once '../config/config.php';

use Middleware\Auth;
use Helpers\Session;


// Middleware: Apenas usuários logados podem acessar esta página
Auth::check();

// --- Lógica do Dashboard ---
$pageTitle = "Dashboard";
$userName = Session::getUserName();

// Carregar templates
require_once '../templates/header.php';
require_once '../templates/navigation.php';


use Models\Alert;

$alertModel = new Alert();
$pendingCount = count($alertModel->getPendingAlerts());


?>

<?php if ($pendingCount > 0): ?>
    <div class="alert-warning">
        <span class="close-button">&times;</span>
        Você tem <?php echo $pendingCount; ?> alertas de estoque baixo pendentes. <a href="/alerts">Ver Alertas</a>
    </div>
<?php endif; ?>

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

<main class="container dashboard-container mt-4">
    <h1>Bem-vindo, <?php echo htmlspecialchars($userName); ?>!</h1>
    <?php if (Session::isAdmin()): ?>
    <p>Este é o painel principal do Sistema de Controle de Estoque.</p>
    
    <!-- Cards Resumo -->
    <div class="row">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Resumo do Estoque</div>
                <div class="card-body">
                    <p><strong>Total em Estoque:</strong> <span id="total-estoque">0</span> unidades</p>
                    <p><strong>Valor FIFO:</strong> <span id="valor-fifo">R$ 0.00</span></p>
                    <p><strong>Valor Estoque:</strong> <span id="valor-estoque">R$ 0.00</span></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Estoque Baixo -->
    <div class="card">
        <div class="card-header">Produtos com Estoque Baixo</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped" id="low-stock-table">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Nome</th>
                            <th>Categoria</th>
                            <th>Fornecedor</th>
                            <th>Estoque Atual</th>
                            <th>Estoque Mínimo</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Movimentações Recentes -->
    <div class="card">
        <div class="card-header">Movimentações Recentes</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped" id="recent-movements-table">
                    <thead>
                        <tr>
                            <th>Tipo</th>
                            <th>Documento</th>
                            <th>Data</th>
                            <th>Fornecedor</th>
                            <th>Usuário</th>
                            <th>Valor Total</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Gráfico de Giro -->
    <div class="card">
        <div class="card-header turnover-header">
            <span>Giro de Estoque (Últimos 6 Meses)</span>
            <label class="turnover-toggle">
                <span>Quantidade</span>
                <input type="checkbox" id="turnover-price-toggle" role="switch" aria-label="Exibir giro de estoque por preço">
                <span class="turnover-slider" aria-hidden="true"></span>
                <span>Preço (R$)</span>
            </label>
        </div>
        <div class="card-body">
            <div class="canvas-container">
                <canvas id="stockTurnoverChart"></canvas>
            </div>
        </div>
    </div>
<?php elseif(Session::get('user_profile')===3): ?>

    <?php header('Location: /inventory.php'); exit; ?>

<?php else: ?>

    <?php header('Location: /produtos.php'); exit; ?>
    
<?php endif; ?>
</main>
<script src="/assets/js/dashboard.js?v=<?php echo filemtime(__DIR__ . '/assets/js/dashboard.js'); ?>"></script>
<?php
require_once '../templates/footer.php';