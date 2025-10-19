<?php
require_once '../../config/config.php';
require_once SRC_PATH . '/Controllers/MovimentacaoController.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller = new \App\Controllers\MovimentacaoController();
    $controller->registrarEntrada($_POST, $_FILES);
} else {
    // Redireciona se o acesso não for via POST
    header('Location: ../admin/entradas.php');
    exit();
}