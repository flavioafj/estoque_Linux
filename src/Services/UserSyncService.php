<?php
namespace Services;

use PDO;
use RuntimeException;

/** Receptor compartilhado pelo PUSH e PULL. Nunca enfileira alterações recebidas. */
final class UserSyncService
{
    private PDO $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function receber(string $action, int $id, array $data): void
    {
        if (!$this->pdo->inTransaction()) throw new RuntimeException('Recebimento de usuário exige transação.');
        if ($id <= 0 || !in_array($action, ['INSERT', 'UPDATE', 'DELETE'], true) ||
            !is_string($data['evento_id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $data['evento_id']) ||
            ($data['id'] ?? null) !== $id || !is_string($data['usuario'] ?? null) ||
            trim($data['usuario']) === '' || mb_strlen($data['usuario']) > 50 ||
            !is_int($data['perfil_id'] ?? null) || $data['perfil_id'] <= 0) {
            throw new RuntimeException('Identificação do evento de usuário inválida.');
        }
        $fields = ['evento_id', 'id', 'usuario', 'perfil_id', 'ativo'];
        if ($action === 'INSERT') {
            $fields = array_merge($fields, ['nome_completo', 'email']);
            if ($data['perfil_id'] !== 2 || ($data['ativo'] ?? null) !== 1 ||
                !is_string($data['nome_completo'] ?? null) || trim($data['nome_completo']) === '' || mb_strlen($data['nome_completo']) > 100 ||
                !array_key_exists('email', $data) || ($data['email'] !== null &&
                (!is_string($data['email']) || mb_strlen($data['email']) > 100 || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)))) {
                throw new RuntimeException('Cadastro de operador recebido inválido.');
            }
        } elseif (($data['ativo'] ?? null) !== 0) {
            throw new RuntimeException('A sincronização de usuários permite apenas cadastro de operador e desativação.');
        }
        if (array_diff(array_keys($data), $fields)) throw new RuntimeException('Campo de usuário não permitido.');

        // Mutex estável para reenvios concorrentes, inclusive antes de o usuário existir.
        $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $profiles = $this->pdo->query('SELECT id FROM perfis WHERE id IN (1, 2) ORDER BY id' . $lock)->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array(2, array_map('intval', $profiles), true)) throw new RuntimeException('Perfil Operador não configurado no destino.');
        $stmt = $this->pdo->prepare("SELECT registro_id, acao, dados FROM sincronizacao WHERE tabela = 'usuarios' AND JSON_UNQUOTE(JSON_EXTRACT(dados, '$.evento_id')) = ? LIMIT 1" . $lock);
        $stmt->execute([$data['evento_id']]);
        $previous = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($previous) {
            if ((int) $previous['registro_id'] !== $id || $previous['acao'] !== $action ||
                json_decode($previous['dados'], true, 512, JSON_THROW_ON_ERROR) !== $data) {
                throw new RuntimeException('Conteúdo divergente do evento de usuário já recebido.');
            }
            return;
        }
        $stmt = $this->pdo->prepare('SELECT id, nome_completo, usuario, email, perfil_id, ativo FROM usuarios WHERE id = ?' . $lock);
        $stmt->execute([$id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing && ($existing['usuario'] !== $data['usuario'] || (int) $existing['perfil_id'] !== $data['perfil_id'])) {
            throw new RuntimeException('Conflito no ID do usuário; reconciliação necessária.');
        }
        if ($action === 'INSERT') {
            if ($existing) {
                if ($existing['nome_completo'] !== $data['nome_completo'] || $existing['email'] !== $data['email']) {
                    throw new RuntimeException('Cadastro divergente no ID do usuário; reconciliação necessária.');
                }
                // Cadastro repetido nunca reativa uma conta já desativada.
            } else {
                $stmt = $this->pdo->prepare('SELECT id FROM usuarios WHERE usuario = ? OR (email IS NOT NULL AND email = ?)');
                $stmt->execute([$data['usuario'], $data['email']]);
                if ($stmt->fetchColumn()) throw new RuntimeException('Usuário ou e-mail já existe com outro ID; reconciliação necessária.');
                $stmt = $this->pdo->prepare('INSERT INTO usuarios (id, nome_completo, usuario, email, senha, perfil_id, ativo) VALUES (?, ?, ?, ?, ?, 2, 1)');
                $stmt->execute([$id, $data['nome_completo'], $data['usuario'], $data['email'], password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);
            }
        } else {
            if (!$existing) throw new RuntimeException('Sincronize o cadastro do usuário antes da exclusão.');
            if ((int) $existing['perfil_id'] === 1 && (int) $existing['ativo'] === 1) {
                $admins = $this->pdo->query('SELECT id FROM usuarios WHERE perfil_id = 1 AND ativo = 1 ORDER BY id' . $lock)->fetchAll(PDO::FETCH_COLUMN);
                if (count($admins) <= 1) throw new RuntimeException('Não é permitido excluir o último administrador ativo no destino.');
            }
            $this->pdo->prepare('UPDATE usuarios SET ativo = 0, token_recuperacao = NULL, token_expiracao = NULL WHERE id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM sessoes WHERE usuario_id = ?')->execute([$id]);
        }
        // Recibo e efeito usam a transação externa. Não volta ao PUSH/PULL como pendência.
        $this->pdo->prepare('INSERT INTO sincronizacao (tabela, registro_id, acao, dados, sincronizado, tentativas, criado_em, sincronizado_em) VALUES (?, ?, ?, ?, 1, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)')
            ->execute(['usuarios', $id, $action, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    }
}
