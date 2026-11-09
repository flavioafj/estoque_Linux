<?php
namespace Services;

use PDO;
use RuntimeException;

/** Receptor do cadastro administrativo e da NF-e; transação do PUSH/PULL. */
final class SupplierSyncService {
    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function receber(string $action, int $id, array $data): void {
        if (!$this->pdo->inTransaction()) throw new RuntimeException('Recebimento de fornecedor exige transação.');
        if ($id <= 0 || !in_array($action, ['INSERT', 'UPDATE', 'DELETE'], true) ||
            !is_string($data['evento_id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $data['evento_id']) || (int) ($data['id'] ?? 0) !== $id) {
            throw new RuntimeException('Identificação do evento de fornecedor inválida.');
        }
        $editing = $action === 'UPDATE' && array_key_exists('alteracoes', $data);
        $allowed = $action === 'INSERT' ? array_merge(array_keys(SupplierData::LIMITS), ['tipo', 'observacoes', 'ativo', 'criado_por', 'criado_em', 'atualizado_em', 'evento_id', 'id'])
            : ($editing ? ['evento_id', 'id', 'cnpj', 'cpf', 'tipo', 'antes', 'alteracoes'] : ['evento_id', 'id', 'razao_social', 'tipo', 'cnpj', 'cpf', 'ativo']);
        if (array_diff(array_keys($data), $allowed)) throw new RuntimeException('Campo de fornecedor não permitido.');
        if ($action === 'INSERT') {
            $normalized = SupplierData::normalize($data);
            if ((int) ($data['ativo'] ?? 0) !== 1) throw new RuntimeException('Cadastro recebido deve estar ativo.');
            foreach (['criado_em', 'atualizado_em'] as $field) {
                if (!is_string($data[$field] ?? null) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $data[$field])) throw new RuntimeException('Data de fornecedor inválida.');
            }
            if (($data['criado_por'] ?? null) !== null && !filter_var($data['criado_por'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) throw new RuntimeException('Autor de fornecedor inválido.');
        } elseif ($editing) {
            if (!is_array($data['antes'] ?? null) || !is_array($data['alteracoes']) || !$data['alteracoes'] ||
                array_diff(array_keys($data['alteracoes']), array_merge(array_diff(array_keys(SupplierData::LIMITS), ['cnpj', 'cpf']), ['observacoes', 'ativo'])) ||
                array_diff_key($data['antes'], $data['alteracoes']) || array_diff_key($data['alteracoes'], $data['antes']) ||
                ($data['tipo'] ?? null) !== 'PJ' || !is_string($data['cnpj'] ?? null) || !array_key_exists('cpf', $data)) {
                throw new RuntimeException('Atualização de fornecedor inválida.');
            }
            $validated = SupplierData::normalize($data['alteracoes'] + ['razao_social' => 'Validação']);
            foreach ($data['alteracoes'] as $field => $value) {
                if ($field === 'ativo') {
                    if ($value !== 1 || !in_array($data['antes'][$field], [0, null], true)) throw new RuntimeException('Reativação de fornecedor inválida.');
                    continue;
                }
                if ($validated[$field] !== $value || (!is_string($data['antes'][$field]) && $data['antes'][$field] !== null)) throw new RuntimeException('Campo inválido na atualização de fornecedor.');
            }
        } else {
            if (($data['ativo'] ?? null) !== 0 || !is_string($data['razao_social'] ?? null) ||
                !in_array($data['tipo'] ?? null, ['PJ', 'PF'], true) || !array_key_exists('cnpj', $data) || !array_key_exists('cpf', $data)) {
                throw new RuntimeException('Evento de exclusão inválido.');
            }
            SupplierData::normalize($data);
        }
        // Serializa reenvios mesmo antes da criação do fornecedor no destino.
        $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $mutex = $this->pdo->query('SELECT id FROM perfis WHERE id = 2' . $lock)->fetchColumn();
        if (!$mutex) throw new RuntimeException('Perfil de referência não configurado no destino.');
        $stmt = $this->pdo->prepare("SELECT registro_id, acao, dados FROM sincronizacao WHERE tabela = 'fornecedores' AND JSON_UNQUOTE(JSON_EXTRACT(dados, '$.evento_id')) = ? LIMIT 1" . $lock);
        $stmt->execute([$data['evento_id']]);
        $previous = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($previous) {
            if ((int) $previous['registro_id'] !== $id || $previous['acao'] !== $action || json_decode($previous['dados'], true, 512, JSON_THROW_ON_ERROR) != $data) throw new RuntimeException('Conteúdo divergente do evento de fornecedor.');
            return;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM fornecedores WHERE id = ?' . $lock);
        $stmt->execute([$id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if ($existing['tipo'] !== $data['tipo'] || SupplierData::documentKey($existing['cnpj']) !== SupplierData::documentKey($data['cnpj']) ||
                SupplierData::documentKey($existing['cpf']) !== SupplierData::documentKey($data['cpf']) ||
                (!$editing && $existing['cnpj'] === null && $existing['cpf'] === null && $existing['razao_social'] !== $data['razao_social'])) {
                throw new RuntimeException('Conflito no ID do fornecedor; reconciliação necessária.');
            }
        }
        if ($action === 'INSERT') {
            if ($existing) {
                foreach ($normalized as $field => $value) {
                    if ($existing[$field] !== $value) throw new RuntimeException('Cadastro divergente de fornecedor; reconciliação necessária.');
                }
                // Não reativa contas desativadas ao repetir um cadastro.
            } else {
                SupplierData::assertUnique($this->pdo, $normalized);
                $record = ['id' => $id] + $normalized + ['ativo' => 1, 'criado_por' => $data['criado_por'] ?? null,
                    'criado_em' => $data['criado_em'], 'atualizado_em' => $data['atualizado_em']];
                $this->pdo->prepare('INSERT INTO fornecedores (' . implode(', ', array_keys($record)) . ') VALUES (' . implode(', ', array_fill(0, count($record), '?')) . ')')->execute(array_values($record));
            }
        } elseif ($editing) {
            if (!$existing) throw new RuntimeException('Sincronize o cadastro do fornecedor antes da atualização.');
            foreach ($data['alteracoes'] as $field => $value) {
                $current = $field === 'ativo' && $existing[$field] !== null ? (int) $existing[$field] : $existing[$field];
                if ($current !== $data['antes'][$field] && $current !== $value) throw new RuntimeException('Fornecedor alterado no destino; reconciliação necessária.');
            }
            $sql = implode(', ', array_map(fn($field) => $field . ' = ?', array_keys($data['alteracoes'])));
            $this->pdo->prepare('UPDATE fornecedores SET ' . $sql . ', atualizado_em = CURRENT_TIMESTAMP WHERE id = ?')->execute(array_merge(array_values($data['alteracoes']), [$id]));
        } else {
            if (!$existing) throw new RuntimeException('Sincronize o cadastro do fornecedor antes da exclusão.');
            $this->pdo->prepare('UPDATE fornecedores SET ativo = 0, atualizado_em = CURRENT_TIMESTAMP WHERE id = ?')->execute([$id]);
        }
        $this->pdo->prepare('INSERT INTO sincronizacao (tabela, registro_id, acao, dados, sincronizado, tentativas, criado_em, sincronizado_em) VALUES (?, ?, ?, ?, 1, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)')
            ->execute(['fornecedores', $id, $action, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    }
}
