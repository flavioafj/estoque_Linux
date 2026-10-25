<?php
require_once '../../config/config.php';
require_once SRC_PATH . '/Helpers/Session.php';
require_once SRC_PATH . '/Models/Database.php';
//require_once SRC_PATH . '/Models/Fornecedor.php';
require_once SRC_PATH . '/Models/Movimentacao.php';
require_once SRC_PATH . '/Models/MovimentacaoItem.php';
require_once SRC_PATH . '/Models/TipoMovimentacao.php';
require_once SRC_PATH . '/Helpers/SyncQueueHelper.php';


use Helpers\Session;
use Models\Database;
use Models\Fornecedor;
use Models\Movimentacao;
use Models\MovimentacaoItem;
use Models\TipoMovimentacao;
use Models\ItensNota;
use Helpers\SyncQueueHelper;

// Inicia a sessão
Session::start();

// Verifica se é POST e se o usuário é administrador
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Session::isAdmin()) {
    Session::setFlash('error', 'Acesso negado ou método inválido.');
    header('Location: ../admin/entradas.php');
    exit();
}

// Valida se os dados necessários foram enviados
if (empty($_POST['produtos']) || !is_array($_POST['produtos']) || 
    empty($_POST['fornecedor_cnpj']) || empty($_POST['nota_numero'])) {
    Session::setFlash('error', 'Dados incompletos. Por favor, preencha o formulário corretamente.');
    header('Location: ../movimentacao/processar_nfe.php');
    exit();
}

// Extrai e valida dados do POST
$cnpj = preg_replace('/[^0-9]/', '', $_POST['fornecedor_cnpj']);
$razaoSocial = $_POST['fornecedor_razao_social'] ?? '';
$notaNumero = $_POST['nota_numero'] ?? '';
$notaChaveAcesso = $_POST['nota_chave_acesso'] ?? '';
$notaDataEmissao = $_POST['nota_data_emissao'] ?? '';
$notaValorTotal = floatval($_POST['nota_valor_total'] ?? 0);
$produtos = $_POST['produtos'] ?? [];
$idFornecedorSistema = $_POST['id_fornecedor_sistema'] ?? '';

// Validações adicionais
if (strlen($cnpj) !== 14) {
    Session::setFlash('error', 'CNPJ inválido.');
    header('Location: ../movimentacao/processar_nfe.php');
    exit();
}
if (!strtotime($notaDataEmissao)) {
    Session::setFlash('error', 'Data de emissão inválida.');
    header('Location: ../movimentacao/processar_nfe.php');
    exit();
}
if ($notaValorTotal <= 0) {
    Session::setFlash('error', 'Valor total da nota inválido.');
    header('Location: ../movimentacao/processar_nfe.php');
    exit();
}

// Verifica se há pelo menos um produto válido
$itensValidos = [];
$itensNotaModel = new ItensNota();

foreach ($produtos as $index => $produto) {
    if (!empty($produto['id_produto_sistema']) && is_numeric($produto['id_produto_sistema']) &&
        is_numeric($produto['quantidade']) && floatval($produto['quantidade']) > 0) {
        $itensValidos[$produto['id_produto_sistema']] = floatval($produto['quantidade']);
        $valoresUnitarios[$produto['id_produto_sistema']] = floatval($produto['valor_unitario'] ?? 0);
    }

      
    $existing = $itensNotaModel->getByNomeXml($produto['nome_xml']);
    if (!$existing && !empty($produto['id_produto_sistema'])) {
        $itensNotaModel->insert($produto['nome_xml'], $produto['id_produto_sistema']);
    }
}
if (empty($itensValidos)) {
    Session::setFlash('error', 'Nenhum produto associado para registrar a entrada.');
    header('Location: ../movimentacao/processar_nfe.php');
    exit();
}

// Inicializa conexão com banco antes do bloco try para garantir rollback seguro
$db = Database::getInstance();

