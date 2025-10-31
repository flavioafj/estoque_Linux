<?php

namespace Models;

use Models\BaseModel;
use Models\Estoque;

class Movimentacao extends BaseModel
{
    protected $table = 'movimentacoes';

    /**
     * Cria uma nova movimentação e retorna seu ID.
     *
     * @param int $tipoMovimentacaoId
     * @param int $usuarioId
     * @param string $observacao
     * @param int|null $fornecedorId
     * @return int|false
     */
    public function criar(int $tipoMovimentacaoId, int $usuarioId, string $observacao = '', ?int $fornecedorId = null, ?int $inventarioId = null): int|false
    {
        $sql = "INSERT INTO {$this->table} (tipo_movimentacao_id, data_movimentacao, usuario_id, fornecedor_id, observacoes, status, inventario_id) VALUES (?, NOW(), ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        $status = 'PROCESSADO';
        
        if ($stmt->execute([$tipoMovimentacaoId, $usuarioId, $fornecedorId, $observacao, $status, $inventarioId])) {
            return $this->db->lastInsertId();
        }
        return false;
    }

    public function atualizarValorTotal(int $movimentacaoId, float $valorTotal, int $nrNota = null)
    {
        $sql = "UPDATE {$this->table} SET valor_total = ?, documento_numero = ? WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        
        if ($stmt === false) {
            error_log("Erro ao preparar query em atualizarValorTotal: " . print_r($this->db->errorInfo(), true), 3, __DIR__ . '/../../logs/error.log');
            return false;
        }
        
        try {
            $stmt->execute([$valorTotal, $nrNota, $movimentacaoId]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            error_log("Erro ao executar atualizarValorTotal: " . $e->getMessage(), 3, __DIR__ . '/../../logs/error.log');
            return false;
        }
    }

    /**  
     * Registra uma saída direta para um produto.  
     *  
     * @param int $produtoId  
     * @param float $quantidade  
     * @param int $usuarioId  
     * @param string $observacao  
     * @return bool  
     */  
    public function registrarSaidaDireta(int $produtoId, float $quantidade, int $usuarioId, string $observacao = ''): bool {  
        // Assume tipo_movimentacao_id para 'Venda' ou similar é 5 (baseado em dados iniciais; ajuste se necessário)  
        $tipoSaidaId = 5; // 'Venda' como exemplo; confirme o ID real da tabela tipos_movimentacao  
        $movimentacaoId = $this->criar($tipoSaidaId, $usuarioId, $observacao);  
        if (!$movimentacaoId) {  
            return false;  
        }  
        $movimentacaoItem = new MovimentacaoItem();  
        $itens = [$produtoId => $quantidade];  
        $valoresUnitarios = [$produtoId => 0]; // Valor unitário 0 para saídas sem custo  
        if (!$movimentacaoItem->adicionarItens($movimentacaoId, $itens, $valoresUnitarios)) {  
            return false;  
        }  
        $this->atualizarValorTotal($movimentacaoId, 0); // Valor total 0 para saídas  
        return true;  
    } 
    
    //usado apenas para ajuste pós inventário
    public function registrarAjusteEntrada(int $produtoId, float $quantidade, int $usuarioId, string $observacao = 'Ajuste de Inventário (+)', ?int $inventarioId = null): bool {  
        $tipoId = 3; // ID para Ajuste de Inventário (+)  
        $qtdEst = new Estoque();
        $val = $qtdEst->getValorUltimo($produtoId);
        $movId = $this->criar($tipoId, $usuarioId, $observacao, null, $inventarioId);  
        if (!$movId) return false;  
        $item = new MovimentacaoItem();  
        if (!$item->adicionarItens($movId, [$produtoId => $quantidade], [$produtoId => $val])) {  
            return false;  
        }  
        $this->atualizarValorTotal($movId, 0);  
        return true;  
    }  

        //usado apenas para ajuste pós inventário
    public function registrarAjusteSaida(int $produtoId, float $quantidade, int $usuarioId, string $observacao = 'Ajuste de Inventário (-)', ?int $inventarioId = null): bool {  
        $tipoId = 7; // ID para Ajuste de Inventário (-)  
        $qtdEst = new Estoque();
        $val = $qtdEst->getValorUltimo($produtoId);
        $movId = $this->criar($tipoId, $usuarioId, $observacao, null, $inventarioId);  
        if (!$movId) return false;  
        $item = new MovimentacaoItem();  
        if (!$item->adicionarItens($movId, [$produtoId => $quantidade], [$produtoId => $val])) {  
            return false;  
        }  
        $this->atualizarValorTotal($movId, 0);  
        return true;  
    }
}