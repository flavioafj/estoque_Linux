<?php
use Helpers\Session;

require_once __DIR__ . '/../../config/config.php';

$controllerPath = __DIR__ . '/../../src/Controllers/CategoryController.php';
if (!file_exists($controllerPath)) {
    error_log("categories.php: Não encontrou CategoryController.php em $controllerPath", 3, __DIR__ . '/../../logs/error.log');
    die("Erro: Não foi possível carregar o controlador de categorias.");
}
require_once $controllerPath;

$sessionPath = __DIR__ . '/../../src/Helpers/Session.php';
if (!file_exists($sessionPath)) {
    error_log("categories.php: Não encontrou Session.php em $sessionPath", 3, __DIR__ . '/../../logs/error.log');
    die("Erro: Não foi possível carregar o helper de sessão.");
}
require_once $sessionPath;

// Inicializa a sessão
if (session_status() === PHP_SESSION_NONE) {
    Session::start();
}

$controller = new CategoryController();

// Lógica para carregar categorias ativas ou inativas
$view = $_GET['view'] ?? 'active'; // Padrão para 'active'
if ($view === 'inactive') {
    $categories = $controller->categoryModel->getAllInactive();
    $pageTitle = "Categorias Inativas";
} else {
    $categories = $controller->categoryModel->getAll(); // O método getAll() já busca apenas os ativos
    $pageTitle = "Categorias Ativas";
}

// Verifica se navigation.php existe
$navigationPath = __DIR__ . '/../../templates/navigation.php';
if (!file_exists($navigationPath)) {
    error_log("categories.php: Não encontrou navigation.php em $navigationPath", 3, __DIR__ . '/../../logs/error.log');
}

include __DIR__ . '/../../templates/header.php';
include $navigationPath;
include __DIR__ . '/../../templates/alerts.php';
?>

<h1>Gestão de Categorias</h1>

<!-- Formulário de Criação melhorado, dentro de um <details> -->
<details>
    <summary><strong>+ Criar Nova Categoria</strong></summary>
    <form action="/category/store" method="POST" style="margin-top: 1rem;">
        <input type="text" name="nome" placeholder="Nome da categoria" required>
        <textarea name="descricao" placeholder="Descrição"></textarea>
        <label><input type="checkbox" name="ativo" value="1" checked> Ativo</label>
        <button type="submit">Criar Categoria</button>
    </form>
</details>

<h2>Lista de Categorias: <?= htmlspecialchars($pageTitle) ?></h2>

<!-- Botões para alternar a visualização -->
<div>
    <a href="categories.php?view=active" class="button">Ver Ativas</a>
    <a href="categories.php?view=inactive" class="button">Ver Inativas</a>
</div>
<br>

<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>Nome</th>
            <th>Descrição</th>
            <th>Ativo</th>
            <th>Ações</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($categories)): ?>
            <tr>
                <td colspan="5">Nenhuma categoria encontrada.</td>
            </tr>
        <?php else: ?>
            <?php foreach ($categories as $cat): ?>
                <tr>
                    <td><?= htmlspecialchars($cat['id']) ?></td>
                    <td><?= htmlspecialchars($cat['nome']) ?></td>
                    <td><?= htmlspecialchars($cat['descricao'] ?? '') ?></td>
                    <td><?= $cat['ativo'] ? 'Sim' : 'Não' ?></td>
                    <td>
                        <!-- Botão de Edição (funcionalidade a ser implementada, talvez com um modal) -->
                        <button onclick="alert('Funcionalidade de edição a ser implementada.')">Editar</button>

                        <?php if ($cat['ativo']): ?>
                            <!-- Formulário de Desativação para categorias ativas -->
                            <form action="/category/destroy/<?= $cat['id'] ?>" method="POST" style="display:inline;" onsubmit="return confirm('Tem certeza que deseja desativar esta categoria?');">
                                <button type="submit">Desativar</button>
                            </form>
                        <?php else: ?>
                            <!-- Formulário de Reativação para categorias inativas -->
                            <form action="/category/reactivate/<?= $cat['id'] ?>" method="POST" style="display:inline;">
                                <button type="submit">Reativar</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

<?php include __DIR__ . '/../../templates/footer.php'; ?>