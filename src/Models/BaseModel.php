<?php
/**
 * Classe BaseModel - Modelo base para todos os models
 * src/Models/BaseModel.php
 */

namespace Models;

$databasePath = __DIR__ . '/Database.php';
if (!file_exists($databasePath)) {
    error_log("BaseModel.php: Não encontrou Database.php em $databasePath", 3, __DIR__ . '/../../logs/error.log');
    die("Erro: Não foi possível carregar Database.php.");
}
require_once $databasePath;



abstract class BaseModel {
    protected $db;
    protected $table;
    protected $primaryKey = 'id';
    protected $fillable = [];
    protected $timestamps = true;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    // Buscar todos os registros
    public function all($where = '', $params = [], $order = '') {
        $sql = "SELECT * FROM {$this->table}";
        
        if (!empty($where)) {
            $sql .= " WHERE $where";
        }
        
        if (!empty($order)) {
            $sql .= " ORDER BY $order";
        }
        
        return $this->db->query($sql, $params);
    }
    
    // Buscar por ID
    public function find($id) {
        $sql = "SELECT * FROM {$this->table} WHERE {$this->primaryKey} = :id";
        return $this->db->queryOne($sql, [':id' => $id]);
    }
    
    // Buscar um registro por condição
    public function findBy($field, $value) {
        $sql = "SELECT * FROM {$this->table} WHERE $field = :value";
        return $this->db->queryOne($sql, [':value' => $value]);
    }
    
    // Buscar múltiplos registros por condição
    public function where($field, $value, $operator = '=') {
        $sql = "SELECT * FROM {$this->table} WHERE $field $operator :value";
        return $this->db->query($sql, [':value' => $value]);
    }

    // Buscar múltiplos registros por condição e ordenados
    public function whereby($field, $value, $orderby, $operator = '=') {
        $sql = "SELECT * FROM {$this->table} WHERE $field $operator :value";
        $sql .= str_replace("'", "", " ORDER BY $orderby");
        return $this->db->query($sql, [':value' => $value]);
    }
    
    // Criar novo registro
    public function create($data) {
        // Filtrar apenas campos permitidos
        $filtered = $this->filterFillable($data);
        
        // Adicionar timestamps se habilitado
        if ($this->timestamps) {
            $filtered['criado_em'] = date('Y-m-d H:i:s');
            $filtered['atualizado_em'] = date('Y-m-d H:i:s');
        }
        
        // Registrar auditoria
        $id = $this->db->insert($this->table, $filtered);
        
        if ($id) {
            $this->logAudit('INSERT', $id, null, $filtered);
        }
        
        return $id;
    }
    
    // Atualizar registro
    public function update($id, $data) {
        // Buscar dados anteriores para auditoria
        $oldData = $this->find($id);
        
        // Filtrar apenas campos permitidos
        $filtered = $this->filterFillable($data);
        
        // Adicionar timestamp de atualização se habilitado
        if ($this->timestamps) {
            $filtered['atualizado_em'] = date('Y-m-d H:i:s');
        }
        
        $where = "{$this->primaryKey} = :id";
        $result = $this->db->update($this->table, $filtered, $where, [':id' => $id]);
        
        if ($result) {
            $this->logAudit('UPDATE', $id, $oldData, $filtered);
        }
        
        return $result;
    }
    
    // Deletar registro
    public function delete($id) {
        // Buscar dados para auditoria
        $oldData = $this->find($id);
        
        $where = "{$this->primaryKey} = :id";
        $result = $this->db->delete($this->table, $where, [':id' => $id]);
        
        if ($result) {
            $this->logAudit('DELETE', $id, $oldData, null);
        }
        
        return $result;
    }
    
    // Soft delete (marcar como inativo)
    public function softDelete($id) {
        return $this->update($id, ['ativo' => false]);
    }
    
    // Contar registros
    public function count($where = '', $params = []) {
        $sql = "SELECT COUNT(*) as total FROM {$this->table}";
        
        if (!empty($where)) {
            $sql .= " WHERE $where";
        }
        
        $result = $this->db->queryOne($sql, $params);
        return $result['total'];
    }
    
    // Verificar se existe
    public function exists($field, $value, $excludeId = null) {
        $where = "$field = :value";
        $params = [':value' => $value];
        
        if ($excludeId) {
            $where .= " AND {$this->primaryKey} != :id";
            $params[':id'] = $excludeId;
        }
        
        return $this->db->exists($this->table, $where, $params);
    }
    
    // Paginação
    public function paginate($page = 1, $perPage = ITEMS_PER_PAGE, $where = '', $params = [], $order = '') {
        $offset = ($page - 1) * $perPage;
        
        $sql = "SELECT * FROM {$this->table}";
        
        if (!empty($where)) {
            $sql .= " WHERE $where";
        }
        
        if (!empty($order)) {
            $sql .= " ORDER BY $order";
        }
        
        $sql .= " LIMIT $perPage OFFSET $offset";
        
        $data = $this->db->query($sql, $params);
        $total = $this->count($where, $params);
        
        return [
            'data' => $data,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'total_pages' => ceil($total / $perPage)
        ];
    }
    
    // Filtrar campos permitidos
    protected function filterFillable($data) {
        if (empty($this->fillable)) {
            return $data;
        }
        
        return array_intersect_key($data, array_flip($this->fillable));
    }
    
    // Log de auditoria
    public function logAudit($action, $recordId, $oldData = null, $newData = null) {
        $userId = $_SESSION['user_id'] ?? null;
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        $auditData = [
            'tabela' => $this->table,
            'registro_id' => $recordId,
            'acao' => $action,
            'dados_anteriores' => $oldData ? json_encode($oldData) : null,
            'dados_novos' => $newData ? json_encode($newData) : null,
            'usuario_id' => $userId,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent
        ];
        
        $this->db->insert('auditoria', $auditData);
    }
    
    // Executar query personalizada
    public function rawQuery($sql, $params = []) {
        return $this->db->query($sql, $params);
    }
    
    // Executar query personalizada para um resultado
    public function rawQueryOne($sql, $params = []) {
        return $this->db->queryOne($sql, $params);
    }
    
    // Iniciar transação
    public function beginTransaction() {
        return $this->db->beginTransaction();
    }
    
    // Confirmar transação
    public function commit() {
        return $this->db->commit();
    }
    
    // Reverter transação
    public function rollback() {
        return $this->db->rollback();
    }
}