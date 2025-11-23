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

    public function getValorFIFO(int $produtoId): int
    {
        $sql =  "SELECT valor_unitario FROM estoques WHERE produto_id = ? ORDER BY data_entrada ASC LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$produtoId]);
        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['valor_unitario'];
    }

    // Note que mudei o nome do argumento para $id para simplificar
    public function getSomaValores(int $id, int $q, string $tip0 = "ASC"): float
    {
        
        $ordem = (strtoupper($tip0) === 'DESC') ? 'DESC' : 'ASC';

        
        $sql = "SELECT SUM(valor_unitario) as total 
                FROM (
                    SELECT valor_unitario 
                    FROM estoques 
                    WHERE produto_id = :id 
                    ORDER BY id $ordem 
                    LIMIT :qtd
                ) as ultimos_registros";

        $stmt = $this->db->prepare($sql);

        // 3. Correção do método: usar bindValue
        $stmt->bind(':id', $id, PDO::PARAM_INT);
        // O :tip0 não é mais necessário no bind, pois já está na string acima
        $stmt->bind(':qtd', $q, PDO::PARAM_INT);
        
        $stmt->execute();
        
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);

        return (float) ($resultado['total'] ?? 0);
    }


}