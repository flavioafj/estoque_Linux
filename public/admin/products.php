<?php
use Helpers\Session;


require_once __DIR__ . '/../../config/config.php';

$controllerPath = __DIR__ . '/../../src/Controllers/ProductController.php';
if (!file_exists($controllerPath)) {
    error_log("products.php: Não encontrou ProductController.php em $controllerPath", 3, __DIR__ . '/../../logs/error.log');
    die("Erro: Não foi possível carregar o controlador de produtos.");
}
require_once $controllerPath;

$sessionPath = __DIR__ . '/../../src/Helpers/Session.php';
if (!file_exists($sessionPath)) {
    error_log("products.php: Não encontrou Session.php em $sessionPath", 3, __DIR__ . '/../../logs/error.log');
    die("Erro: Não foi possível carregar o helper de sessão.");
}
require_once $sessionPath;

// Inicializa a sessão
if (session_status() === PHP_SESSION_NONE) {
    Session::start();
}



$controller = new ProductController();



$view = $_GET['view'] ?? 'active'; // Padrão para 'active'
if ($view === 'inactive') {
    $products = $controller->productModel->getAllinativo();
    $pageTitle = "Produtos Inativos";
} else {
    $products = $controller->productModel->getAll();
    $pageTitle = "Produtos Ativos";
}

$categories = $controller->categoryModel->getAll();

$categories = $controller->categoryModel->getAll();
$unidades = $controller->unidadeMedidaModel->all();
$fornecedores = $controller->fornecedorModel->getAll();



// Verifica se navigation.php existe
$navigationPath = __DIR__ . '/../../templates/navigation.php';
if (!file_exists($navigationPath)) {
    error_log("products.php: Não encontrou navigation.php em $navigationPath", 3, __DIR__ . '/../../logs/error.log');
}

