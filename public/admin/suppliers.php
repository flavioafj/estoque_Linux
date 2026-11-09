<?php
require_once __DIR__ . '/../../config/config.php';

use Helpers\Session;
use Middleware\Auth;
use Models\AdminFornecedor;
use Services\SupplierData;

Auth::checkAdmin('/dashboard.php');
$model = new AdminFornecedor();
$labels = ['razao_social' => 'Razão social / Nome', 'nome_fantasia' => 'Nome fantasia', 'cnpj' => 'CNPJ', 'cpf' => 'CPF',
    'email' => 'E-mail', 'telefone' => 'Telefone', 'celular' => 'Celular', 'endereco' => 'Endereço', 'cidade' => 'Cidade',
    'estado' => 'Estado (UF)', 'cep' => 'CEP', 'observacoes' => 'Observações'];
$values = array_fill_keys(array_keys($labels), '');
$values['tipo'] = 'PJ';
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $field => $value) {
        $values[$field] = is_string($_POST[$field] ?? null) ? trim($_POST[$field]) : ($field === 'tipo' ? 'PJ' : '');
    }
    try {
        $token = $_POST['csrf_token'] ?? '';
        if (!is_string($token) || !Session::validateCSRFToken($token)) throw new InvalidArgumentException('Formulário expirado. Atualize a página e tente novamente.');
        if (($_POST['action'] ?? '') === 'create') {
            $model->addSupplier($values, (int) Session::getUserId());
            Session::setFlash('success', 'Fornecedor cadastrado e incluído na fila de sincronização.');
        } elseif (($_POST['action'] ?? '') === 'delete') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id || $id < 1) throw new InvalidArgumentException('Fornecedor inválido.');
            $model->deactivateSupplier($id, (int) Session::getUserId());
            Session::setFlash('success', 'Fornecedor excluído da lista de ativos. Histórico preservado e sincronização agendada.');
        } else {
            throw new InvalidArgumentException('Operação inválida.');
        }
        header('Location: /admin/suppliers.php');
        exit;
    } catch (InvalidArgumentException $e) { $error = $e->getMessage(); }
    catch (Throwable $e) {
        error_log('Gestão de fornecedores: falha na operação (' . get_class($e) . ').');
        $error = 'Não foi possível concluir a operação. Tente novamente.';
    }
}
$suppliers = $model->all('ativo = 1', [], 'razao_social, id');
$csrf = Session::getCSRFToken();
$escape = static function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };
$title = 'Gerenciar Fornecedores';
require __DIR__ . '/../../templates/header.php';
require __DIR__ . '/../../templates/navigation.php';
?>
<main class="container py-4">
    <h1>Gerenciar Fornecedores</h1>
    <?php if ($error !== null): ?><div class="alert alert-danger" role="alert"><?= $escape($error) ?></div><?php endif; ?>
    <section class="card my-4" aria-labelledby="novo-fornecedor">
        <div class="card-body">
            <h2 class="h4" id="novo-fornecedor">Incluir fornecedor</h2>
            <p>Campos com * são obrigatórios. CNPJ e CPF são opcionais. O novo fornecedor será cadastrado como ativo.</p>
            <form method="post" action="/admin/suppliers.php" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= $escape($csrf) ?>">
                <input type="hidden" name="action" value="create">
                <div class="col-md-4">
                    <label for="tipo" class="form-label">Tipo *</label>
                    <select id="tipo" name="tipo" class="form-select" required>
                        <option value="PJ" <?= $values['tipo'] === 'PJ' ? 'selected' : '' ?>>Pessoa jurídica (PJ)</option>
                        <option value="PF" <?= $values['tipo'] === 'PF' ? 'selected' : '' ?>>Pessoa física (PF)</option>
                    </select>
                </div>
                <?php foreach ($labels as $field => $label): ?>
                    <div class="<?= $field === 'observacoes' ? 'col-12' : 'col-md-4' ?>">
                        <label for="<?= $field ?>" class="form-label"><?= $escape($label) ?><?= $field === 'razao_social' ? ' *' : ' (opcional)' ?></label>
                        <?php if ($field === 'observacoes'): ?>
                            <textarea id="observacoes" name="observacoes" class="form-control" rows="3" maxlength="65535"><?= $escape($values[$field]) ?></textarea>
                        <?php else: ?>
                            <input type="<?= $field === 'email' ? 'email' : (in_array($field, ['telefone', 'celular'], true) ? 'tel' : 'text') ?>" id="<?= $field ?>" name="<?= $field ?>" class="form-control"
                                maxlength="<?= SupplierData::LIMITS[$field] ?>" <?= $field === 'razao_social' ? 'required' : '' ?> value="<?= $escape($values[$field]) ?>">
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <div class="col-12"><button type="submit" class="btn btn-primary">Incluir fornecedor</button></div>
            </form>
        </div>
    </section>
    <h2 class="h4">Fornecedores ativos (<?= count($suppliers) ?>)</h2>
    <p>A exclusão desativa o fornecedor e preserva as notas fiscais e movimentações vinculadas.</p>
    <div class="table-responsive">
        <table class="table table-striped align-middle">
            <thead><tr><th>Razão social / Nome</th><th>Tipo</th><th>CNPJ / CPF</th><th>Contato</th><th>Dados completos</th><th>Ações</th></tr></thead>
            <tbody>
            <?php foreach ($suppliers as $supplier): ?>
                <tr>
                    <td><?= $escape($supplier['razao_social']) ?></td>
                    <td><?= $escape($supplier['tipo']) ?></td>
                    <td><?= $escape(implode(' / ', array_filter([$supplier['cnpj'], $supplier['cpf']])) ?: '—') ?></td>
                    <td><?= $escape(implode(' / ', array_filter([$supplier['email'], $supplier['telefone'], $supplier['celular']])) ?: '—') ?></td>
                    <td><details><summary>Ver dados</summary><dl class="mt-2">
                        <?php foreach ($labels as $field => $label): ?>
                            <dt><?= $escape($label) ?></dt><dd class="text-break" style="white-space: pre-wrap"><?= $escape($supplier[$field] ?? '—') ?></dd>
                        <?php endforeach; ?>
                        <dt>ID</dt><dd><?= (int) $supplier['id'] ?></dd>
                        <dt>Status</dt><dd>Ativo</dd>
                        <dt>Criado por (ID)</dt><dd><?= $escape($supplier['criado_por'] ?? '—') ?></dd>
                        <dt>Criado em</dt><dd><?= $escape($supplier['criado_em']) ?></dd>
                        <dt>Atualizado em</dt><dd><?= $escape($supplier['atualizado_em']) ?></dd>
                    </dl></details></td>
                    <td><form method="post" action="/admin/suppliers.php" onsubmit="return confirm('Excluir este fornecedor? Ele será desativado e o histórico preservado.');">
                        <input type="hidden" name="csrf_token" value="<?= $escape($csrf) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $supplier['id'] ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm" aria-label="Excluir fornecedor <?= $escape($supplier['razao_social']) ?>">Excluir</button>
                    </form></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$suppliers): ?><tr><td colspan="6">Nenhum fornecedor ativo encontrado.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
<?php require __DIR__ . '/../../templates/footer.php'; ?>
