<?php
require_once __DIR__ . '/../../config/config.php';

use Helpers\Session;
use Middleware\Auth;
use Models\AdminUser;

Auth::checkAdmin('/dashboard.php');
$model = new AdminUser();
$values = ['nome_completo' => '', 'usuario' => '', 'email' => ''];
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $key => $value) {
        $values[$key] = is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
    }
    try {
        $token = $_POST['csrf_token'] ?? '';
        if (!is_string($token) || !Session::validateCSRFToken($token)) {
            throw new InvalidArgumentException('Formulário expirado. Atualize a página e tente novamente.');
        }
        if (($_POST['action'] ?? '') === 'create') {
            $model->addOperator($values, (int) Session::getUserId());
            Session::setFlash('success', 'Operador cadastrado. Já pode entrar usando apenas o usuário.');
        } elseif (($_POST['action'] ?? '') === 'delete') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id || $id < 1) {
                throw new InvalidArgumentException('Usuário inválido.');
            }
            $model->deactivateUser($id, (int) Session::getUserId());
            Session::setFlash('success', 'Usuário excluído da lista de ativos. Histórico preservado.');
        } else {
            throw new InvalidArgumentException('Operação inválida.');
        }
        header('Location: /admin/users.php');
        exit;
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Gestão de usuários: falha na operação (' . get_class($e) . ').');
        $error = 'Não foi possível concluir a operação. Tente novamente.';
    }
}
$users = $model->getAllWithProfiles(true);
$csrf = Session::getCSRFToken();
$escape = static function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };
$title = 'Gerenciar Usuários';
require __DIR__ . '/../../templates/header.php';
require __DIR__ . '/../../templates/navigation.php';
?>
<main class="container py-4">
    <h1>Gerenciar Usuários</h1>
    <?php if ($error !== null): ?>
        <div class="alert alert-danger" role="alert"><?= $escape($error) ?></div>
    <?php endif; ?>
    <section class="card my-4" aria-labelledby="novo-usuario">
        <div class="card-body">
            <h2 class="h4" id="novo-usuario">Incluir operador</h2>
            <p>O novo operador acessa o sistema digitando apenas o usuário.</p>
            <form method="post" action="/admin/users.php" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= $escape($csrf) ?>">
                <input type="hidden" name="action" value="create">
                <div class="col-md-4">
                    <label for="nome_completo" class="form-label">Nome completo</label>
                    <input id="nome_completo" name="nome_completo" class="form-control" maxlength="100" required value="<?= $escape($values['nome_completo']) ?>">
                </div>
                <div class="col-md-4">
                    <label for="usuario" class="form-label">Usuário</label>
                    <input id="usuario" name="usuario" class="form-control" maxlength="50" required value="<?= $escape($values['usuario']) ?>">
                </div>
                <div class="col-md-4">
                    <label for="email" class="form-label">E-mail (opcional)</label>
                    <input type="email" id="email" name="email" class="form-control" maxlength="100" value="<?= $escape($values['email']) ?>">
                </div>
                <div class="col-12"><button class="btn btn-primary" type="submit">Incluir usuário</button></div>
            </form>
        </div>
    </section>
    <h2 class="h4">Usuários ativos (<?= count($users) ?>)</h2>
    <p>A exclusão desativa o acesso e preserva o histórico do usuário.</p>
    <div class="table-responsive">
        <table class="table table-striped align-middle">
            <thead><tr><th>Nome completo</th><th>Usuário</th><th>E-mail</th><th>Perfil</th><th>Último acesso</th><th>Ações</th></tr></thead>
            <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td><?= $escape($user['nome_completo']) ?></td>
                    <td><?= $escape($user['usuario']) ?></td>
                    <td><?= $escape($user['email'] ?: '—') ?></td>
                    <td><?= $escape($user['perfil_nome']) ?></td>
                    <td><?= $user['ultimo_acesso'] ? $escape(date('d/m/Y H:i', strtotime($user['ultimo_acesso']))) : 'Nunca acessou' ?></td>
                    <td>
                        <?php if ((int) $user['id'] === (int) Session::getUserId()): ?>
                            <span class="text-muted">Sua conta</span>
                        <?php else: ?>
                            <form method="post" action="/admin/users.php" onsubmit="return confirm('Excluir este usuário? O acesso será desativado e o histórico preservado.');">
                                <input type="hidden" name="csrf_token" value="<?= $escape($csrf) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm" aria-label="Excluir usuário <?= $escape($user['usuario']) ?>">Excluir</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$users): ?><tr><td colspan="6">Nenhum usuário ativo encontrado.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
<?php require __DIR__ . '/../../templates/footer.php'; ?>
