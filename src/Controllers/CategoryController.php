<?php
use Middleware\Auth;
use Helpers\Validator;
use Models\Category;


require_once __DIR__ . '/../Helpers/Validator.php';
require_once __DIR__ . '/../Helpers/Session.php'; // Conforme Parte 1
require_once __DIR__ . '/../Middleware/Auth.php'; // Conforme Parte 1

// Função auxiliar para verificar se há erros reais
function hasErrors($errors) {
    foreach ($errors as $fieldErrors) {
        if (!empty($fieldErrors)) {
            return true;
        }
    }
    return false;
}

class CategoryController {
    public $categoryModel;

    public function __construct() {
        $this->categoryModel = new Category();
        Auth::checkAdmin(); // Conforme Parte 1
    }

    public function index() {
        $categories = $this->categoryModel->getAll();
        include __DIR__ . '/../../public/admin/categories.php';
    }

    public function getAllCategories() {
        return $this->categoryModel->getAll();
    }

    public function store() {
        $data = $_POST;
        $errors = Validator::validate($data, [
            'nome' => 'required|unique:categorias,nome',
            'descricao' => 'optional',
            'ativo' => 'boolean'
        ]);

        if (hasErrors($errors)) {
            $_SESSION['errors'] = $errors;
            header('Location: /admin/categories.php');
            exit;
        }

        $data['ativo'] = isset($data['ativo']) ? 1 : 0;
        $this->categoryModel->create($data);
        $_SESSION['success'] = 'Categoria criada com sucesso.';
        header('Location: /admin/categories.php');
    }

    public function update($id) {
        $data = $_POST;
        $errors = Validator::validate($data, [
            'nome' => "required|unique:categorias,nome,$id",
            'descricao' => 'optional',
            'ativo' => 'boolean'
        ]);

        if (hasErrors($errors)) {
            $_SESSION['errors'] = $errors;
            header('Location: /admin/categories.php');
            exit;
        }

        $data['ativo'] = isset($data['ativo']) ? 1 : 0;
        $this->categoryModel->update($id, $data);
        $_SESSION['success'] = 'Categoria atualizada.';
        header('Location: /admin/categories.php');
    }

    public function destroy($id) {
        $this->categoryModel->delete($id);
        $_SESSION['success'] = 'Categoria desativada.';
        header('Location: /admin/categories.php');
    }

    public function reactivate($id) {
        $this->categoryModel->update($id, ['ativo' => 1]);
        $_SESSION['success'] = 'Categoria reativada com sucesso.';
        header('Location: /admin/categories.php?view=inactive');
        exit;
    }
}