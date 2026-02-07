<?php
/**
 * Classe Database - Conexão e operações com banco de dados
 * src/Models/Database.php
 */

namespace Models;

use PDO;
use PDOException;
use Helpers\SyncQueueHelper;

class Database {
    private static $instance = null;
    private $connection;
    private $statement;
    
    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET . ";port=" . DB_PORT;
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, PDO_OPTIONS);
            SyncQueueHelper::initialize($this->connection);
        } catch (PDOException $e) {
            $this->logError("Erro de conexão: " . $e->getMessage());
            die("Erro ao conectar com o banco de dados. Por favor, tente novamente mais tarde.");
        }
    }
    
    // Singleton pattern
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    // Obter conexão PDO
    public function getConnection() {
        return $this->connection;
    }
    
    // Preparar query
    public function prepare($sql) {
        try {
            $this->statement = $this->connection->prepare($sql);
            
            return $this;
        } catch (PDOException $e) {
            $this->logError("Erro ao preparar query: " . $e->getMessage() . " SQL: " . $sql);
            return false;
        }
    }
    
    // Bind de parâmetros
    public function bind($param, $value, $type = null) {
        if (is_null($type)) {
            switch (true) {
                case is_int($value):
                    $type = PDO::PARAM_INT;
                    break;
                case is_bool($value):
                    $type = PDO::PARAM_BOOL;
                    break;
                case is_null($value):
                    $type = PDO::PARAM_NULL;
                    break;
                default:
                    $type = PDO::PARAM_STR;
            }
        }
        
        try {
            $this->statement->bindValue($param, $value, $type);
            $this->logError("Parâmetro associado: $param = " . (is_null($value) ? 'NULL' : $value) . " (tipo: $type)");
            return $this;
        } catch (PDOException $e) {
            $this->logError("Erro ao associar parâmetro $param: " . $e->getMessage());
            return false;
        }
    }
    
    // Executar query
    public function execute($params = []) {
        try {
            if (empty($params)) {
                return $this->statement->execute();
            } else {
                return $this->statement->execute($params);
            }
        } catch (PDOException $e) {
            $this->logError("Erro ao executar query: " . $e->getMessage());
            return false;
        }
    }
    
    // Obter todos os resultados
    public function fetchAll() {
        return $this->statement->fetchAll();
    }
    
    // Obter um único resultado
    public function fetch() {
        return $this->statement->fetch();
    }
    
    // Obter última ID inserida
    public function lastInsertId() {
        return $this->connection->lastInsertId();
    }
    
    // Contar linhas afetadas
    public function rowCount() {
        return $this->statement->rowCount();
    }
    
    // Iniciar transação
    public function beginTransaction() {
        return $this->connection->beginTransaction();
    }
    
    // Confirmar transação
    public function commit() {
        return $this->connection->commit();
    }
    
    // Reverter transação
    public function rollback() {
        if ($this->connection->inTransaction()) {
            return $this->connection->rollBack();
        }
        $this->logError("Tentativa de rollback sem transação ativa.");
        return false;
    }
    
    // Query rápida com resultado
    public function query($sql, $params = []) {
         if ($this->prepare($sql) === false) {
            return [];
        }
        
        // Log para debugging
        $this->logError("Preparando query: $sql com parâmetros: " . json_encode($params));
        
        foreach ($params as $key => $value) {
            if (!$this->bind($key, $value)) {
                return [];
            }
        }
       
        if ($this->execute()) {
            // Debug após binding
            ob_start();
            $this->statement->debugDumpParams();
            $this->logError("Debug PDO após binding: " . ob_get_clean());
            return $this->fetchAll();
        }
        
        return [];
    }
    
    // Query rápida para um único resultado
    public function queryOne($sql, $params = []) {
        $this->prepare($sql);
        
        if ($this->statement === null || $this->statement === false) {
            $this->logError("Falha ao preparar query: $sql");
            return null;
        }
        
        if (!empty($params)) {
            foreach ($params as $key => $value) {
                $this->bind($key, $value);
            }
        }
        
        if ($this->execute()) {
            return $this->fetch();
        }
        
        return null;
    }
    
    // Inserir registro
    public function insert($table, $data) {
        $fields = array_keys($data);
        $values = array_map(function($field) { return ':' . $field; }, $fields);
        
        $sql = "INSERT INTO $table (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $values) . ")";
        
        $this->prepare($sql);

        $teste = $sql;
        
        foreach ($data as $key => $value) {
            $teste = str_replace(':' . $key, $value, $teste);
            $this->bind(':' . $key, $value);
        }
        
        if ($this->execute()) {
            return $this->lastInsertId();
        }
        
        return false;
    }
    
    // Atualizar registro
    public function update($table, $data, $where, $whereParams = []) {
        $fields = [];
        foreach ($data as $key => $value) {
            $fields[] = "$key = :$key";
        }
        
        $sql = "UPDATE $table SET " . implode(', ', $fields) . " WHERE $where";
        
        $this->prepare($sql);
        $teste = $sql;
        foreach ($data as $key => $value) {
            $teste = str_replace(':' . $key, $value, $teste);
            $this->bind(':' . $key, $value);
        }
        
        foreach ($whereParams as $key => $value) {
            $this->bind($key, $value);
        }
        
        return $this->execute();
    }
    
    // Deletar registro
    public function delete($table, $where, $params = []) {
        $sql = "DELETE FROM $table WHERE $where";
        
        $this->prepare($sql);
        
        foreach ($params as $key => $value) {
            $this->bind($key, $value);
        }
        
        return $this->execute();
    }
    
    // Verificar se existe
    public function exists($table, $where, $params = []) {
        $sql = "SELECT COUNT(*) as count FROM $table WHERE $where";
        $result = $this->queryOne($sql, $params);
        return $result['count'] > 0;
    }
    
    // Log de erros
    private function logError($message) {
        $date = date('Y-m-d H:i:s');
        $log = "[$date] [DATABASE ERROR] $message" . PHP_EOL;
        file_put_contents(LOG_PATH . '/database_error.log', $log, FILE_APPEND);
    }

    public function getPendingSync($limit = 100) {
        return $this->query("SELECT * FROM sincronizacao WHERE sincronizado = 0 AND tentativas < 3 LIMIT :limit", [':limit' => $limit]);
    }

    public function updateSyncStatus($id, $sincronizado, $erro_mensagem = null) {
        $data = ['sincronizado' => $sincronizado, 'sincronizado_em' => date('Y-m-d H:i:s')];
        if ($erro_mensagem) {
            $data['erro_mensagem'] = $erro_mensagem;
            $data['tentativas'] = new \PDOStatement('tentativas + 1');
        }
        return $this->update('sincronizacao', $data, "id = :id", [':id' => $id]);
    }

    public function inTransaction()
    {
        // Altere para o nome da variável que guarda o PDO dentro do seu Wrapper
        // Exemplo: $this->pdo ou $this->connection
        return $this->connection && $this->connection->inTransaction();
    }
    
    // Destrutor
    public function __destruct() {
        $this->connection = null;
    }
}
                    