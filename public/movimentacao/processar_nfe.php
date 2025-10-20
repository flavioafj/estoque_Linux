<?php
use Helpers\Session;
use Helpers\NFeProcessor;
//use Models\Product;
use Models\Fornecedor; // ADICIONADO

// Carrega as configurações e dependências
require_once '../../config/config.php';
require_once SRC_PATH . '/Helpers/NFeProcessor.php';
require_once SRC_PATH . '/Models/Product.php';
//require_once SRC_PATH . '/Models/Fornecedor.php'; // ADICIONADO

// Inicia a sessão e verifica se o usuário é administrador
Session::start();
if (!Session::isAdmin()) {
    Session::setFlash('error', 'Acesso negado. Você precisa ser administrador.');
    header('Location: ../admin/entradas.php');
    exit();
}

// Verifica se a requisição é POST e se arquivos foram enviados
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['arquivos_nfe']['name'][0])) {
    Session::setFlash('error', 'Nenhum arquivo XML foi enviado.');
    header('Location: ../admin/entradas.php');
    exit();
}

// Pega o primeiro arquivo enviado para processamento
$uploadedFile = [
    'name' => $_FILES['arquivos_nfe']['name'][0],
    'type' => $_FILES['arquivos_nfe']['type'][0],
    'tmp_name' => $_FILES['arquivos_nfe']['tmp_name'][0],
    'error' => $_FILES['arquivos_nfe']['error'][0],
    'size' => $_FILES['arquivos_nfe']['size'][0],
];

// Validação básica do arquivo
if ($uploadedFile['error'] !== UPLOAD_ERR_OK || pathinfo($uploadedFile['name'], PATHINFO_EXTENSION) !== 'xml') {
    Session::setFlash('error', 'Houve um erro no upload ou o arquivo não é um XML válido.');
    header('Location: ../admin/entradas.php');
    exit();
}

// === ETAPA DE PROCESSAMENTO XML ===

$nfeProcessor = new NFeProcessor();
$nfeData = $nfeProcessor->extractDataForVerification($uploadedFile['tmp_name']);

if ($nfeData === null) {
    Session::setFlash('error', 'Não foi possível processar o arquivo XML. Verifique se o arquivo é uma NF-e válida.');
    header('Location: ../admin/entradas.php');
    exit();
}

// === ETAPA DE SUGESTÃO DE FORNECEDOR (NOVO) ===

$fornecedorModel = new Fornecedor();
$fornecedoresDoBanco = $fornecedorModel->getAll();
$idFornecedorSugerido = null;
$cnpjXmlLimpo = preg_replace('/[^0-9]/', '', $nfeData['fornecedor']['cnpj']);

foreach ($fornecedoresDoBanco as $fornecedorDb) {
    $cnpjDbLimpo = preg_replace('/[^0-9]/', '', $fornecedorDb['cnpj']);
    if ($cnpjDbLimpo === $cnpjXmlLimpo) {
        $idFornecedorSugerido = $fornecedorDb['id'];
        break; // Para o loop assim que encontrar a correspondência
    }
}

// === ETAPA DE SUGESTÃO DE PRODUTOS ===

$productModel = new Product();
$produtosDoBanco = $productModel->getAllby("nome");

$produtosSugeridos = [];
foreach ($nfeData['produtos_xml'] as $produtoXml) {
    $melhorSimilaridade = 0;
    $idSugerido = null;

    foreach ($produtosDoBanco as $produtoDb) {
        similar_text(strtoupper($produtoXml['nome_xml']), strtoupper($produtoDb['nome']), $percent);
        
        if ($percent > $melhorSimilaridade) {
            $melhorSimilaridade = $percent;
            $idSugerido = $produtoDb['id'];
        }
    }

    if ($melhorSimilaridade > 70) {
        $produtoXml['sugestao_produto_id'] = $idSugerido;
    } else {
        $produtoXml['sugestao_produto_id'] = null;
    }
    
    $produtosSugeridos[] = $produtoXml;
}

// === ETAPA DE RENDERIZAÇÃO (VIEW) ===


