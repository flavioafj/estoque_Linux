<?php
namespace Services;

use InvalidArgumentException;

/** Campos de cadastro compartilhados pela página e pelo receptor de sincronização. */
final class SupplierData {
    public const LIMITS = ['razao_social' => 200, 'nome_fantasia' => 200, 'cnpj' => 20, 'cpf' => 14,
        'email' => 100, 'telefone' => 20, 'celular' => 20, 'endereco' => 255, 'cidade' => 100, 'estado' => 2, 'cep' => 10];

    public static function normalize(array $input): array {
        $data = [];
        foreach (self::LIMITS + ['observacoes' => null] as $field => $limit) {
            $value = $input[$field] ?? null;
            if ($value !== null && !is_string($value)) throw new InvalidArgumentException('Valor inválido no campo ' . $field . '.');
            $value = $value === null ? null : trim($value);
            if ($value === '') $value = null;
            if ($value !== null && (($limit !== null && mb_strlen($value) > $limit) || ($field === 'observacoes' && strlen($value) > 65535))) {
                throw new InvalidArgumentException('Limite de tamanho excedido no campo ' . $field . '.');
            }
            $data[$field] = $value;
        }
        if ($data['razao_social'] === null) throw new InvalidArgumentException('Informe a razão social ou o nome do fornecedor.');
        $data['tipo'] = $input['tipo'] ?? 'PJ';
        if (!in_array($data['tipo'], ['PJ', 'PF'], true)) throw new InvalidArgumentException('Selecione o tipo PJ ou PF.');
        if ($data['email'] !== null && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Informe um e-mail válido ou deixe o campo vazio.');
        if ($data['estado'] !== null) {
            $data['estado'] = strtoupper($data['estado']);
            if (!preg_match('/^[A-Z]{2}$/D', $data['estado'])) throw new InvalidArgumentException('Informe a UF com duas letras ou deixe o campo vazio.');
        }
        return $data;
    }

    /** Trata documentos com e sem pontuação como a mesma identificação. */
    public static function documentKey(?string $document): ?string {
        return $document === null ? null : strtoupper(preg_replace('/[.\/\-\s]/u', '', $document));
    }

    public static function assertUnique(\PDO $pdo, array $data, ?int $excludeId = null): void {
        foreach (['cnpj', 'cpf'] as $field) {
            if ($data[$field] === null) continue;
            $stmt = $pdo->prepare("SELECT id FROM fornecedores WHERE UPPER(REPLACE(REPLACE(REPLACE(REPLACE($field, '.', ''), '/', ''), '-', ''), ' ', '')) = ?" . ($excludeId === null ? '' : ' AND id <> ?'));
            $params = [self::documentKey($data[$field])];
            if ($excludeId !== null) $params[] = $excludeId;
            $stmt->execute($params);
            if ($stmt->fetchColumn()) throw new InvalidArgumentException(strtoupper($field) . ' já cadastrado, inclusive entre fornecedores inativos.');
        }
    }
}
