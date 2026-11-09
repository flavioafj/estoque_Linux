<?php
namespace Models;

/** Gestão de usuários com auditoria sem credenciais. */
class AdminUser extends User {
    public function addOperator(array $data, int $actorId): void {
        $name = trim($data['nome_completo'] ?? '');
        $username = trim($data['usuario'] ?? '');
        $email = trim($data['email'] ?? '');
        if ($name === '' || mb_strlen($name) > 100 || $username === '' || mb_strlen($username) > 50) {
            throw new \InvalidArgumentException('Informe nome completo (até 100 caracteres) e usuário (até 50 caracteres).');
        }
        if ($email !== '' && (mb_strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            throw new \InvalidArgumentException('Informe um e-mail válido ou deixe o campo vazio.');
        }
        $pdo = $this->db->getConnection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE usuario = ?');
            $stmt->execute([$username]);
            if ($stmt->fetchColumn()) {
                throw new \InvalidArgumentException('Este usuário já está cadastrado, inclusive entre contas inativas.');
            }
            if ($email !== '') {
                $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE email = ?');
                $stmt->execute([$email]);
                if ($stmt->fetchColumn()) {
                    throw new \InvalidArgumentException('Este e-mail já está cadastrado.');
                }
            }
            $stmt = $pdo->prepare('INSERT INTO usuarios (nome_completo, usuario, email, senha, perfil_id, ativo) VALUES (?, ?, ?, ?, 2, 1)');
            $stmt->execute([$name, $username, $email === '' ? null : $email, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);
            $id = (int) $pdo->lastInsertId();
            $this->auditChange($pdo, 'INSERT', $id, $actorId, null, [
                'nome_completo' => $name, 'usuario' => $username, 'email' => $email === '' ? null : $email,
                'perfil_id' => 2, 'ativo' => 1
            ]);
            \Helpers\SyncQueueHelper::queueInsert('usuarios', $id, [
                'evento_id' => bin2hex(random_bytes(16)), 'id' => $id,
                'nome_completo' => $name, 'usuario' => $username, 'email' => $email === '' ? null : $email,
                'perfil_id' => 2, 'ativo' => 1
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            if ($e instanceof \PDOException && $e->getCode() === '23000') {
                throw new \InvalidArgumentException('Usuário/e-mail duplicado ou perfil Operador indisponível.');
            }
            throw $e;
        }
    }

    public function deactivateUser(int $id, int $actorId): void {
        if ($id <= 0 || $id === $actorId) {
            throw new \InvalidArgumentException('Não é permitido excluir a própria conta.');
        }
        $pdo = $this->db->getConnection();
        $pdo->beginTransaction();
        try {
            // Serializa exclusões para proteger o último administrador ativo.
            $admins = $pdo->query('SELECT id FROM usuarios WHERE perfil_id = 1 AND ativo = 1 ORDER BY id' . $this->lock())->fetchAll(\PDO::FETCH_COLUMN);
            $stmt = $pdo->prepare('SELECT id, nome_completo, usuario, email, perfil_id, ativo FROM usuarios WHERE id = ? AND ativo = 1' . $this->lock());
            $stmt->execute([$id]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$user) {
                throw new \InvalidArgumentException('Usuário ativo não encontrado.');
            }
            if ((int) $user['perfil_id'] === 1 && count($admins) <= 1) {
                throw new \InvalidArgumentException('Não é permitido excluir o último administrador ativo.');
            }
            $pdo->prepare('UPDATE usuarios SET ativo = 0, token_recuperacao = NULL, token_expiracao = NULL WHERE id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM sessoes WHERE usuario_id = ?')->execute([$id]);
            $this->auditChange($pdo, 'UPDATE', $id, $actorId, $user, ['ativo' => 0]);
            \Helpers\SyncQueueHelper::queueUpdate('usuarios', $id, [
                'evento_id' => bin2hex(random_bytes(16)), 'id' => $id, 'usuario' => $user['usuario'],
                'perfil_id' => (int) $user['perfil_id'], 'ativo' => 0
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function lock(): string {
        return $this->db->getConnection()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }

    private function auditChange(\PDO $pdo, string $action, int $id, int $actorId, ?array $before, array $after): void {
        $stmt = $pdo->prepare('INSERT INTO auditoria (tabela, registro_id, acao, dados_anteriores, dados_novos, usuario_id, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute(['usuarios', $id, $action, $before === null ? null : json_encode($before), json_encode($after), $actorId,
            $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null]);
    }
}
