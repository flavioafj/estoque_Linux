<?php
namespace Services;

use Helpers\SyncQueueHelper;
use Models\Database;
use Models\Movimentacao;
use Models\Product;
use PDO;
use RuntimeException;
use Throwable;

/** Inventário, ajustes e evento são confirmados juntos, usando uma única conexão. */
final class InventoryService
{
    private PDO $pdo;

    public function __construct() { $this->pdo = Database::getInstance()->getConnection(); }

    public function registrar(array $quantidades, int $usuarioId): array
    {
        return $this->processar(['evento_id' => bin2hex(random_bytes(16)), 'user_id' => $usuarioId,
            'data' => date('Y-m-d H:i:s'), 'quantidades' => $quantidades], false);
    }

    public function desfazer(int $inventarioId, int $usuarioId): array
    {
        if ($inventarioId <= 0) throw new RuntimeException('Inventário inválido.');
        return $this->processar(['evento_id' => bin2hex(random_bytes(16)), 'user_id' => $usuarioId,
            'data' => date('Y-m-d H:i:s'), '_inventario_id' => $inventarioId], false);
    }

    public function receber(array $dados): array { return $this->processar($dados, true); }

    private function processar(array $dados, bool $recebido): array
    {
        if (!is_string($dados['evento_id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $dados['evento_id']) ||
            !filter_var($dados['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ||
            !is_string($dados['data'] ?? null) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $dados['data'])) {
            throw new RuntimeException('Identificação do inventário inválida.');
        }
        $undo = $recebido ? isset($dados['desfaz_evento_id']) : isset($dados['_inventario_id']);
        $items = $recebido ? ($dados['itens'] ?? null) : ($dados['quantidades'] ?? null);
        if (!$undo && (!is_array($items) || !$items)) throw new RuntimeException('Informe as contagens do inventário.');
        if (is_array($items)) ksort($items, SORT_NUMERIC);
        $own = !$this->pdo->inTransaction();
        if ($own) $this->pdo->beginTransaction();
        else $this->pdo->exec('SAVEPOINT inventory_sync');
        try {
            // Mutex estável: serializa reenvios PUSH e PULL antes de consultar o recibo.
            $types = $this->pdo->query('SELECT id, tipo FROM tipos_movimentacao WHERE id IN (3, 7) ORDER BY id' . $this->lock())->fetchAll(PDO::FETCH_KEY_PAIR);
            if (($types[3] ?? null) !== 'ENTRADA' || ($types[7] ?? null) !== 'SAIDA') {
                throw new RuntimeException('Tipos de ajuste de inventário não configurados.');
            }
            if ($recebido) {
                $stmt = $this->pdo->prepare("SELECT dados FROM sincronizacao WHERE acao = 'AJUSTE_INVENTARIO' AND JSON_UNQUOTE(JSON_EXTRACT(dados, '$.evento_id')) = ? LIMIT 1" . $this->lock());
                $stmt->execute([$dados['evento_id']]);
                $previous = $stmt->fetchColumn();
                if ($previous !== false) {
                    $previous = json_decode($previous, true, 512, JSON_THROW_ON_ERROR);
                    // O mesmo identificador não pode ser reutilizado com efeitos diferentes.
                    if ($previous != $dados) throw new RuntimeException('Conteúdo do inventário divergente do evento já registrado.');
                    $this->finish($own);
                    return ['inventario_id' => null, 'ajustes' => []];
                }
            }
            $inventoryId = null;
            $originalEvent = null;
            if ($undo) {
                if ($recebido) {
                    if (!is_string($dados['desfaz_evento_id']) || !preg_match('/^[a-f0-9]{32}$/D', $dados['desfaz_evento_id'])) {
                        throw new RuntimeException('Referência do inventário original inválida.');
                    }
                    $stmt = $this->pdo->prepare("SELECT m.inventario_id FROM sincronizacao s JOIN movimentacoes m ON m.id = s.registro_id WHERE s.acao = 'AJUSTE_INVENTARIO' AND JSON_UNQUOTE(JSON_EXTRACT(s.dados, '$.evento_id')) = ? LIMIT 1" . $this->lock());
                    $stmt->execute([$dados['desfaz_evento_id']]);
                    $inventoryId = $stmt->fetchColumn();
                    if (!$inventoryId) throw new RuntimeException('Sincronize o inventário original antes de seu desfazimento.');
                    $originalEvent = $dados['desfaz_evento_id'];
                } else {
                    $inventoryId = (int) $dados['_inventario_id'];
                }
                $stmt = $this->pdo->prepare('SELECT status, data_conclusao FROM inventarios WHERE id = ?' . $this->lock());
                $stmt->execute([$inventoryId]);
                $inventory = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$inventory || $inventory['status'] !== 'CONCLUIDO') throw new RuntimeException('Inventário inválido ou já cancelado.');
                if (!$recebido && strtotime($inventory['data_conclusao']) < time() - 86400) {
                    throw new RuntimeException('Inventário não pode ser desfeito após 24 horas.');
                }
                $stmt = $this->pdo->prepare('SELECT m.tipo_movimentacao_id, mi.produto_id, mi.quantidade, mi.valor_unitario, mi.valor_total FROM movimentacoes m JOIN movimentacao_itens mi ON mi.movimentacao_id = m.id WHERE m.inventario_id = ? ORDER BY mi.produto_id');
                $stmt->execute([$inventoryId]);
                $inverse = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $id = (int) $row['produto_id'];
                    $type = (int) $row['tipo_movimentacao_id'];
                    if (!in_array($type, [3, 7], true) || isset($inverse[$id])) throw new RuntimeException('Histórico de inventário incompatível com desfazimento.');
                    $inverse[$id] = ['diferenca' => ($type === 3 ? -1 : 1) * $this->integer($row['quantidade']),
                        'valor_unitario' => (float) $row['valor_unitario'], 'valor_total' => (float) $row['valor_total']];
                }
                if (!$recebido && $inverse) {
                    $stmt = $this->pdo->prepare("SELECT s.dados FROM sincronizacao s JOIN movimentacoes m ON m.id = s.registro_id WHERE s.acao = 'AJUSTE_INVENTARIO' AND m.inventario_id = ? ORDER BY s.id LIMIT 1");
                    $stmt->execute([$inventoryId]);
                    $original = $stmt->fetchColumn();
                    if ($original === false) throw new RuntimeException('Inventário antigo sem evento de sincronização; não é possível desfazê-lo de forma sincronizada.');
                    $originalEvent = json_decode($original, true, 512, JSON_THROW_ON_ERROR)['evento_id'];
                }
                if ($recebido && (!is_array($items) || $items != $inverse)) throw new RuntimeException('Ajustes inversos divergentes do inventário original.');
                $items = $inverse;
            }
            $payload = ['evento_id' => $dados['evento_id'], 'user_id' => (int) $dados['user_id'], 'data' => $dados['data'], 'itens' => []];
            if ($undo && $originalEvent !== null) $payload['desfaz_evento_id'] = $originalEvent;
            $summary = [];
            foreach ($items as $id => $item) {
                if (!$recebido && ($item === '' || $item === null)) continue;
                if (!filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) throw new RuntimeException('Produto inválido no inventário.');
                $stmt = $this->pdo->prepare('SELECT nome, estoque_atual FROM produtos WHERE id = ?' . $this->lock());
                $stmt->execute([$id]);
                $product = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$product) throw new RuntimeException('Produto não encontrado: ' . $id);
                $current = (float) $product['estoque_atual'];
                if (!is_finite($current) || $current < 0 || floor($current) != $current) throw new RuntimeException('Saldo incompatível com estoque por unidades.');
                if ($recebido || $undo) {
                    if (!is_array($item)) throw new RuntimeException('Item de inventário inválido.');
                    $delta = $this->integer($item['diferenca'] ?? null, true);
                    $value = $item['valor_unitario'] ?? null;
                    $total = $item['valor_total'] ?? null;
                    if (!$delta || !is_numeric($value) || !is_finite((float) $value) || $value < 0 ||
                        !is_numeric($total) || !is_finite((float) $total) || $total < 0) throw new RuntimeException('Ajuste de inventário inválido.');
                    $value = (float) $value;
                    $total = (float) $total;
                } else {
                    $count = $this->integer($item);
                    $delta = $count - $current;
                    if ($delta == 0) continue;
                    $stmt = $this->pdo->prepare('SELECT valor_unitario FROM estoques WHERE produto_id = ? ORDER BY data_entrada DESC, id DESC LIMIT 1');
                    $stmt->execute([$id]);
                    $lastValue = $stmt->fetchColumn();
                    $value = $lastValue === false ? 0.0 : (float) $lastValue;
                    $total = abs($delta) * $value;
                }
                if ($current + $delta < 0) throw new RuntimeException('Saldo insuficiente para aplicar ajuste do produto ' . $id);
                // O evento leva diferenças, preservando vendas/entradas posteriores no destino.
                $payload['itens'][(int) $id] = ['diferenca' => (int) $delta, 'valor_unitario' => $value, 'valor_total' => $total];
                $summary[] = 'Produto ' . $product['nome'] . ': ' . ($delta > 0 ? 'Entrada' : 'Saída') . ' de ' . abs($delta) . ' (estoque ajustado para ' . ($current + $delta) . ')';
            }
            if ($recebido && !$payload['itens']) throw new RuntimeException('Inventário recebido sem ajustes.');
            if (!$undo) {
                $stmt = $this->pdo->prepare("INSERT INTO inventarios (usuario_id, data_inicio, data_conclusao, status) VALUES (?, ?, ?, 'CONCLUIDO')");
                $stmt->execute([$payload['user_id'], $payload['data'], $payload['data']]);
                $inventoryId = (int) $this->pdo->lastInsertId();
            }
            $firstMovement = 0;
            foreach ($payload['itens'] as $id => $item) {
                $delta = $item['diferenca'];
                $qty = abs($delta);
                $observation = $delta > 0 ? 'Ajuste de Inventário (+)' : 'Ajuste de Inventário (-)';
                if ($undo) $observation = $delta > 0 ? 'Desfazer Inventário: Entrada (+)' : 'Desfazer Inventário: Saída (-)';
                $stmt = $this->pdo->prepare("INSERT INTO movimentacoes (tipo_movimentacao_id, data_movimentacao, usuario_id, observacoes, status, inventario_id, valor_total) VALUES (?, ?, ?, ?, 'PROCESSADO', ?, ?)");
                $stmt->execute([$delta > 0 ? 3 : 7, $payload['data'], $payload['user_id'], $observation, $inventoryId, $item['valor_total']]);
                $movementId = (int) $this->pdo->lastInsertId();
                if (!$firstMovement) $firstMovement = $movementId;
                $stmt = $this->pdo->prepare('INSERT INTO movimentacao_itens (movimentacao_id, produto_id, quantidade, valor_unitario, valor_total) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$movementId, $id, $qty, $item['valor_unitario'], $item['valor_total']]);
                if ($delta > 0) {
                    $stmt = $this->pdo->prepare('INSERT INTO estoques (produto_id, valor_unitario) VALUES (?, ?)');
                    for ($i = 0; $i < $qty; $i++) $stmt->execute([$id, $item['valor_unitario']]);
                } else {
                    $order = $undo ? 'DESC' : 'ASC';
                    $stmt = $this->pdo->prepare('SELECT id FROM estoques WHERE produto_id = ? ORDER BY data_entrada ' . $order . ', id ' . $order . ' LIMIT ' . $qty . $this->lock());
                    $stmt->execute([$id]);
                    $units = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    if (count($units) !== $qty) throw new RuntimeException('Unidades de estoque insuficientes para o produto ' . $id);
                    $delete = $this->pdo->prepare('DELETE FROM estoques WHERE id = ?');
                    foreach ($units as $unit) $delete->execute([$unit]);
                }
                if (!(new Product())->atualizarEstoque($id, $qty, $delta > 0 ? 'ENTRADA' : 'SAIDA')) throw new RuntimeException('Falha ao ajustar o saldo.');
                (new Movimentacao())->logAudit('INSERT', $movementId, null, $item);
            }
            if ($undo) {
                $stmt = $this->pdo->prepare("UPDATE inventarios SET status = 'CANCELADO' WHERE id = ?");
                $stmt->execute([$inventoryId]);
            }
            if ($firstMovement) {
                if ($recebido) {
                    $stmt = $this->pdo->prepare("INSERT INTO sincronizacao (tabela, registro_id, acao, dados, sincronizado, tentativas, criado_em, sincronizado_em) VALUES ('movimentacoes', ?, 'AJUSTE_INVENTARIO', ?, 1, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
                    $stmt->execute([$firstMovement, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
                } else {
                    SyncQueueHelper::queueChange('movimentacoes', $firstMovement, 'AJUSTE_INVENTARIO', $payload);
                }
            }
            $this->finish($own);
            return ['inventario_id' => $inventoryId, 'ajustes' => $summary];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                if ($own) $this->pdo->rollBack();
                else {
                    $this->pdo->exec('ROLLBACK TO SAVEPOINT inventory_sync');
                    $this->pdo->exec('RELEASE SAVEPOINT inventory_sync');
                }
            }
            throw $e;
        }
    }

    private function lock(): string { return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''; }
    private function finish(bool $own): void {
        if ($own) $this->pdo->commit();
        else $this->pdo->exec('RELEASE SAVEPOINT inventory_sync');
    }
    private function integer($value, bool $signed = false): int {
        if (!is_numeric($value) || !is_finite((float) $value) || (!$signed && $value < 0) ||
            floor((float) $value) != (float) $value || abs((float) $value) >= PHP_INT_MAX) {
            throw new RuntimeException('As quantidades do inventário devem ser inteiras e não negativas.');
        }
        return (int) $value;
    }
}
