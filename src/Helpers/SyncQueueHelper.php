<?php
declare(strict_types=1);

namespace Helpers;

use PDO;
use PDOException;
use JsonException;
use RuntimeException;

/**
 * Helper responsável por registrar alterações para sincronização local → web
 *
 * Registra INSERT, UPDATE e DELETE em uma fila (tabela sincronizacao)
 * para posterior envio ao servidor central.
 */
final class SyncQueueHelper
{
    private static ?PDO $pdo = null;

    /**
     * Inicializa o helper com a conexão PDO
     * Deve ser chamado uma vez no bootstrap ou no construtor do model base
     *
     * @param PDO $pdo Conexão com o banco de dados local
     * @return void
     */
    public static function initialize(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    /**
     * Registra uma mudança para futura sincronização
     *
     * @param string              $table       Nome da tabela afetada (ex: 'produtos', 'movimentacoes', 'movimentacao_itens')
     * @param int                 $recordId    ID do registro alterado
     * @param string              $action      'INSERT', 'UPDATE' ou 'DELETE'
     * @param array<string,mixed> $payload     Dados completos (INSERT/UPDATE) ou delta (antes/depois)
     * @param string|null         $errorMsg    (opcional) mensagem de erro inicial, se já souber
     *
     * @return int ID do registro inserido na tabela sincronizacao
     *
     * @throws RuntimeException Quando não foi inicializado ou falha na inserção
     */
    public static function queueChange(
        string $table,
        int $recordId,
        string $action,
        array $payload = [],
        ?string $errorMsg = null
    ): int {
        if (self::$pdo === null) {
            throw new RuntimeException('SyncQueueHelper não foi inicializado. Chame SyncQueueHelper::initialize($pdo) primeiro.');
        }

        $validActions = ['INSERT', 'UPDATE', 'DELETE', 'SAIDA_DIRETA'];
        if (!in_array($action, $validActions, true)) {
            throw new RuntimeException("Ação inválida: '$action'. Valores permitidos: " . implode(', ', $validActions));
        }

        try {
            $jsonData = $payload ? json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ) : null;
        } catch (JsonException $e) {
            throw new RuntimeException('Falha ao codificar payload para JSON: ' . $e->getMessage(), 0, $e);
        }

        $sql = "
            INSERT INTO sincronizacao 
            (tabela, registro_id, acao, dados, sincronizado, tentativas, erro_mensagem, criado_em, sincronizado_em)
            VALUES 
            (:tabela, :registro_id, :acao, :dados, FALSE, 0, :erro_mensagem, NOW(), NULL)
        ";

        $stmt = self::$pdo->prepare($sql);

        $stmt->execute([
            ':tabela'         => $table,
            ':registro_id'    => $recordId,
            ':acao'           => $action,
            ':dados'          => $jsonData,
            ':erro_mensagem'  => $errorMsg
        ]);

        $syncId = (int) self::$pdo->lastInsertId();

        if ($syncId === 0) {
            throw new RuntimeException('Falha ao obter ID da sincronização recém-criada');
        }

        return $syncId;
    }

    /**
     * Método de conveniência: registra INSERT
     */
    public static function queueInsert(string $table, int $recordId, array $data): int
    {
        return self::queueChange($table, $recordId, 'INSERT', $data);
    }

    /**
     * Método de conveniência: registra UPDATE (com delta antes/depois recomendado)
     */
    public static function queueUpdate(string $table, int $recordId, array $delta): int
    {
        return self::queueChange($table, $recordId, 'UPDATE', $delta);
    }

    /**
     * Método de conveniência: registra DELETE
     */
    public static function queueDelete(string $table, int $recordId, array $oldData = []): int
    {
        return self::queueChange($table, $recordId, 'DELETE', ['old' => $oldData]);
    }

    public static function isInitialized(): bool
    {
        return self::$pdo !== null;
    }

    /**
     * (Opcional) Marca uma sincronização como falhada e incrementa tentativas
     */
    public static function markAsFailed(int $syncId, string $errorMessage): bool
    {
        if (self::$pdo === null) {
            return false;
        }

        $sql = "
            UPDATE sincronizacao 
            SET 
                tentativas = tentativas + 1,
                erro_mensagem = :erro,
                sincronizado = FALSE
            WHERE id = :id
        ";

        $stmt = self::$pdo->prepare($sql);
        return $stmt->execute([
            ':id'   => $syncId,
            ':erro' => $errorMessage
        ]);
    }
}