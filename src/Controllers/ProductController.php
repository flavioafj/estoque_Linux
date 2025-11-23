<?php
namespace Controllers;

use Helpers\Validator;
use Models\Category;
use Models\Fornecedor;
use Models\Product;  
use Models\UnidadeDeMedida;

//$productPath = __DIR__ . '/../Models/Product.php';
$validatorPath = __DIR__ . '/../Helpers/Validator.php';


/* if (!file_exists($productPath)) {
    error_log("ProductController.php: Não encontrou Product.php em $productPath", 3, __DIR__ . '/../../logs/error.log');
    die("Erro: Não foi possível carregar Product.php.");
} */

if (!file_exists($validatorPath)) {
    error_log("ProductController.php: Não encontrou Validator.php em $validatorPath", 3, __DIR__ . '/../../logs/error.log');
    die("Erro: Não foi possível carregar Validator.php.");
}



//require_once $productPath;


// Função auxiliar para verificar se há erros reais
function hasErrors($errors) {
    foreach ($errors as $fieldErrors) {
        if (!empty($fieldErrors)) {
            return true;
        }
    }
    return false;
}


class ProductController {
    public $productModel;
    public $categoryModel;
    public $fornecedorModel;
    public $unidadeMedidaModel;

    public function __construct() {
        $this->productModel = new Product();
        $this->categoryModel = new Category();
        $this->fornecedorModel = new Fornecedor();
        $this->unidadeMedidaModel = new UnidadeDeMedida();
    }

    public function store($data) {
        $rules = [
            'nome' => 'required|unique:produtos,nome',
            'categoria_id' => 'required|exists:categorias,id',
            'estoque_atual' => 'numeric|min:0',
            'estoque_minimo' => 'required|numeric|min:0',
            'ativo' => 'boolean',
            'unidade_medida_id' => 'required|exists:unidades_medida,id',
            'estoque_maximo' => 'numeric|min:0',
            'preco_custo' => 'numeric|min:0',
            'margem_lucro' => 'numeric|min:0',
            'fornecedor_principal_id' => 'fornecedores,id'
        ];

         if($data['fornecedor_principal_id']==''){
            $data['fornecedor_principal_id']= null;
        }

        $errors = Validator::validate($data, $rules);

       

        if (hasErrors($errors)) {
            $_SESSION['errors'] = $errors;
            header('Location: /admin/products.php');
            exit;
        }

        

        $id = $this->productModel->create($data);
        if ($id) {
            $_SESSION['success'] = 'Produto criado com sucesso!';
            header('Location: /admin/products.php');
            echo json_encode(['success' => 'Produto criado com sucesso!', 'id' => $id]);
            exit;
        }

        $_SESSION['errors'] = ['general' => ['Erro ao criar produto.']];
        //header('Location: /admin/products.php');
        echo json_encode(['error' => 'Erro ao criar produto.']);
        exit;
    }

    public function update($id, $data) {
        $rules = [
            'nome' => "required|unique:produtos,nome,$id",
            'codigo' => "unique:produtos,codigo,$id",
            'categoria_id' => 'required|exists:categorias,id',
            'estoque_atual' => 'numeric|min:0',
            'estoque_minimo' => 'numeric|min:0',
            'ativo' => 'boolean',
            'unidade_medida_id' => 'exists:unidades_medida,id',
            'fornecedor_principal_id' => 'exists:fornecedores,id'
        ];

        $errors = Validator::validate($data, $rules);

        
        if (hasErrors($errors)) {
            $_SESSION['errors'] = $errors;
            header('Location: /admin/products.php');
            exit;
        }

        $result = $this->productModel->update($id, $data);
        if ($result) {
            $_SESSION['success'] = 'Produto atualizado com sucesso!';
            header('Location: /admin/products.php');
            exit;
        }

        $_SESSION['errors'] = ['general' => ['Erro ao atualizar produto.']];
        header('Location: /admin/products.php');
        exit;
    }

    public function reactivate($id) {
        $result = $this->productModel->update($id, ['ativo' => 1]);
        if ($result) {
            $_SESSION['success'] = 'Produto reativado com sucesso!';
        } else {
            $_SESSION['errors'] = ['general' => ['Erro ao reativar produto.']];
        }
        header('Location: /admin/products.php?view=inactive');
        exit;
    }

    public function destroy($id) {
        $result = $this->productModel->delete($id);
        if ($result) {
            $_SESSION['success'] = 'Produto desativado com sucesso!';
            header('Location: /admin/products.php');
            exit;
        }

        $_SESSION['errors'] = ['general' => ['Erro ao desativar produto.']];
        header('Location: /admin/products.php');
        exit;
    }
}