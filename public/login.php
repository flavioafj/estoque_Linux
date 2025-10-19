<?php
// public/login.php

require_once '../config/config.php';

use Controllers\AuthController;

$authController = new AuthController();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $authController->login();
} else {
    $authController->showLoginForm();
}