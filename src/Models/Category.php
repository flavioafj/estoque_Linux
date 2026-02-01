<?php
namespace Models;

use Models\BaseModel;
$baseModelPath = __DIR__ . '/BaseModel.php';
if (!file_exists($baseModelPath)) {
    error_log("Category.php: Não encontrou BaseModel.php em $baseModelPath", 3, __DIR__ . '/../../logs/error.log');
    die("Erro: Não foi possível carregar BaseModel.php.");
}
//require_once $baseModelPath;

class Category extends BaseModel {
    protected $table = 'categorias';
    protected $fillable = ['nome', 'descricao', 'ativo', 'criado_por'];

    public function create($data) {
        $data['criado_por'] = $_SESSION['user_id'] ?? null;
        return parent::create($data);
    }

    public function update($id, $data) {
        return parent::update($id, $data);
    }

    public function delete($id) {
        return parent::softDelete($id);
    }

    public function getAll() {
        return $this->where('ativo', 1);

    }

    public function getAllInactive() {
        return $this->where('ativo', 0);
    }

    public function getById($id) {
        return $this->find($id);
    }
}