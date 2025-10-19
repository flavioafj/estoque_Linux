<?php
/**
 * Modelo de Auditoria
 * src/Models/Audit.php
 */

namespace Models;

class Audit extends BaseModel {
    protected $table = 'auditoria';

    public function getLogs($filters = []) {
        $sql = "SELECT 
                    a.id, a.tabela, a.registro_id, a.acao, a.dados_anteriores, a.dados_novos,
                    u.nome_completo AS usuario, a.ip_address, a.user_agent, a.criado_em
                FROM {$this->table} a
                LEFT JOIN usuarios u ON a.usuario_id = u.id
                WHERE 1=1";

        $params = [];

        if (!empty($filters['tabela'])) {
            $sql .= " AND a.tabela = :tabela";
            $params[':tabela'] = $filters['tabela'];
           
        }
        if (!empty($filters['acao'])) {
            $sql .= " AND a.acao = :acao";
            $params[':acao'] = $filters['acao'];
            
        }
        if (!empty($filters['usuario_id'])) {
            $sql .= " AND a.usuario_id = :usuario_id";
            $params[':usuario_id'] = $filters['usuario_id'];
            
        }
        if (!empty($filters['data_inicio'])) {
            $sql .= " AND a.criado_em >= :data_inicio";
            $params[':data_inicio'] = $filters['data_inicio'];
            

        }
        if (!empty($filters['data_fim'])) {
            $sql .= " AND a.criado_em <= :data_fim";
            $params[':data_fim'] = $filters['data_fim'];
            
        }

        $sql .= " ORDER BY a.criado_em DESC LIMIT 100";

        return $this->rawQuery($sql, $params);
    }
}