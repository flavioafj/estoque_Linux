<?php
// public/logout.php

require_once '../config/config.php';

use Controllers\AuthController;

$authController = new AuthController();
$authController->logout();