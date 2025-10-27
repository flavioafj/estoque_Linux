<?php
use Helpers\Session;
use Models\TipoMovimentacao; // NOVO
use Models\Product;

require_once '../../config/config.php';
//require_once SRC_PATH . '/Models/Product.php'; 
//require_once SRC_PATH . '/Middleware/Auth.php';



if (!Session::isAdmin()) {
            Session::setFlash('error', 'Você não tem permissão para acessar esta página.');
            header('Location: ' . $redirect_url);
            exit();
}


// Inicializa a sessão
if (session_status() === PHP_SESSION_NONE) {
    Session::start();
}
$products = (new Product())->getAll();
$tiposEntrada = (new TipoMovimentacao())->findByTipo('ENTRADA'); // NOVO

$pageTitle = "Registrar Entrada de Estoque";
include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/navigation.php';
?>

<main class="container mt-4">
    <h2>Registrar Entrada de Estoque</h2>
    <?php include __DIR__ . '/../../templates/alerts.php'; ?>

    <form action="../movimentacao/registrar_entrada.php" method="POST" enctype="multipart/form-data">
        <div class="card">
            <div class="card-header">
                Detalhes da Entrada
            </div>
            <div class="card-body">
                <!-- CAMPO NOVO -->
                <div class="mb-3">
                    <label for="tipo_movimentacao_id" class="form-label">Tipo de Entrada</label>
                    <select class="form-select" id="tipo_movimentacao_id" name="tipo_movimentacao_id" required>
                        <option value="">Selecione o motivo...</option>
                        <?php foreach ($tiposEntrada as $tipo): ?>
                            <option value="<?= $tipo['id'] ?>"><?= htmlspecialchars($tipo['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label for="observacao" class="form-label">Observação (Opcional)</label>
                    <textarea class="form-control" id="observacao" name="observacao" rows="2"></textarea>
                </div>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header">
                Entrada Manual de Produtos
            </div>
            <div class="card-body">
                <p>Adicione as quantidades para os produtos que estão entrando no estoque.</p>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Produto</th>
                                <th style="width: 150px;">Quantidade a Adicionar</th>
                                <th style="width: 150px;">Valor unitário</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $product): ?>
                            <tr>
                                <td><?= htmlspecialchars($product['nome']) ?></td>
                                <td>
                                    <input type="number" step="1" min="0" class="form-control" name="produtos[<?= $product['id'] ?>]" value="0">
                                </td>
                                                                <td>
                                    <input type="number" step="0.01" min="0" class="form-control" name="valor_unitario[<?= $product['id'] ?>]" value="0">
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

       
        <div class="mt-4">
            <button type="submit" class="btn btn-primary">Registrar Entrada</button>
            <a href="products.php" class="btn btn-secondary">Cancelar</a>
        </div>
    </form>
    <form action="../movimentacao/processar_nfe.php" method="POST" enctype="multipart/form-data">
        
        <div class="card mt-4">
            <div class="card-header">
                Entrada via Nota Fiscal (NF-e)
            </div>
            <div class="card-body">
                <p class="text-muted">Faça o upload de um ou mais arquivos XML da Nota Fiscal Eletrônica para iniciar o processo de entrada.</p>
                <div class="mb-3">
                    <label for="arquivos_nfe" class="form-label">Upload de Arquivo(s) XML</label>
                    <input class="form-control" type="file" id="arquivos_nfe" name="arquivos_nfe[]" accept=".xml" multiple required>
                </div>
                 <button type="submit" class="btn btn-primary">Processar Nota(s) Fiscal(is)</button>
            </div>
        </div>

        <div class="mt-4">
            <a href="products.php" class="btn btn-secondary">Cancelar</a>
        </div>
    </form>
</main>

<?php include __DIR__ . '/../../templates/footer.php'; ?>