include __DIR__ . '/../../templates/header.php'; // Conforme Parte 1
include $navigationPath; // Caminho ajustado
include __DIR__ . '/../../templates/alerts.php'; // Conforme Parte 1
?>
<main class="container mt-4">
    <h1>Gestão de Produtos</h1>
    <form action="/../product/store" method="POST">
        <div>
            <label for="nome">Nome *</label>
            <input type="text" id="nome" name="nome" placeholder="Nome do produto" required>
        </div>
        <div>
            <label for="codigo">Código *</label>
            <input type="text" id="codigo" name="codigo" placeholder="Código único">
        </div>
        <div>
            <label for="categoria_id">Categoria *</label>
            <select id="categoria_id" name="categoria_id" required>
                <option value="">Selecione uma categoria</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= htmlspecialchars($cat['id']) ?>"><?= htmlspecialchars($cat['nome']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="unidade_medida_id">Unidade de Medida *</label>
            <select id="unidade_medida_id" name="unidade_medida_id">
                <option value="">Selecione uma unidade</option>
                <?php foreach ($unidades as $unidade): ?>
                    <option value="<?= htmlspecialchars($unidade['id']) ?>"><?= htmlspecialchars($unidade['sigla']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="estoque_atual">Estoque Atual (kg) </label>
            <input type="number" id="estoque_atual" name="estoque_atual" step="0.001" min="0" placeholder="0.000" value="0">
        </div>
        <div>
            <label for="estoque_minimo">Estoque Mínimo (kg) *</label>
            <input type="number" id="estoque_minimo" name="estoque_minimo" step="0.001" min="0" placeholder="0.000" value="1">
        </div>
        <div>
            <label for="estoque_maximo">Estoque Máximo (kg)</label>
            <input type="number" id="estoque_maximo" name="estoque_maximo" step="0.001" min="0" placeholder="0.000" value="100">
        </div>
        <div>
            <label for="preco_custo">Preço de Custo (R$)</label>
            <input type="number" id="preco_custo" name="preco_custo" step="0.01" min="0" placeholder="0.00" value="0">
        </div>
        <div>
            <label for="preco_venda">Preço de Venda (R$)</label>
            <input type="number" id="preco_venda" name="preco_venda" step="0.01" min="0" placeholder="0.00" value="100">
        </div>
        <div>
            <label for="margem_lucro">Margem de Lucro (%)</label>
            <input type="number" id="margem_lucro" name="margem_lucro" step="0.01" min="0" placeholder="0.00" value="100">
        </div>
        <div>
            <label for="fornecedor_principal_id">Fornecedor Principal</label>
            <select id="fornecedor_principal_id" name="fornecedor_principal_id">
                <option value="">Selecione um fornecedor</option>
                <?php foreach ($fornecedores as $forn): ?>
                    <option value="<?= htmlspecialchars($forn['id']) ?>"><?= htmlspecialchars($forn['razao_social']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="descricao">Descrição</label>
            <textarea id="descricao" name="descricao" placeholder="Descrição do produto"></textarea>
        </div>
        <div>
            <label for="ativo">Ativo</label>
            <input type="checkbox" id="ativo" name="ativo" checked>
        </div>
        <button type="submit">Criar Produto</button>
    </form>

    <h2>Lista de Produtos: <?= $pageTitle ?></h2>
    <!-- Botões para alternar a visualização -->
    <div>
        <a href="products.php?view=active" class="button">Ver Ativos</a>
        <a href="products.php?view=inactive" class="button">Ver Inativos</a>
    </div>
    <br>

    <table class="prod">
        <thead>
            <tr>
                <th>ID</th>
                <th>Código</th>
                <th>Nome</th>
                <th>Categoria</th>
               
                <th>Estoque Mínimo</th>
              
                <th>Estoque Atual</th>
                <th>Ativo</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($products as $prod): ?>
                <tr>
                    <td><?= htmlspecialchars($prod['id']) ?></td>
                    <td><?= htmlspecialchars($prod['codigo'] ?? '') ?></td>
                    <td><a href="product_turnover.php?id=<?php echo $prod['id']?>"><?= htmlspecialchars($prod['nome']) ?></a></td>
                    <td><?= htmlspecialchars($controller->categoryModel->getById($prod['categoria_id'])['nome'] ?? 'N/A') ?></td>
                    
                    <td><?= htmlspecialchars($prod['estoque_minimo']) ?></td>
                    
                    <!-- VALOR DO ESTOQUE -->
                                        <td>
                                            <?= number_format($prod['estoque_atual'], 2, ',', '.') ?>
                                            <?php if ($prod['estoque_atual'] <= $prod['estoque_minimo']): ?>
                                                <span class="badge bg-danger">Baixo</span>
                                            <?php endif; ?>
                                        </td>
                    <td><?= $prod['ativo'] ? 'Sim' : 'Não' ?></td>
                </tr>
                <tr>
                    <td colspan="9">
                        <!-- Formulário de Atualização -->
                        <form action="/../product/update/<?= $prod['id'] ?>" method="POST" style="display:inline;">
                            <input type="text" name="nome" value="<?= htmlspecialchars($prod['nome']) ?>" required>
                            <input type="text" name="codigo" value="<?= htmlspecialchars($prod['codigo'] ?? '') ?>">
                            <select name="categoria_id" required>
                                <option value="">Selecione uma categoria</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= htmlspecialchars($cat['id']) ?>" <?= $cat['id'] == $prod['categoria_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cat['nome']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <select name="unidade_medida_id">
                                <option value="">Selecione uma unidade</option>
                                <?php foreach ($unidades as $unidade): ?>
                                    <option value="<?= htmlspecialchars($unidade['id']) ?>" <?= $unidade['id'] == ($prod['unidade_medida_id'] ?? '') ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($unidade['sigla']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="number" name="estoque_atual" value="<?= htmlspecialchars($prod['estoque_atual']) ?>" step="0.001" min="0">
                            <input type="number" name="estoque_minimo" value="<?= htmlspecialchars($prod['estoque_minimo']) ?>" step="0.001" min="0">
                            <select name="fornecedor_principal_id">
                                <option value="">Selecione um fornecedor</option>
                                <?php foreach ($fornecedores as $forn): ?>
                                    <option value="<?= htmlspecialchars($forn['id']) ?>" <?= $forn['id'] == ($prod['fornecedor_principal_id'] ?? '') ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($forn['razao_social']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <textarea name="descricao"><?= htmlspecialchars($prod['descricao'] ?? '') ?></textarea>
                            <label><input type="checkbox" name="ativo" <?= $prod['ativo'] ? 'checked' : '' ?>> Ativo</label>
                            <button type="submit">Editar</button>
                        </form>
                        <!-- Formulário de Exclusão -->
                        <?php if ($prod['ativo']): ?>
                            <!-- Formulário de Desativação para produtos ativos -->
                            <form action="/product/destroy/<?= $prod['id'] ?>" method="POST" style="display:inline;" onsubmit="return confirm('Tem certeza que deseja desativar este produto?');">
                                <button type="submit">Desativar</button>
                            </form>
                        <?php else: ?>
                            <!-- Formulário de Reativação para produtos inativos -->
                            <form action="/product/reactivate/<?= $prod['id'] ?>" method="POST" style="display:inline;">
                                <button type="submit">Reativar</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</main>
<?php include __DIR__ . '/../../templates/footer.php'; // Conforme Parte 1 ?>