<?php
/** Somente consulta por padrão. --apply confirma os vínculos conferidos. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../config/config.php';
$pdo = Models\Database::getInstance()->getConnection();
$rows = $pdo->query("SELECT DISTINCT x.produto_id, p.nome AS produto, x.fornecedor_id, f.razao_social AS fornecedor FROM (
 SELECT id AS produto_id, fornecedor_principal_id AS fornecedor_id FROM produtos WHERE fornecedor_principal_id IS NOT NULL
 UNION SELECT mi.produto_id, m.fornecedor_id FROM movimentacao_itens mi JOIN movimentacoes m ON m.id = mi.movimentacao_id
 JOIN tipos_movimentacao tm ON tm.id = m.tipo_movimentacao_id WHERE tm.tipo = 'ENTRADA' AND m.fornecedor_id IS NOT NULL AND m.status <> 'CANCELADO'
 ) x JOIN produtos p ON p.id = x.produto_id JOIN fornecedores f ON f.id = x.fornecedor_id
 LEFT JOIN produto_fornecedores pf ON pf.produto_id = x.produto_id AND pf.fornecedor_id = x.fornecedor_id
 WHERE p.ativo = 1 AND f.ativo = 1 AND pf.id IS NULL ORDER BY x.produto_id, x.fornecedor_id")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
if (!in_array('--apply', $argv, true)) { echo "Prévia: confira antes de executar com --apply. Vínculos existentes, inclusive inativos, serão preservados.\n"; exit; }
$pdo->beginTransaction();
try {
    $service = new Services\ProductSupplierService($pdo);
    foreach ($rows as $row) $service->set((int) $row['produto_id'], (int) $row['fornecedor_id'], true, null);
    $pdo->commit();
    echo count($rows) . " vínculos incluídos na fila. Execute em apenas uma origem e sincronize.\n";
} catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
