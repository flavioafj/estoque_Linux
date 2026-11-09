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
$editId = null;
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edit'])) {
    $id = filter_var($_GET['edit'], FILTER_VALIDATE_INT);
    $supplier = $id && $id > 0 ? $model->getById($id) : null;
    if (!$supplier || (int) $supplier['ativo'] !== 1) {
        $error = 'Fornecedor ativo não encontrado.';
    } else {
        $editId = (int) $supplier['id'];
        foreach ($values as $field => $value) $values[$field] = $supplier[$field] ?? '';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $field => $value) {
        $values[$field] = is_string($_POST[$field] ?? null) ? trim($_POST[$field]) : ($field === 'tipo' ? 'PJ' : '');
    }
    if (($_POST['action'] ?? '') === 'edit') {
        $editId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$editId || $editId < 1) $editId = null;
    }
    try {
        $token = $_POST['csrf_token'] ?? '';
        if (!is_string($token) || !Session::validateCSRFToken($token)) throw new InvalidArgumentException('Formulário expirado. Atualize a página e tente novamente.');
        if (($_POST['action'] ?? '') === 'create') {
            $model->addSupplier($values + ['produtos' => $_POST['produtos'] ?? []], (int) Session::getUserId());
            Session::setFlash('success', 'Fornecedor cadastrado e incluído na fila de sincronização.');
        } elseif (($_POST['action'] ?? '') === 'edit') {
            if ($editId === null) throw new InvalidArgumentException('Fornecedor inválido.');
            $model->editSupplier($editId, $values, (int) Session::getUserId());
            Session::setFlash('success', 'Fornecedor atualizado. Alterações incluídas na fila de sincronização.');
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
$productChoices = $model->rawQuery('SELECT id, nome FROM produtos WHERE ativo = 1 ORDER BY nome');
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
            <h2 class="h4" id="novo-fornecedor"><?= $editId !== null ? 'Editar fornecedor' : 'Incluir fornecedor' ?></h2>
            <p>Campos com * são obrigatórios. CNPJ e CPF são opcionais. <?= $editId !== null ? 'Altere os dados e salve.' : 'O novo fornecedor será cadastrado como ativo.' ?></p>
            <form method="post" action="/admin/suppliers.php" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= $escape($csrf) ?>">
                <input type="hidden" name="action" value="<?= $editId !== null ? 'edit' : 'create' ?>">
                <?php if ($editId !== null): ?><input type="hidden" name="id" value="<?= $editId ?>"><?php endif; ?>
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
                <?php if ($editId === null): ?>
                <div class="col-12"><label for="supplier-products" class="form-label">Produtos fornecidos (opcional)</label>
                    <select id="supplier-products" name="produtos[]" multiple class="form-select" aria-describedby="supplier-products-help">
                    <?php foreach ($productChoices as $choice): ?><option value="<?= (int) $choice['id'] ?>" <?= in_array((string) $choice['id'], array_map('strval', is_array($_POST['produtos'] ?? null) ? $_POST['produtos'] : []), true) ? 'selected' : '' ?>><?= $escape($choice['nome']) ?></option><?php endforeach; ?>
                    </select><small id="supplier-products-help">Seleção múltipla opcional. Também é possível associar produtos depois pelo botão Produtos.</small>
                </div>
                <?php endif; ?>
                <div class="col-12"><button type="submit" class="btn btn-primary"><?= $editId !== null ? 'Salvar alterações' : 'Incluir fornecedor' ?></button>
                    <?php if ($editId !== null): ?><a href="/admin/suppliers.php" class="btn btn-outline-secondary">Cancelar</a><?php endif; ?>
                </div>
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
                    <td><button type="button" class="btn btn-outline-secondary btn-sm mb-2" data-bs-toggle="modal" data-bs-target="#supplierProductsModal" data-supplier-id="<?= (int) $supplier['id'] ?>" data-supplier-name="<?= $escape($supplier['razao_social']) ?>">Produtos</button>
                    <a class="btn btn-outline-primary btn-sm mb-2" href="/admin/suppliers.php?edit=<?= (int) $supplier['id'] ?>#novo-fornecedor" aria-label="Editar fornecedor <?= $escape($supplier['razao_social']) ?>">Editar</a>
                    <form method="post" action="/admin/suppliers.php" onsubmit="return confirm('Excluir este fornecedor? Ele será desativado e o histórico preservado.');">
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
<div class="modal fade" id="supplierProductsModal" tabindex="-1" aria-labelledby="supplierProductsTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header"><h2 class="modal-title fs-5" id="supplierProductsTitle">Produtos fornecidos</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
        <div class="modal-body" data-supplier-products data-csrf="<?= $escape($csrf) ?>"></div>
    </div></div>
</div>
<script src="/assets/js/product_suppliers.js" defer></script>
<?php require __DIR__ . '/../../templates/footer.php'; ?>
