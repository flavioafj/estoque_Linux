<?php
namespace Services;

use Helpers\SyncQueueHelper;
use Models\Database;
use Models\Movimentacao;
use Models\MovimentacaoItem;
use PDO;
use RuntimeException;
use Throwable;

/** Ajuste, vínculo e evento são confirmados juntos. */
final class CorrecaoSaidaService
{
    public function registrar(int $originalId, array $itens, int $usuarioId): int
    {
        return $this->processar(['evento_id' => bin2hex(random_bytes(16)), 'original_id' => $originalId,
            'itens' => $itens, 'usuario_id' => $usuarioId], false);
    }

    public function receber(array $dados): int
    {
        if (!is_string($dados['evento_id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $dados['evento_id'])) {
            throw new RuntimeException('Identificador de correção inválido.');
        }
        return $this->processar($dados, true);
    }

    private function processar(array $dados, bool $recebido): int
    {
        $pdo = Database::getInstance()->getConnection();
        $propria = !$pdo->inTransaction();
        if ($propria) $pdo->beginTransaction();
        try {
            // Serializa reenvios, inclusive antes de localizar a movimentação original.
            $pdo->query('SELECT id FROM tipos_movimentacao WHERE id = 5 FOR UPDATE')->fetchColumn();
            if ($recebido) {
                $stmt = $pdo->prepare("SELECT registro_id FROM sincronizacao WHERE acao = 'CORRECAO_SAIDA' AND JSON_UNQUOTE(JSON_EXTRACT(dados, '$.evento_id')) = ? FOR UPDATE");
                $stmt->execute([$dados['evento_id']]);
                $existente = $stmt->fetchColumn();
                if ($existente !== false) {
                    if ($propria) $pdo->commit();
                    return (int) $existente;
                }
                // O ID no evento pertence à origem, nunca ao banco de destino.
                $stmt = $pdo->prepare("SELECT dados FROM sincronizacao WHERE tabela = 'movimentacoes' AND registro_id = ? AND acao IN ('SAIDA_DIRETA', 'SAIDA_DIRETA_ADM') AND sincronizado = 1 ORDER BY id DESC");
                $stmt->execute([$dados['original_id']]);
                $originalId = 0;
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $evento) {
                    $ref = json_decode($evento['dados'], true, 512, JSON_THROW_ON_ERROR);
                    if (($ref['_movimento_destino_id'] ?? 0) > 0 && isset($dados['original_evento'])) {
                        $destino = $ref['_movimento_destino_id'];
                        unset($ref['_movimento_destino_id']);
                        if ($ref != $dados['original_evento']) continue;
                        $originalId = (int) $destino;
                        break;
                    }
                }
                if (!$originalId) throw new RuntimeException('Saída original sem referência no destino. Sincronize a saída com a versão atual antes da correção.');
            } else {
                $originalId = (int) $dados['original_id'];
                $stmt = $pdo->prepare("SELECT dados FROM sincronizacao WHERE tabela = 'movimentacoes' AND registro_id = ? AND acao IN ('SAIDA_DIRETA', 'SAIDA_DIRETA_ADM') ORDER BY id DESC LIMIT 1");
                $stmt->execute([$originalId]);
                $evento = $stmt->fetchColumn();
                if ($evento !== false) $dados['original_evento'] = json_decode($evento, true, 512, JSON_THROW_ON_ERROR);
            }
            $stmt = $pdo->prepare('SELECT * FROM movimentacoes WHERE id = ? FOR UPDATE');
            $stmt->execute([$originalId]);
            $original = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$original || $original['corrigida'] || (int) $original['usuario_id'] !== (int) $dados['usuario_id']) {
                throw new RuntimeException('Saída inválida ou já corrigida.');
            }
            $mov = new Movimentacao();
            $ultimoId = 0;
            if (empty($dados['itens']) || !is_array($dados['itens'])) throw new RuntimeException('Correção sem itens.');
            // Cada sinal exige seu próprio tipo: entrada para devolução, saída para retirada adicional.
            foreach ($dados['itens'] as $produtoId => $diferenca) {
                if (!is_numeric($diferenca) || !is_finite((float) $diferenca) || (float) $diferenca == 0) throw new RuntimeException('Quantidade de correção inválida.');
                $stmt = $pdo->prepare('SELECT quantidade, valor_unitario FROM movimentacao_itens WHERE movimentacao_id = ? AND produto_id = ?');
                $stmt->execute([$originalId, $produtoId]);
                $item = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$item || $item['quantidade'] + $diferenca < 0) throw new RuntimeException('Item inválido na correção.');
                $qtd = abs((float) $diferenca);
                $valor = (float) $item['valor_unitario'];
                $ultimoId = $mov->criar($diferenca > 0 ? 7 : 3, (int) $dados['usuario_id']);
                if (!$ultimoId || !(new MovimentacaoItem())->adicionarItens($ultimoId, [$produtoId => $qtd], [$produtoId => $valor])) {
                    throw new RuntimeException('Falha ao registrar ajuste.');
                }
                $stmt = $pdo->prepare('UPDATE movimentacoes SET valor_total = ? WHERE id = ?');
                if (!$stmt->execute([$qtd * $valor, $ultimoId])) throw new RuntimeException('Falha ao atualizar total.');
            }
            $stmt = $pdo->prepare('UPDATE movimentacoes SET movimentacao_original_id = ?, corrigida = 1 WHERE id = ?');
            if (!$stmt->execute([$ultimoId, $originalId])) throw new RuntimeException('Falha ao vincular correção.');
            if ($recebido) {
                $stmt = $pdo->prepare("INSERT INTO sincronizacao (tabela, registro_id, acao, dados, sincronizado, tentativas, criado_em, sincronizado_em) VALUES ('movimentacoes', ?, 'CORRECAO_SAIDA', ?, 1, 0, NOW(), NOW())");
                if (!$stmt->execute([$ultimoId, json_encode($dados, JSON_THROW_ON_ERROR)])) throw new RuntimeException('Falha ao registrar recibo.');
            } else {
                SyncQueueHelper::queueChange('movimentacoes', $ultimoId, 'CORRECAO_SAIDA', $dados);
            }
            if ($propria) $pdo->commit();
            return (int) $ultimoId;
        } catch (Throwable $e) {
            if ($propria && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
