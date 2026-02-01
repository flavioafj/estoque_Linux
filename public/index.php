<?php
// public/index.php

// Carrega as configurações e o autoloader
require_once '../config/config.php';

use Helpers\Session;
use Controllers\CategoryController;
use Controllers\ProductController;
use Controllers\AlertController;

Session::start();

// Rotas
$requestUri = $_SERVER['REQUEST_URI'];

// Redirecionamento inicial
if ($requestUri === '/' || $requestUri === '/index.php') {
    if (Session::isLoggedIn()) {
        header('Location: dashboard.php');
        exit();
    } else {
        header('Location: login.php');
        exit();
    }
}


// Rotas para Categorias
if ($requestUri === '/admin/categories.php') {
    $controller = new CategoryController();
    $controller->index();
} elseif (preg_match('/\/category\/update\/(\d+)/', $requestUri, $matches)) {
    $controller = new CategoryController();
    $controller->update($matches[1]);
} elseif ($requestUri === '/category/store') {
    $controller = new CategoryController();
    $controller->store();
} elseif (preg_match('/\/category\/destroy\/(\d+)/', $requestUri, $matches)) {
    $controller = new CategoryController();
    $controller->destroy($matches[1]);
}

// Rotas para Produtos
if ($requestUri === '/admin/products.php') {
    $controller = new ProductController();
    $controller->index();
} elseif (preg_match('/\/product\/update\/(\d+)/', $requestUri, $matches)) {
    $controller = new ProductController();
    $controller->update($matches[1]);
} elseif ($requestUri === '/product/store') {
    $controller = new ProductController();
    $controller->store();
} elseif (preg_match('/\/product\/destroy\/(\d+)/', $requestUri, $matches)) {
    $controller = new ProductController();
    $controller->destroy($matches[1]);
 }elseif ($requestUri === '/alerts') {
    require_once __DIR__ . '/../src/Controllers/AlertController.php';
    $controller = new Controllers\AlertController();
    $controller->renderAlerts();
}

// API Endpoints
if ($requestUri === '/api/products') {
    $controller = new ProductController();
    $controller->apiGetAll();
} elseif (preg_match('/\/api\/product\/(\d+)/', $requestUri, $matches)) {
    $controller = new ProductController();
    $controller->apiGetById($matches[1]);
} elseif ($requestUri === '/api/sync') {
    require_once __DIR__ . '/../src/Controllers/SyncController.php';
    $controller = new \Controllers\SyncController();
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $controller->receiveSync();
    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $controller->sendSync();
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Método não permitido']);
    }
 }elseif (preg_match('/\/admin\/product_turnover/', $requestUri)) {
    require_once __DIR__ . '/admin/product_turnover.php';
} 