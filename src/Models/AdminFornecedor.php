<?php
namespace Models;

use Helpers\SyncQueueHelper;
use Services\SupplierData;
use PDO;
use Throwable;
use InvalidArgumentException;

/** Fluxo administrativo separado do cadastro embutido na NF-e. */
class AdminFornecedor extends Fornecedor {
    public function addSupplier(array $input, int $actorId): int {
        $data = SupplierData::normalize($input);
        $pdo = $this->db->getConnection();
        $pdo->beginTransaction();
        try {
            SupplierData::assertUnique($pdo, $data);
            $data['ativo'] = 1;
            $data['criado_por'] = $actorId;
            $fields = implode(', ', array_keys($data));
            $pdo->prepare('INSERT INTO fornecedores (' . $fields . ') VALUES (' . implode(', ', array_fill(0, count($data), '?')) . ')')->execute(array_values($data));
            $id = (int) $pdo->lastInsertId();
            $stmt = $pdo->prepare('SELECT * FROM fornecedores WHERE id = ?');
            $stmt->execute([$id]);
            $created = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->audit($pdo, 'INSERT', $id, $actorId, null, $created);
            SyncQueueHelper::queueInsert('fornecedores', $id, ['evento_id' => bin2hex(random_bytes(16))] + $created);
            $pdo->commit();
            return $id;
        } catch (Throwable $e) {
            $pdo->rollBack();
            if ($e instanceof \PDOException && $e->getCode() === '23000') throw new InvalidArgumentException('Não foi possível cadastrar: documento duplicado ou autor indisponível.');
            throw $e;
        }
    }

    public function editSupplier(int $id, array $input, int $actorId): void {
        if ($id <= 0) throw new InvalidArgumentException('Fornecedor inválido.');
        $data = SupplierData::normalize($input);
        $pdo = $this->db->getConnection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM fornecedores WHERE id = ? AND ativo = 1' . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
            $stmt->execute([$id]);
            $old = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$old) throw new InvalidArgumentException('Fornecedor ativo não encontrado.');
            SupplierData::assertUnique($pdo, $data, $id);
            $before = $changes = [];
            foreach ($data as $field => $value) {
                if ($old[$field] !== $value) { $before[$field] = $old[$field]; $changes[$field] = $value; }
            }
            if ($changes) {
                $sql = implode(', ', array_map(fn($field) => $field . ' = ?', array_keys($changes)));
                $pdo->prepare('UPDATE fornecedores SET ' . $sql . ', atualizado_em = CURRENT_TIMESTAMP WHERE id = ?')->execute(array_merge(array_values($changes), [$id]));
                $this->audit($pdo, 'UPDATE', $id, $actorId, $old, $changes);
                SyncQueueHelper::queueUpdate('fornecedores', $id, ['evento_id' => bin2hex(random_bytes(16)), 'id' => $id,
                    'cnpj' => $old['cnpj'], 'cpf' => $old['cpf'], 'tipo' => $old['tipo'], 'razao_social' => $old['razao_social'],
                    'antes' => $before, 'alteracoes' => $changes]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            if ($e instanceof \PDOException && $e->getCode() === '23000') throw new InvalidArgumentException('Documento já cadastrado em outro fornecedor.');
            throw $e;
        }
    }

    public function deactivateSupplier(int $id, int $actorId): void {
        if ($id <= 0) throw new InvalidArgumentException('Fornecedor inválido.');
        $pdo = $this->db->getConnection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM fornecedores WHERE id = ? AND ativo = 1' . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
            $stmt->execute([$id]);
            $old = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$old) throw new InvalidArgumentException('Fornecedor ativo não encontrado.');
            $pdo->prepare('UPDATE fornecedores SET ativo = 0, atualizado_em = CURRENT_TIMESTAMP WHERE id = ?')->execute([$id]);
            $this->audit($pdo, 'UPDATE', $id, $actorId, $old, ['ativo' => 0]);
            SyncQueueHelper::queueUpdate('fornecedores', $id, [
                'evento_id' => bin2hex(random_bytes(16)), 'id' => $id, 'ativo' => 0,
                'razao_social' => $old['razao_social'], 'tipo' => $old['tipo'], 'cnpj' => $old['cnpj'], 'cpf' => $old['cpf']
            ]);
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    }

    private function audit(PDO $pdo, string $action, int $id, int $actorId, ?array $before, array $after): void {
        $pdo->prepare('INSERT INTO auditoria (tabela, registro_id, acao, dados_anteriores, dados_novos, usuario_id, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['fornecedores', $id, $action, $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR), json_encode($after, JSON_THROW_ON_ERROR), $actorId,
                $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null]);
    }
}
