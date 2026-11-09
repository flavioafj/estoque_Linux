<?php
namespace Services;

use PDO;
use RuntimeException;
use Throwable;
use Helpers\SyncQueueHelper;

/** A identidade compartilhada é o par produto/fornecedor, não o AUTO_INCREMENT local. */
final class ProductSupplierService {
    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public static function ids($values): array {
        if (!is_array($values)) throw new RuntimeException('Seleção de produtos/fornecedores inválida.');
        $ids = [];
        foreach ($values as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$id) throw new RuntimeException('Identificador inválido na seleção.');
            $ids[$id] = $id;
        }
        sort($ids); return array_values($ids);
    }

    public function set(int $product, int $supplier, bool $active, ?int $actor, bool $principal = false): ?string {
        return $this->transaction(function () use ($product, $supplier, $active, $actor, $principal) {
            [$p, $s] = $this->parents($product, $supplier);
            if ($active && (!(int) $p['ativo'] || !(int) $s['ativo'])) throw new RuntimeException('Selecione produto e fornecedor ativos.');
            $stmt = $this->pdo->prepare('SELECT * FROM produto_fornecedores WHERE produto_id = ? AND fornecedor_id = ?');
            $stmt->execute([$product, $supplier]); $old = $stmt->fetch(PDO::FETCH_ASSOC);
            $primary = $p['fornecedor_principal_id'] === null ? null : (int) $p['fornecedor_principal_id'];
            $changePrimary = $principal || (!$active && $primary === $supplier);
            if ($old && (int) $old['ativo'] === (int) $active && (!$principal || $primary === $supplier)) {
                $stmt = $this->pdo->prepare("SELECT JSON_UNQUOTE(JSON_EXTRACT(dados, '$.evento_id')) FROM sincronizacao WHERE tabela = 'produto_fornecedores' AND registro_id = ? AND JSON_EXTRACT(dados, '$.fornecedor_id') = ? ORDER BY id DESC LIMIT 1");
                $stmt->bindValue(1, $product, PDO::PARAM_INT); $stmt->bindValue(2, $supplier, PDO::PARAM_INT); $stmt->execute(); $event = $stmt->fetchColumn();
                if ($event && !$changePrimary) return $event;
                if ($event && $principal && $primary === $supplier) return $event;
            }
            if (!$old && !$active && !$changePrimary) return null;
            $data = ['evento_id' => bin2hex(random_bytes(16)), 'produto_id' => $product, 'fornecedor_id' => $supplier,
                'ativo' => (int) $active, 'criado_por' => $actor, 'principal' => $changePrimary ? ($principal && $active) : null,
                'principal_antes' => $primary];
            $this->write($data);
            $this->pdo->prepare('INSERT INTO auditoria (tabela, registro_id, acao, dados_anteriores, dados_novos, usuario_id, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                'produto_fornecedores', $product, $old ? 'UPDATE' : 'INSERT', $old ? json_encode($old, JSON_THROW_ON_ERROR) : null,
                json_encode($data, JSON_THROW_ON_ERROR), $actor, $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null]);
            SyncQueueHelper::queueUpdate('produto_fornecedores', $product, $data);
            return $data['evento_id'];
        });
    }

    public function receber(string $action, int $recordId, array $data): void {
        if (!$this->pdo->inTransaction()) throw new RuntimeException('Recebimento de vínculo exige transação.');
        $fields = ['evento_id', 'produto_id', 'fornecedor_id', 'ativo', 'criado_por', 'principal', 'principal_antes'];
        if ($action !== 'UPDATE' || array_diff(array_keys($data), $fields) || array_diff($fields, array_keys($data)) ||
            !is_string($data['evento_id']) || !preg_match('/^[a-f0-9]{32}$/D', $data['evento_id']) ||
            !is_int($data['produto_id']) || $recordId !== $data['produto_id'] || !is_int($data['fornecedor_id']) ||
            !in_array($data['ativo'], [0, 1], true) || !in_array($data['principal'], [null, false, true], true) ||
            ($data['criado_por'] !== null && (!is_int($data['criado_por']) || $data['criado_por'] <= 0)) ||
            ($data['principal_antes'] !== null && (!is_int($data['principal_antes']) || $data['principal_antes'] <= 0)) ||
            ($data['principal'] === true && $data['ativo'] !== 1)) throw new RuntimeException('Evento de vínculo inválido.');
        [$p] = $this->parents($data['produto_id'], $data['fornecedor_id']);
        $stmt = $this->pdo->prepare("SELECT registro_id, acao, dados FROM sincronizacao WHERE tabela = 'produto_fornecedores' AND JSON_UNQUOTE(JSON_EXTRACT(dados, '$.evento_id')) = ? LIMIT 1");
        $stmt->execute([$data['evento_id']]); $receipt = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($receipt) {
            if ((int) $receipt['registro_id'] !== $recordId || $receipt['acao'] !== $action || json_decode($receipt['dados'], true, 512, JSON_THROW_ON_ERROR) != $data) throw new RuntimeException('Conteúdo divergente do vínculo recebido.');
            return;
        }
        $current = $p['fornecedor_principal_id'] === null ? null : (int) $p['fornecedor_principal_id'];
        if ($data['principal'] === true && $current !== $data['principal_antes'] && $current !== $data['fornecedor_id']) throw new RuntimeException('Fornecedor principal alterado no destino.');
        $this->write($data);
        $this->pdo->prepare('INSERT INTO sincronizacao (tabela, registro_id, acao, dados, sincronizado, tentativas, criado_em, sincronizado_em) VALUES (?, ?, ?, ?, 1, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)')->execute([
            'produto_fornecedores', $recordId, $action, json_encode($data, JSON_THROW_ON_ERROR),
        ]);
    }

    private function parents(int $product, int $supplier): array {
        if ($product <= 0 || $supplier <= 0) throw new RuntimeException('Produto ou fornecedor inválido.');
        $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $stmt = $this->pdo->prepare('SELECT id, ativo, fornecedor_principal_id FROM produtos WHERE id = ?' . $lock);
        $stmt->execute([$product]); $p = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt = $this->pdo->prepare('SELECT id, ativo FROM fornecedores WHERE id = ?' . $lock);
        $stmt->execute([$supplier]); $s = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$p || !$s) throw new RuntimeException('Sincronize o cadastro do produto e do fornecedor antes do vínculo.');
        return [$p, $s];
    }

    private function write(array $data): void {
        $stmt = $this->pdo->prepare('SELECT id FROM produto_fornecedores WHERE produto_id = ? AND fornecedor_id = ?');
        $stmt->execute([$data['produto_id'], $data['fornecedor_id']]);
        if ($stmt->fetchColumn()) {
            $this->pdo->prepare('UPDATE produto_fornecedores SET ativo = ?, atualizado_em = CURRENT_TIMESTAMP WHERE produto_id = ? AND fornecedor_id = ?')->execute([$data['ativo'], $data['produto_id'], $data['fornecedor_id']]);
        } else {
            $this->pdo->prepare('INSERT INTO produto_fornecedores (produto_id, fornecedor_id, ativo, criado_por) VALUES (?, ?, ?, ?)')->execute([$data['produto_id'], $data['fornecedor_id'], $data['ativo'], $data['criado_por']]);
        }
        if ($data['principal'] === true) $this->pdo->prepare('UPDATE produtos SET fornecedor_principal_id = ?, atualizado_em = CURRENT_TIMESTAMP WHERE id = ?')->execute([$data['fornecedor_id'], $data['produto_id']]);
        if (!$data['ativo'] || $data['principal'] === false) $this->pdo->prepare('UPDATE produtos SET fornecedor_principal_id = NULL, atualizado_em = CURRENT_TIMESTAMP WHERE id = ? AND fornecedor_principal_id = ?')->execute([$data['produto_id'], $data['fornecedor_id']]);
    }

    public function listFor(string $side, int $id): array {
        if (!in_array($side, ['produto', 'fornecedor'], true) || $id <= 0) throw new RuntimeException('Consulta de vínculos inválida.');
        $column = $side === 'produto' ? 'produto_id' : 'fornecedor_id';
        $stmt = $this->pdo->prepare('SELECT pf.produto_id, pf.fornecedor_id, p.nome AS produto, p.codigo, f.razao_social AS fornecedor, f.cnpj, f.cpf, CASE WHEN p.fornecedor_principal_id = f.id THEN 1 ELSE 0 END AS principal FROM produto_fornecedores pf JOIN produtos p ON p.id = pf.produto_id JOIN fornecedores f ON f.id = pf.fornecedor_id WHERE pf.' . $column . ' = ? AND pf.ativo = 1 AND p.ativo = 1 AND f.ativo = 1 ORDER BY p.nome, f.razao_social');
        $stmt->execute([$id]); return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function verifyNfe(PDO $pdo, array $data): void {
        if (!array_key_exists('produto_fornecedor_eventos', $data)) return; // notas legadas
        $events = $data['produto_fornecedor_eventos'];
        if (!is_array($events)) throw new RuntimeException('Vínculos da NF-e inválidos.');
        foreach ($data['itens'] as $product => $quantity) {
            $event = $events[$product] ?? null;
            if (!is_string($event)) throw new RuntimeException('Sincronize os vínculos antes da NF-e.');
            $stmt = $pdo->prepare("SELECT dados FROM sincronizacao WHERE tabela = 'produto_fornecedores' AND registro_id = ? AND JSON_UNQUOTE(JSON_EXTRACT(dados, '$.evento_id')) = ? LIMIT 1");
            $stmt->execute([(int) $product, $event]); $raw = $stmt->fetchColumn();
            $link = $raw === false ? null : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!$link || $link['fornecedor_id'] !== (int) $data['fornecedor'] || $link['ativo'] !== 1) throw new RuntimeException('Vínculo do fornecedor da NF-e não recebido.');
        }
    }

    private function transaction(callable $fn) {
        $own = !$this->pdo->inTransaction();
        if ($own) $this->pdo->beginTransaction(); else $this->pdo->exec('SAVEPOINT product_supplier');
        try {
            $result = $fn();
            if ($own) $this->pdo->commit(); else $this->pdo->exec('RELEASE SAVEPOINT product_supplier');
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                if ($own) $this->pdo->rollBack(); else { $this->pdo->exec('ROLLBACK TO SAVEPOINT product_supplier'); $this->pdo->exec('RELEASE SAVEPOINT product_supplier'); }
            }
            throw $e;
        }
    }
}
