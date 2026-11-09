<?php
namespace Services;

use PDO;
use RuntimeException;
use Throwable;
use Helpers\SyncQueueHelper;

/** Persistência do emitente na origem. Recebimentos nunca passam por este método. */
final class NfeSupplierService {
    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function save(array $input, int $actorId, ?int $selectedId = null): array {
        $cnpj = SupplierData::documentKey($input['cnpj'] ?? null);
        if (!is_string($cnpj) || !preg_match('/^[0-9]{14}$/D', $cnpj)) throw new RuntimeException('CNPJ inválido na NF-e.');
        $input['cnpj'] = $cnpj;
        $input['tipo'] = 'PJ';
        $provided = SupplierData::normalize($input);
        $own = !$this->pdo->inTransaction();
        if ($own) $this->pdo->beginTransaction(); else $this->pdo->exec('SAVEPOINT nfe_supplier');
        try {
            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            // Mesmo mutex usado pelo receptor, inclusive para CNPJs ainda não cadastrados.
            if (!$this->pdo->query('SELECT id FROM perfis WHERE id = 2' . $lock)->fetchColumn()) throw new RuntimeException('Perfil de referência não configurado.');
            $stmt = $this->pdo->prepare("SELECT * FROM fornecedores WHERE REPLACE(REPLACE(REPLACE(REPLACE(cnpj, '.', ''), '/', ''), '-', ''), ' ', '') = ?" . $lock);
            $stmt->execute([$cnpj]);
            $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($matches) > 1) throw new RuntimeException('Mais de um fornecedor com o CNPJ da nota; reconciliação necessária.');
            $old = $matches[0] ?? null;
            if ($selectedId !== null && (!$old || (int) $old['id'] !== $selectedId)) throw new RuntimeException('CNPJ do fornecedor selecionado não corresponde ao da nota.');
            $eventId = null;
            if (!$old) {
                SupplierData::assertUnique($this->pdo, $provided);
                $record = $provided + ['ativo' => 1, 'criado_por' => $actorId];
                $this->pdo->prepare('INSERT INTO fornecedores (' . implode(', ', array_keys($record)) . ') VALUES (' . implode(', ', array_fill(0, count($record), '?')) . ')')->execute(array_values($record));
                $id = (int) $this->pdo->lastInsertId();
                $stmt = $this->pdo->prepare('SELECT * FROM fornecedores WHERE id = ?'); $stmt->execute([$id]);
                $after = $stmt->fetch(PDO::FETCH_ASSOC);
                $eventId = bin2hex(random_bytes(16));
                $this->audit('INSERT', $id, $actorId, null, $after);
                SyncQueueHelper::queueInsert('fornecedores', $id, ['evento_id' => $eventId] + $after);
            } else {
                $id = (int) $old['id'];
                if ($old['tipo'] !== 'PJ') throw new RuntimeException('O fornecedor da NF-e deve ser do tipo PJ.');
                $before = $changes = [];
                // Dados ausentes/vazios no XML não apagam contatos e endereço existentes.
                foreach ($provided as $field => $value) {
                    if (in_array($field, ['cnpj', 'cpf', 'tipo'], true) || $value === null) continue;
                    if ($old[$field] !== $value) { $before[$field] = $old[$field]; $changes[$field] = $value; }
                }
                if ($changes) {
                    $sql = implode(', ', array_map(fn($field) => $field . ' = ?', array_keys($changes)));
                    $this->pdo->prepare('UPDATE fornecedores SET ' . $sql . ', atualizado_em = CURRENT_TIMESTAMP WHERE id = ?')->execute(array_merge(array_values($changes), [$id]));
                    $eventId = bin2hex(random_bytes(16));
                    $this->audit('UPDATE', $id, $actorId, $old, $changes);
                    SyncQueueHelper::queueUpdate('fornecedores', $id, ['evento_id' => $eventId, 'id' => $id,
                        'cnpj' => $old['cnpj'], 'cpf' => $old['cpf'], 'tipo' => $old['tipo'], 'antes' => $before, 'alteracoes' => $changes]);
                } else {
                    $stmt = $this->pdo->prepare("SELECT JSON_UNQUOTE(JSON_EXTRACT(dados, '$.evento_id')) FROM sincronizacao WHERE tabela = 'fornecedores' AND registro_id = ? AND acao IN ('INSERT', 'UPDATE') AND JSON_EXTRACT(dados, '$.evento_id') IS NOT NULL ORDER BY id DESC LIMIT 1");
                    $stmt->execute([$id]); $eventId = $stmt->fetchColumn() ?: null;
                }
            }
            if ($own) $this->pdo->commit(); else $this->pdo->exec('RELEASE SAVEPOINT nfe_supplier');
            return ['id' => $id, 'evento_id' => $eventId];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                if ($own) $this->pdo->rollBack(); else { $this->pdo->exec('ROLLBACK TO SAVEPOINT nfe_supplier'); $this->pdo->exec('RELEASE SAVEPOINT nfe_supplier'); }
            }
            throw $e;
        }
    }

    /** Uma nota nova não pode recriar o fornecedor se o evento dependente falhou. */
    public static function receivedSupplier(PDO $pdo, array $data): int {
        $eventId = $data['fornecedor_evento_id'] ?? null;
        $id = filter_var($data['fornecedor'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id || !is_string($eventId) || !preg_match('/^[a-f0-9]{32}$/D', $eventId)) throw new RuntimeException('Referência do fornecedor da nota inválida.');
        $stmt = $pdo->prepare("SELECT registro_id FROM sincronizacao WHERE tabela = 'fornecedores' AND JSON_UNQUOTE(JSON_EXTRACT(dados, '$.evento_id')) = ? LIMIT 1");
        $stmt->execute([$eventId]);
        if ((int) $stmt->fetchColumn() !== $id) throw new RuntimeException('Sincronize o evento do fornecedor antes da NF-e.');
        $stmt = $pdo->prepare('SELECT cnpj FROM fornecedores WHERE id = ?'); $stmt->execute([$id]);
        $cnpj = $stmt->fetchColumn();
        if ($cnpj === false || SupplierData::documentKey($cnpj) !== SupplierData::documentKey($data['cnpj'] ?? null)) throw new RuntimeException('Fornecedor da NF-e divergente no destino.');
        return $id;
    }

    private function audit(string $action, int $id, int $actorId, ?array $before, array $after): void {
        $this->pdo->prepare('INSERT INTO auditoria (tabela, registro_id, acao, dados_anteriores, dados_novos, usuario_id, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['fornecedores', $id, $action, $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR), json_encode($after, JSON_THROW_ON_ERROR), $actorId,
                $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null]);
    }
}
