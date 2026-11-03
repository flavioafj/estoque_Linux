<?php
namespace Services;

use Helpers\SyncQueueHelper;
use Models\Database;
use Models\Movimentacao;
use Models\MovimentacaoItem;
use PDO;
use RuntimeException;
use Throwable;

/** Entrada e evento de sincronização são confirmados na mesma transação. */
final class EntradaManualService
{
    private Database $db;
    private PDO $pdo;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->pdo = $this->db->getConnection();
    }

    public function registrar(array $dados): int
    {
        $dados['evento_id'] = bin2hex(random_bytes(16));
        return $this->processar($dados, false);
    }

    public function receber(array $dados): int
    {
        if (!is_string($dados['evento_id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $dados['evento_id'])) {
            throw new RuntimeException('Identificador da entrada manual inválido.');
        }
        return $this->processar($dados, true);
    }

    private function processar(array $dados, bool $recebido): int
    {
        $propriaTransacao = !$this->pdo->inTransaction();
        if ($propriaTransacao) {
            $this->pdo->beginTransaction();
        }
        try {
            if ($recebido) {
                // Uma linha estável serializa recebimentos concorrentes até o commit do chamador.
                $mutex = $this->pdo->query("SELECT id FROM tipos_movimentacao WHERE tipo = 'ENTRADA' ORDER BY id LIMIT 1 FOR UPDATE");
                if (!$mutex->fetchColumn()) {
                    throw new RuntimeException('Nenhum tipo de entrada configurado.');
                }
                // Leitura com bloqueio vê inclusive eventos confirmados enquanto aguardávamos o mutex.
                $stmt = $this->pdo->prepare("SELECT registro_id FROM sincronizacao WHERE acao = 'ENTRADA_MANUAL' AND JSON_UNQUOTE(JSON_EXTRACT(dados, '$.evento_id')) = ? LIMIT 1 FOR UPDATE");
                $stmt->execute([$dados['evento_id']]);
                $existente = $stmt->fetchColumn();
                if ($existente !== false) {
                    if ($propriaTransacao) {
                        $this->pdo->commit();
                    }
                    return (int) $existente;
                }
            }

            $dados = $this->validar($dados);
            $mov = new Movimentacao();
            $movId = $mov->criar($dados['tipo_movimentacao_id'], $dados['user_id'], $dados['observacao']);
            if (!$movId || !(new MovimentacaoItem())->adicionarItens($movId, $dados['itens'], $dados['valores'])) {
                throw new RuntimeException('Falha ao registrar a entrada e seus itens.');
            }
            // execute(), em vez de rowCount(), também aceita uma entrada de valor zero.
            $stmt = $this->pdo->prepare('UPDATE movimentacoes SET valor_total = ? WHERE id = ?');
            if (!$stmt->execute([$dados['total'], $movId])) {
                throw new RuntimeException('Falha ao atualizar o total da entrada.');
            }
            $mov->logAudit('INSERT', $movId, null, $dados);
            if ($recebido) {
                // Recibo confirmado evita reenvio e duplicação, inclusive no PULL.
                $stmt = $this->pdo->prepare("INSERT INTO sincronizacao (tabela, registro_id, acao, dados, sincronizado, tentativas, criado_em, sincronizado_em) VALUES ('movimentacoes', ?, 'ENTRADA_MANUAL', ?, 1, 0, NOW(), NOW())");
                if (!$stmt->execute([$movId, json_encode($dados, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)])) {
                    throw new RuntimeException('Falha ao registrar o recebimento da entrada.');
                }
            } else {
                SyncQueueHelper::queueChange('movimentacoes', $movId, 'ENTRADA_MANUAL', $dados);
            }
            if ($propriaTransacao) {
                $this->pdo->commit();
            }
            return (int) $movId;
        } catch (Throwable $e) {
            if ($propriaTransacao && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function validar(array $dados): array
    {
        $tipoId = filter_var($dados['tipo_movimentacao_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $usuarioId = filter_var($dados['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$tipoId || !$usuarioId || !is_array($dados['itens'] ?? null) || !is_array($dados['valores'] ?? null)) {
            throw new RuntimeException('Dados da entrada manual incompletos.');
        }
        $stmt = $this->pdo->prepare("SELECT id FROM tipos_movimentacao WHERE id = ? AND tipo = 'ENTRADA'");
        $stmt->execute([$tipoId]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('Selecione um tipo de movimentação de entrada válido.');
        }
        $itens = $valores = [];
        $total = 0.0;
        foreach ($dados['itens'] as $id => $qtd) {
            if (!is_numeric($qtd) || !is_finite((float) $qtd) || (float) $qtd < 0 || floor((float) $qtd) != (float) $qtd || (float) $qtd > PHP_INT_MAX) {
                throw new RuntimeException('As quantidades devem ser inteiras e não negativas.');
            }
            if ((float) $qtd == 0) {
                continue;
            }
            $produtoId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $valor = $dados['valores'][$id] ?? null;
            if (!$produtoId || !is_numeric($valor) || !is_finite((float) $valor) || (float) $valor < 0) {
                throw new RuntimeException('Produto ou valor unitário inválido.');
            }
            $stmt = $this->pdo->prepare('SELECT id FROM produtos WHERE id = ?');
            $stmt->execute([$produtoId]);
            if (!$stmt->fetchColumn()) {
                throw new RuntimeException('Produto não encontrado: ' . $produtoId);
            }
            $itens[$produtoId] = (int) $qtd;
            $valores[$produtoId] = (float) $valor;
            $total += $itens[$produtoId] * $valores[$produtoId];
        }
        if (!$itens || !is_finite($total)) {
            throw new RuntimeException('Adicione produtos e valores válidos à entrada.');
        }
        if (isset($dados['total']) && (!is_numeric($dados['total']) || !is_finite((float) $dados['total']) || abs((float) $dados['total'] - $total) > 0.01)) {
            throw new RuntimeException('Total da entrada divergente dos itens.');
        }
        if (isset($dados['observacao']) && !is_string($dados['observacao'])) {
            throw new RuntimeException('Observação inválida.');
        }
        $observacao = trim($dados['observacao'] ?? '');
        return [
            'evento_id' => $dados['evento_id'],
            'tipo_movimentacao_id' => (int) $tipoId,
            'user_id' => (int) $usuarioId,
            'observacao' => $observacao !== '' ? $observacao : 'Entrada manual.',
            'itens' => $itens,
            'valores' => $valores,
            'total' => $total,
        ];
    }
}