$pageTitle = "Verificar e Associar Produtos da NF-e";
include __DIR__ . '/../../templates/header.php';
?>

<main class="container mt-4">
    <h2>Verificar e Associar Produtos da NF-e</h2>
    <p>Confirme os dados da nota fiscal, associe o fornecedor e os produtos aos registros do sistema.</p>
    
    <form action="registrar_entrada_nfe.php" method="POST">
        <input type="hidden" name="fornecedor_cnpj" value="<?= htmlspecialchars($nfeData['fornecedor']['cnpj']) ?>">
        <input type="hidden" name="fornecedor_razao_social" value="<?= htmlspecialchars($nfeData['fornecedor']['razao_social']) ?>">
        <input type="hidden" name="nota_chave_acesso" value="<?= htmlspecialchars($nfeData['nota_fiscal']['chave_acesso']) ?>">
        <input type="hidden" name="nota_numero" value="<?= htmlspecialchars($nfeData['nota_fiscal']['numero']) ?>">
        <input type="hidden" name="nota_data_emissao" value="<?= htmlspecialchars($nfeData['nota_fiscal']['data_emissao']) ?>">
        <input type="hidden" name="nota_valor_total" value="<?= htmlspecialchars($nfeData['nota_fiscal']['valor_total']) ?>">
        
        <div class="card mb-4">
            <div class="card-header">
                Dados da Nota Fiscal
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Fornecedor na Nota Fiscal (XML)</label>
                        <p class="form-control-plaintext"><?= htmlspecialchars($nfeData['fornecedor']['razao_social']) ?> (CNPJ: <?= htmlspecialchars($nfeData['fornecedor']['cnpj']) ?>)</p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="id_fornecedor_sistema" class="form-label fw-bold">Associar ao Fornecedor do Sistema</label>
                        <select name="id_fornecedor_sistema" id="id_fornecedor_sistema" class="form-select" required>
                            <option value="">-- Selecione um fornecedor --</option>
                            <?php foreach ($fornecedoresDoBanco as $fornecedor): ?>
                            <option value="<?= $fornecedor['id'] ?>" <?= ($fornecedor['id'] == $idFornecedorSugerido) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($fornecedor['razao_social']) ?>
                            </option>
                            <?php endforeach; ?>
                            <option value="cadastrar_novo">-- Cadastrar como novo fornecedor --</option>
                        </select>
                        <?php if ($idFornecedorSugerido): ?>
                            <div class="form-text text-success">✔ Fornecedor sugerido com base no CNPJ.</div>
                        <?php else: ?>
                             <div class="form-text text-warning">⚠ Nenhum fornecedor encontrado com este CNPJ. Selecione um existente ou cadastre um novo.</div>
                             
                            <button type="button" id="btn-cadastrar-fornecedor" class="btn btn-sm btn-primary">
                                ->Cadastrar Fornecedor
                            </button>

                            <div id="status-message" style="margin-top: 15px;"></div>
                                
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <strong>Número da Nota:</strong> <?= htmlspecialchars($nfeData['nota_fiscal']['numero']) ?>
                    </div>
                    <div class="col-md-6">
                        <strong>Data de Emissão:</strong> <?= htmlspecialchars(date('d/m/Y H:i', strtotime($nfeData['nota_fiscal']['data_emissao']))) ?><br>
                        <strong>Valor Total:</strong> R$ <?= htmlspecialchars(number_format($nfeData['nota_fiscal']['valor_total'], 2, ',', '.')) ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                Itens da Nota Fiscal
            </div>
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Produto na Nota Fiscal (XML)</th>
                            <th class="text-center">Qtd.</th>
                            <th class="w-50">Associar ao Produto do Sistema</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($produtosSugeridos as $index => $produto): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($produto['nome_xml']) ?></strong><br>
                                <small class="text-muted">Cód: <?= htmlspecialchars($produto['codigo_xml']) ?> | V. Un: R$ <?= number_format($produto['valor_unitario'], 2, ',', '.') ?></small>
                            </td>
                            <td class="text-center"><?= htmlspecialchars($produto['quantidade']) ?> <?= htmlspecialchars($produto['unidade']) ?></td>
                            <td>
                                <input type="hidden" name="produtos[<?= $index ?>][nome_xml]" value="<?= htmlspecialchars($produto['nome_xml']) ?>">
                                <input type="hidden" name="produtos[<?= $index ?>][quantidade]" value="<?= htmlspecialchars($produto['quantidade']) ?>">
                                <input type="hidden" name="produtos[<?= $index ?>][valor_unitario]" value="<?= htmlspecialchars($produto['valor_unitario']) ?>">

                                <select name="produtos[<?= $index ?>][id_produto_sistema]" class="form-select">
                                    <option value="">-- Ignorar este item --</option>
                                    <?php foreach ($produtosDoBanco as $produtoDb): ?>
                                    <option value="<?= $produtoDb['id'] ?>" <?= ($produtoDb['id'] == $produto['sugestao_produto_id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($produtoDb['nome']) ?> (Estoque atual: <?= $produtoDb['estoque_atual'] ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                 <?php if ($produto['sugestao_produto_id'] === null): ?>
                                        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#productModal" data-product-name="<?= htmlspecialchars($produto['nome_xml']) ?>">Cadastrar Produto</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Modal para cadastro de produto -->
        <div class="modal fade" id="productModal" tabindex="-1" aria-labelledby="productModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="productModalLabel">Cadastrar Novo Produto</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <iframe id="productFormIframe" src="/admin/products.php" style="width: 100%; height: 500px; border: none;"></iframe>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-4">
            <button type="submit" class="btn btn-primary">Confirmar e Registrar Entrada</button>
            <a href="../admin/entradas.php" class="btn btn-secondary">Cancelar</a>
        </div>
    </form>
</main>


<!-- JavaScript para manipulação do modal -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    //modal
    const productModal = document.getElementById('productModal');
    productModal.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget; // Botão que disparou o modal
        const productName = button.getAttribute('data-product-name');
        const iframe = document.getElementById('productFormIframe');
        
        // Passa o nome do produto como parâmetro para pré-preencher o formulário
        iframe.src = `/admin/products.php?nome=${encodeURIComponent(productName)}`;
    });

    productModal.addEventListener('hidden.bs.modal', function () {
        // Recarrega a página para atualizar a lista de produtos após o cadastro
        window.location.reload();
    });


    //cadastra fornecedor
    const btn = document.getElementById('btn-cadastrar-fornecedor');
    const statusDiv = document.getElementById('status-message');

    if (btn) {
        btn.addEventListener('click', function() {
            // 1. Obter dados dos inputs (você deve ter inputs para CNPJ e Razão Social)
            // Exemplo (adapte isso para seus inputs reais):
            const cnpj = document.getElementsByName("fornecedor_cnpj")[0].value;
            const razaoSocial = document.getElementsByName("fornecedor_razao_social")[0].value;

            // 2. Montar o objeto de dados a ser enviado
            const formData = new FormData();
            formData.append('cnpj', cnpj);
            formData.append('razao_social', razaoSocial);
            
            // Opcional: Desabilitar o botão enquanto processa
            btn.disabled = true;
            statusDiv.innerHTML = '<span style="color: gray;">Processando...</span>';

            // 3. Fazer a requisição AJAX usando Fetch
            fetch('processa_cadastro.php', {
                method: 'POST',
                body: formData // Envia os dados
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // SUCESSO: Exibe a mensagem "Fornecedor cadastrado"
                    statusDiv.innerHTML = `<span style="color: green; font-weight: bold;">${data.message}</span>`;
                } else {
                    // ERRO
                    statusDiv.innerHTML = `<span style="color: red;">Erro: ${data.message}</span>`;
                }
            })
            .catch(error => {
                // Erro de rede ou JSON
                statusDiv.innerHTML = `<span style="color: red;">Erro de comunicação: ${error.message}</span>`;
            })
            .finally(() => {
                // Reabilita o botão
                btn.disabled = false;
            });
        });
    }
});
</script>


<?php include __DIR__ . '/../../templates/footer.php'; ?>