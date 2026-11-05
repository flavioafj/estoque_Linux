<?php
namespace Services;

use PDO;
use RuntimeException;

/** Aplica eventos sem chamar Product: recebimentos não geram novos envios. */
final class ProductSyncService
{
    private PDO $pdo;
    private const FIELDS = ['nome', 'codigo', 'codigo_barras', 'categoria_id', 'unidade_medida_id',
        'estoque_atual', 'estoque_minimo', 'estoque_maximo', 'preco_custo', 'preco_venda',
        'margem_lucro', 'localizacao', 'fornecedor_principal_id', 'descricao', 'ativo',
        'criado_por', 'foto_url', 'observacoes', 'criado_em', 'atualizado_em'];

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    /** A transação pertence ao receptor PUSH/PULL. Os IDs são compartilhados entre as bases. */
    public function receber(string $action, int $id, array $data): void
    {
        if (!$this->pdo->inTransaction()) throw new RuntimeException('Recebimento de produto exige transação.');
        if ($id <= 0 || !in_array($action, ['INSERT', 'UPDATE', 'DELETE'], true)) {
            throw new RuntimeException('Evento de produto inválido.');
        }
        if (isset($data['id']) && (int) $data['id'] !== $id) throw new RuntimeException('ID de produto divergente.');
        unset($data['id']);
        if (array_diff(array_keys($data), self::FIELDS)) throw new RuntimeException('Campo de produto não permitido.');
        $stmt = $this->pdo->prepare('SELECT * FROM produtos WHERE id = ?' .
            ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
        $stmt->execute([$id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($action === 'DELETE') {
            // Nunca apaga o histórico e os vínculos de um produto recebido.
            $data = ['ativo' => 0];
            if (!$existing) return;
        } elseif (!$data) {
            throw new RuntimeException('Evento de produto sem campos.');
        }
        if ($action === 'INSERT') {
            if (empty($data['nome']) || empty($data['categoria_id']) || empty($data['unidade_medida_id'])) {
                throw new RuntimeException('Cadastro de produto incompleto.');
            }
            if ($existing) {
                // Reenvio após perda da resposta é seguro; colisões exigem reconciliação.
                foreach ($data as $field => $value) {
                    $current = $existing[$field];
                    $equal = $value === null || $current === null ? $value === $current :
                        ((string) $value === (string) $current ||
                        (is_numeric($value) && is_numeric($current) && (float) $value === (float) $current));
                    if (!$equal) throw new RuntimeException('Conflito no ID do produto; reconciliação necessária.');
                }
                return;
            }
            $data = ['id' => $id] + $data;
            $columns = implode(', ', array_keys($data));
            $sql = 'INSERT INTO produtos (' . $columns . ') VALUES (' . implode(', ', array_fill(0, count($data), '?')) . ')';
            $this->pdo->prepare($sql)->execute(array_values($data));
            return;
        }
        if (!$existing) throw new RuntimeException('Produto recebido não existe no destino.');
        $assignments = implode(', ', array_map(fn($field) => $field . ' = ?', array_keys($data)));
        $this->pdo->prepare('UPDATE produtos SET ' . $assignments . ' WHERE id = ?')->execute(array_merge(array_values($data), [$id]));
    }
}
