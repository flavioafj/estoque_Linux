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
                    <a href="/cart.php" class="icon cart-link" title="Ver Carrinho">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16">
                            <path d="M0 1.5A.5.5 0 0 1 .5 1H2a.5.5 0 0 1 .485.379L2.89 3H14.5a.5.5 0 0 1 .49.598l-1 5a.5.5 0 0 1-.465.401l-9.397.472L4.415 11H13a.5.5 0 0 1 0 1H4a.5.5 0 0 1-.491-.408L2.01 3.607 1.61 2H.5a.5.5 0 0 1-.5-.5zM3.14 5l.5 2.5H12.36l.5-2.5H3.14zM2 8.5a.5.5 0 0 1 .5-.5h9.025a.5.5 0 0 1 .5.5v.5a.5.5 0 0 1-.5.5H2.5a.5.5 0 0 1-.5-.5v-.5z"/>
                            <path d="M5 13.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zm6 0a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3z"/>
                        </svg>
                        <span class="cart-text">Carrinho</span>
                        
                    </a>
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