<?php
namespace Services;

use PDO;
use RuntimeException;
use Throwable;

/** Recibo local e efeitos do evento pertencem à mesma transação. */
final class SyncPullService
{
    public const ACTIONS = ['INSERT', 'UPDATE', 'DELETE', 'SAIDA_DIRETA', 'SAIDA_DIRETA_ADM', 'ENTRADA_NFE', 'ENTRADA_MANUAL', 'CORRECAO_SAIDA', 'AJUSTE_INVENTARIO'];
    public const TABLES = ['produto_fornecedores', 'usuarios', 'produtos', 'categorias', 'fornecedores', 'unidades_medida', 'movimentacoes', 'movimentacao_itens', 'estoques', 'notas_fiscais', 'itens_nota'];
    private PDO $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function prepararRecibos(): void
    {
        if ($this->pdo->inTransaction()) throw new RuntimeException('Prepare os recibos antes de iniciar a transação.');
        $engine = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ENGINE=InnoDB' : '';
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS sync_recebimentos (
            origem_hash CHAR(64) NOT NULL, evento_id BIGINT NOT NULL,
            evento_hash CHAR(64) NOT NULL, aplicado_em DATETIME NULL,
            confirmado_em DATETIME NULL, PRIMARY KEY (origem_hash, evento_id)
        )' . $engine);
    }

    public static function hashEvento(array $evento): string
    {
        if (!isset($evento['id'], $evento['tabela'], $evento['registro_id'], $evento['acao'], $evento['dados']) ||
            !filter_var($evento['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ||
            !is_string($evento['dados'])) throw new RuntimeException('Evento de sincronização incompleto.');
        return hash('sha256', $evento['tabela'] . "\0" . (int) $evento['registro_id'] . "\0" . $evento['acao'] . "\0" . $evento['dados']);
    }

    public function aplicar(string $origem, array $evento, callable $aplicar): bool
    {
        $hash = self::hashEvento($evento);
        if (!in_array($evento['acao'], self::ACTIONS, true) || !in_array($evento['tabela'], self::TABLES, true)) {
            throw new RuntimeException('Ação ou tabela de sincronização não suportada.');
        }
        if (in_array($evento['acao'], ['SAIDA_DIRETA', 'SAIDA_DIRETA_ADM', 'ENTRADA_MANUAL', 'CORRECAO_SAIDA', 'AJUSTE_INVENTARIO'], true) && $evento['tabela'] !== 'movimentacoes') {
            throw new RuntimeException('Tabela incompatível com o evento de movimentação.');
        }
        $dados = json_decode($evento['dados'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($dados)) throw new RuntimeException('Payload de sincronização inválido.');
        if ($this->pdo->inTransaction()) throw new RuntimeException('O recebimento deve controlar sua própria transação.');
        $origem = hash('sha256', $origem);
        $this->pdo->beginTransaction();
        try {
            // A chave única faz recebimentos concorrentes aguardarem o primeiro commit.
            $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
            $sql = 'INSERT INTO sync_recebimentos (origem_hash, evento_id, evento_hash) VALUES (?, ?, ?)';
            $sql .= $mysql ? ' ON DUPLICATE KEY UPDATE evento_id = evento_id' : ' ON CONFLICT (origem_hash, evento_id) DO NOTHING';
            $this->pdo->prepare($sql)->execute([$origem, $evento['id'], $hash]);
            $stmt = $this->pdo->prepare('SELECT evento_hash, aplicado_em FROM sync_recebimentos WHERE origem_hash = ? AND evento_id = ?' . ($mysql ? ' FOR UPDATE' : ''));
            $stmt->execute([$origem, $evento['id']]);
            $recibo = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$recibo || !hash_equals($recibo['evento_hash'], $hash)) throw new RuntimeException('Evento recebido mudou de conteúdo; reconciliação necessária.');
            $novo = $recibo['aplicado_em'] === null;
            if ($novo) {
                if ($aplicar($evento, $dados) === false) throw new RuntimeException('Falha ao aplicar evento.');
                if (!$this->pdo->inTransaction()) throw new RuntimeException('A transação do recebimento foi interrompida.');
                $this->pdo->prepare('UPDATE sync_recebimentos SET aplicado_em = CURRENT_TIMESTAMP WHERE origem_hash = ? AND evento_id = ?')->execute([$origem, $evento['id']]);
            }
            $this->pdo->commit();
            return $novo;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function pendentesConfirmacao(string $origem): array
    {
        $stmt = $this->pdo->prepare('SELECT evento_id AS id, evento_hash AS hash FROM sync_recebimentos WHERE origem_hash = ? AND aplicado_em IS NOT NULL AND confirmado_em IS NULL ORDER BY evento_id LIMIT 30');
        $stmt->execute([hash('sha256', $origem)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function marcarConfirmados(string $origem, array $recibos): void
    {
        $stmt = $this->pdo->prepare('UPDATE sync_recebimentos SET confirmado_em = CURRENT_TIMESTAMP WHERE origem_hash = ? AND evento_id = ? AND evento_hash = ? AND aplicado_em IS NOT NULL');
        foreach ($recibos as $recibo) $stmt->execute([hash('sha256', $origem), $recibo['id'], $recibo['hash']]);
    }

    /** Sem cursor por data: apenas eventos ainda pendentes e não bloqueados por tentativas. */
    public function listarPendentes(): array
    {
        $actions = implode(',', array_fill(0, count(self::ACTIONS), '?'));
        $tables = implode(',', array_fill(0, count(self::TABLES), '?'));
        $stmt = $this->pdo->prepare("SELECT id, tabela, registro_id, acao, dados, criado_em FROM sincronizacao WHERE sincronizado = 0 AND tentativas < 5 AND acao IN ($actions) AND tabela IN ($tables) ORDER BY id LIMIT 30");
        $stmt->execute(array_merge(self::ACTIONS, self::TABLES));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** ACK idempotente: o conteúdo confirmado deve ser o mesmo que o cliente recebeu. */
    public function confirmarNoServidor(array $recibos): array
    {
        if (!$recibos || count($recibos) > 30) throw new RuntimeException('Informe de 1 a 30 recibos.');
        $this->pdo->beginTransaction();
        try {
            $ids = [];
            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $select = $this->pdo->prepare('SELECT id, tabela, registro_id, acao, dados FROM sincronizacao WHERE id = ?' . $lock);
            $update = $this->pdo->prepare('UPDATE sincronizacao SET sincronizado = 1, sincronizado_em = CURRENT_TIMESTAMP, erro_mensagem = NULL WHERE id = ? AND sincronizado = 0');
            usort($recibos, fn($a, $b) => (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0));
            foreach ($recibos as $recibo) {
                if (!is_array($recibo) || !filter_var($recibo['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) || !is_string($recibo['hash'] ?? null)) throw new RuntimeException('Recibo inválido.');
                $select->execute([$recibo['id']]);
                $evento = $select->fetch(PDO::FETCH_ASSOC);
                if (!$evento || !hash_equals(self::hashEvento($evento), $recibo['hash'])) throw new RuntimeException('Evento não encontrado ou confirmação divergente.');
                if (!in_array($evento['acao'], self::ACTIONS, true) || !in_array($evento['tabela'], self::TABLES, true)) throw new RuntimeException('Evento não suportado.');
                $update->execute([$evento['id']]);
                $ids[] = (int) $evento['id'];
            }
            $this->pdo->commit();
            return $ids;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }
}
