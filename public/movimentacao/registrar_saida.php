<?php
require_once '../../config/config.php';
require_once SRC_PATH . '/Controllers/MovimentacaoController.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller = new \App\Controllers\MovimentacaoController();
    $controller->registrarSaida($_POST);
} else {
    // Redireciona se o acesso não for via POST
    header('Location: ../saidas.php');
    exit();
}