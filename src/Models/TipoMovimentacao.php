<?php

namespace Models;

use Models\BaseModel;

class TipoMovimentacao extends BaseModel
{
    protected $table = 'tipos_movimentacao';

    /**
     * Busca todos os tipos de movimentação por categoria ('ENTRADA' ou 'SAIDA').
     *
     * @param string $tipo
     * @return array
     */
    public function findByTipo(string $tipo): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE tipo = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$tipo]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}