<?php
use Helpers\Session;
require_once __DIR__ . '/../src/Helpers/Session.php'; // Conforme Parte 1

// Inicializa a sessão
if (session_status() === PHP_SESSION_NONE) {
    Session::start();
}

// Log para depuração
if (!Session::isLoggedIn()) {
    error_log("navigation.php: Usuário não está logado", 3, __DIR__ . '/../logs/error.log');
} else {
    error_log("navigation.php: Carregado com sucesso para usuário " . Session::getUserName(), 3, __DIR__ . '/../logs/access.log');
}

$userRole = Session::isAdmin();
$userName = Session::getUserName() ?: 'Usuário';
?>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="/dashboard.php"><?php echo defined('APP_NAME') ? htmlspecialchars(APP_NAME) : 'Sorveteria'; ?></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <?php if (Session::isLoggedIn()): ?>
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <?php if ($userRole == 1): ?>  <!-- Administrador -->
                <li class="nav-item">
                    <a class="nav-link active" aria-current="page" href="/dashboard.php">Dashboard</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="/admin/categories.php">Gerenciar Categorias</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="/admin/products.php">Gerenciar Produtos</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="/admin/entradas.php">Registrar Entrada</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="/audit.php">Auditoria</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="/reports.php">Valor do estoque</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="/inventory_undo.php">Inventários</a>
                </li>
                <?php elseif ($userName == 'Inventariante'): ?>  <!-- inventariador -->

                <?php else: ?>  <!-- Operador -->
                <li class="nav-item">
                    <span class="nav-link">Acesso restrito (não admin)</span>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="/saidas.php">Registrar Saída</a>
                </li>
                <li class="nav-item">  
                    <a class="nav-link" href="/produtos.php">Produtos</a>  
                </li>  
                <li class="nav-item">  
                    <a class="nav-link" href="/cart.php">Carrinho</a>  
                </li>  
                <li class="nav-item">  
                    <a class="nav-link" href="/my_exits.php">Minhas Saídas</a>  
                </li>  
                <?php endif; ?>
            </ul>
            <!-- Resto do código continua igual -->
            </ul>
            <ul class="navbar-nav">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="navbarUserDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <?php echo htmlspecialchars($userName); ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="navbarUserDropdown">
                        <li><a class="dropdown-item" href="#">Meu Perfil</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="/logout.php">Sair</a></li>
                    </ul>
                </li>
            </ul>
            <?php else: ?>
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link" href="/login.php">Login</a>
                    </li>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</nav>