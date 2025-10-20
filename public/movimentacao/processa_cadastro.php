<?php
use Helpers\Session;
use Models\Fornecedor;

require_once '../../config/config.php';
// Inclua seus arquivos de configuração, modelos e a função cadastra_fornecedor

header('Content-Type: application/json');

Session::start();

// Simulação de onde vêm os dados (AJAX deve enviá-los via POST)
$cnpj = $_POST['cnpj'] ?? ''; // Certifique-se de validar e sanitizar
$razaoSocial = $_POST['razao_social'] ?? '';

// Simule a inicialização do seu model (você precisa fazer isso corretamente)
// global $fornecedorModel; 
// $fornecedorModel = new FornecedorModel(); 

try {
    // A função cadastra_fornecedor() precisa ter acesso a $cnpj, $razaoSocial e $fornecedorModel
    // Idealmente, ela deveria aceitar $cnpj e $razaoSocial como argumentos.
    // Vamos assumir que ela foi ajustada para aceitar os dados:
    function cadastra_fornecedor($fornecedorData){
 // Cadastra novo fornecedor
        $fornecedorModel = new Fornecedor();
        $fornecedorId = null;
       
        $fornecedorId = $fornecedorModel->create($fornecedorData);
        if (!$fornecedorId) {
            throw new Exception('Erro ao cadastrar fornecedor.');
        }
        return "Fornecedor cadastrado";
    }


    $dadosCadastro = [
        'cnpj' => $cnpj,
        'razao_social' => $razaoSocial,
        'tipo' => 'PJ',
        'ativo' => 1,
        'criado_por' => $_SESSION['user_id'] ?? null
    ];

  
    
    $mensagem = cadastra_fornecedor($dadosCadastro);

    echo json_encode(['success' => true, 'message' => $mensagem]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>