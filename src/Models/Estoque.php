<?php

namespace Models;

use PDO;

class Estoque extends BaseModel
{
    protected $table = 'estoques';
    /**
     * Insere uma unidade individual no estoque.
     */
    public function inserirUnidade(int $produtoId, float $valorUnitario): bool
    {
        $sql = "INSERT INTO estoques (produto_id, valor_unitario) VALUES (?, ?)";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$produtoId, $valorUnitario]);
    }

    /**
     * Remove N unidades mais antigas (FIFO).
     */
    public function removerUnidades(int $produtoId, int $quantidade): bool
    {
        $sql = "DELETE FROM estoques WHERE produto_id = ? ORDER BY data_entrada ASC LIMIT ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$produtoId, $quantidade]);
    }

     /**
     * Remove N unidades mais antigas (LIFO).
     */
    public function removerUnidadesLifo(int $produtoId, int $quantidade): bool
    {
        $sql = "DELETE FROM estoques WHERE produto_id = ? ORDER BY data_entrada DESC LIMIT ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$produtoId, $quantidade]);
    }

    /**
     * Obtém o estoque atual (contagem de unidades).
     */
    public function getEstoqueAtual(int $produtoId): int
    {
        $sql = "SELECT COUNT(*) as total FROM estoques WHERE produto_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$produtoId]);
        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    }

     public function getValorUltimo(int $produtoId): int
    {
        $sql =  "SELECT valor_unitario FROM estoques WHERE produto_id = ? ORDER BY data_entrada DESC LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$produtoId]);
        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['valor_unitario'];
    }
}