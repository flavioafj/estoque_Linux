<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../config/config.php';
$pdo = Models\Database::getInstance()->getConnection();
$pdo->exec(file_get_contents(__DIR__ . '/../database/migrations/20261109_product_suppliers.sql'));
echo "Tabela produto_fornecedores disponível. Nenhum vínculo histórico foi incluído.\n";