try {

  
    

    // 1. Verificar/Cadastrar Fornecedor
    $fornecedorModel = new Fornecedor();
    $fornecedorId = null;

    if ($idFornecedorSistema === 'cadastrar_novo') {
        $existentePorCnpj = $db->query(
            "SELECT id FROM fornecedores WHERE REPLACE(REPLACE(REPLACE(cnpj, '.', ''), '/', ''), '-', '') = :cnpj LIMIT 1",
            [':cnpj' => $cnpj]
        );
        if (!empty($existentePorCnpj)) {
            $fornecedorId = $existentePorCnpj[0]['id'];
        } else {
            // Cadastra novo fornecedor
            $fornecedorData = [
                'cnpj' => $cnpj,
                'razao_social' => $razaoSocial,
                'tipo' => 'PJ',
                'ativo' => 1,
                'criado_por' => $_SESSION['user_id'] ?? null
            ];
            $fornecedorId = $fornecedorModel->create($fornecedorData);
            if (!$fornecedorId) {
                throw new Exception('Erro ao cadastrar fornecedor.');
            }
        }
    } else {
        // Verifica fornecedor existente
        $fornecedorExistente = $fornecedorModel->getById($idFornecedorSistema);
        if (!$fornecedorExistente) {
            throw new Exception('Fornecedor selecionado inválido.');
        }
        $fornecedorId = $fornecedorExistente['id'];
        
        // Valida correspondência de CNPJ
        $cnpjDb = preg_replace('/[^0-9]/', '', $fornecedorExistente['cnpj']);
        if ($cnpjDb !== $cnpj) {
            throw new Exception('CNPJ do fornecedor selecionado não corresponde ao da nota.');
        }
    }

    // 2. Criar Movimentação
    $tipoMovimentacaoModel = new TipoMovimentacao();
    $tiposEntrada = $tipoMovimentacaoModel->findByTipo('ENTRADA');
    if (empty($tiposEntrada)) {
        throw new Exception('Nenhum tipo de movimentação de entrada configurado.');
    }
    $tipoMovimentacaoId = $tiposEntrada[0]['id']; // Assume o primeiro tipo de entrada (ex: "Compra por NF-e")

    $movimentacaoModel = new Movimentacao();
    $observacao = "Entrada via NF-e {$notaNumero}";
    $usuarioId = $_SESSION['user_id'] ?? null;
    $movimentacaoId = $movimentacaoModel->criar($tipoMovimentacaoId, $usuarioId, $observacao, $fornecedorId);
    if (!$movimentacaoId) {
        throw new Exception('Erro ao criar movimentação.');
    }

    // 3. Adicionar Itens à Movimentação
    $movimentacaoItemModel = new MovimentacaoItem();
    if (!$movimentacaoItemModel->adicionarItens($movimentacaoId, $itensValidos, $valoresUnitarios)) {
        throw new Exception('Erro ao registrar itens da movimentação.');
    }

    // 3.1 Adicioanar o valor total da movimentação
    $notaValorTotal = floatval($notaValorTotal);
    if (!$movimentacaoModel->atualizarValorTotal($movimentacaoId, $notaValorTotal, $notaNumero)) {
        throw new Exception('Erro ao atualizar valor total da movimentação.');
    }

    // Inicia transação
    $db->beginTransaction();
    // 4. Registrar Nota Fiscal
    $notaData = [
        'movimentacao_id' => $movimentacaoId,
        'numero_nota' => $notaNumero,
        'chave_acesso' => $notaChaveAcesso,
        'data_emissao' => date('Y-m-d', strtotime($notaDataEmissao)),
        'fornecedor_id' => $fornecedorId,
        'valor_total' => $notaValorTotal,
        'processado' => true,
        'usuario_id' => $usuarioId
        // 'arquivo_xml' => base64_encode(file_get_contents($uploadedFile['tmp_name'])) // Opcional, se XML for salvo
    ];
    if (!$db->insert('notas_fiscais', $notaData)) {
        throw new Exception('Erro ao registrar nota fiscal.');
    }

    // 5. Commit da transação
    $db->commit();

    // Registrar na sincronizacao para envio ao web
    // a inicialização do helper já é feita pelo Database quando criada a conexão,
    // portanto não precisamos reinicializar. apenas obtenha o último ID
    $notaFiscalId = $db->lastInsertId();
    $syncPayload = [
        'fornecedor' => $idFornecedorSistema,
        'cnpj' => $cnpj,
        'razao_social' => $razaoSocial,
        'itens' => $itensValidos,
        'valores' => $valoresUnitarios,
        'total' => $notaValorTotal,
        'numero' => $notaNumero,
        'chave_acesso' => $notaChaveAcesso,
        'data' => $notaDataEmissao,
        'user_id' => $usuarioId,
        'nomes_xml' => array_column($produtos, 'nome_xml', 'id_produto_sistema') // Mapear nomes_xml
    ];
    // gravação no log de sincronia para troubleshooting
    $logMessage = "[[ENTRADA_NFE local]] nota={$notaNumero} fornecedor={$fornecedorId} movId={$movimentacaoId} payload=" . json_encode($syncPayload, JSON_UNESCAPED_UNICODE);
    error_log($logMessage . "\n", 3, LOG_PATH . '/sync_nfe.log');

    try {
        SyncQueueHelper::queueChange('notas_fiscais', $notaFiscalId, 'ENTRADA_NFE', $syncPayload);
        error_log("[sync] payload enfileirado com sucesso para nota {$notaNumero}\n", 3, LOG_PATH . '/sync_nfe.log');
    } catch (Exception $syncEx) {
        error_log("[sync] falha ao enfileirar nota {$notaNumero}: " . $syncEx->getMessage() . "\n", 3, LOG_PATH . '/sync_nfe.log');
    }

    Session::setFlash('success', 'Entrada registrada com sucesso!');
    header('Location: ../admin/entradas.php');
    exit();

} catch (Exception $e) {
    // Rollback em caso de erro (apenas se houver transação ativa)
    if ($db->inTransaction()) {
        $db->rollback();
    }
    error_log("Erro em registrar_entrada.php: " . $e->getMessage());
    Session::setFlash('error', 'Erro ao registrar entrada: ' . $e->getMessage());
    header('Location: ../movimentacao/processar_nfe.php');
    exit();
}
?>