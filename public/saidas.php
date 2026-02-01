<?php
require_once '../config/config.php';
//require_once SRC_PATH . 'Helpers/Session.php';
//require_once SRC_PATH . 'Middleware/Auth.php';
//require_once SRC_PATH . '/Models/Product.php'; 

use Helpers\Session;
use Models\TipoMovimentacao; // NOVO
use Models\Product;

//Auth::check(['admin', 'operador']);

// Inicializa a sessão
if (session_status() === PHP_SESSION_NONE) {
    Session::start();
}

$products = (new Product())->getAll();
$tiposSaida = (new TipoMovimentacao())->findByTipo('SAIDA'); // NOVO

$pageTitle = "Registrar Saída de Estoque";
include __DIR__ . '/../templates/header.php';
include __DIR__ . '/../templates/navigation.php';
?>

<main class="container mt-4">
    <h2>Registrar Saída de Estoque</h2>
    <p>Informe as quantidades para os produtos que serão retirados do estoque para produção ou outros fins.</p>
    <?php include __DIR__ . '/../templates/alerts.php'; ?>

    <form action="movimentacao/registrar_saida.php" method="POST">
        <!-- CAMPO NOVO -->
        <div class="mb-3">
            <label for="tipo_movimentacao_id" class="form-label">Tipo de Saída</label>
            <select class="form-select" id="tipo_movimentacao_id" name="tipo_movimentacao_id" required>
                <option value="">Selecione o motivo...</option>
                <?php foreach ($tiposSaida as $tipo): ?>
                    <option value="<?= $tipo['id'] ?>"><?= htmlspecialchars($tipo['nome']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead class="table-light">
                    <tr>
                        <th>Produto</th>
                        <th>Estoque Disponível</th>
                        <th style="width: 150px;">Quantidade a Retirar</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product): ?>
                    <tr>
                        <td><?= htmlspecialchars($product['nome']) ?></td>
                        <td><?= number_format($product['estoque_atual'], 2, ',', '.') ?></td>
                        <td>
                            <input type="number" step="0.001" min="0" max="<?= $product['estoque_atual'] ?>" class="form-control" name="produtos[<?= $product['id'] ?>]" value="0">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="mb-3">
            <label for="observacao" class="form-label">Observação (Opcional)</label>
            <textarea class="form-control" id="observacao" name="observacao" rows="2"></textarea>
        </div>

        <div class="mt-4">
            <button type="submit" class="btn btn-danger">Confirmar Saída</button>
            <a href="dashboard.php" class="btn btn-secondary">Cancelar</a>
        </div>
    </form>
</main>

<?php include __DIR__ . '/../templates/footer.php'; ?>