<?php

namespace Models;

use Models\BaseModel;
use Models\Estoque;
use Helpers\SyncQueueHelper;

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

                $lstId = $this->db->lastInsertId();
            return $lstId;

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
        $qtdEst = new Estoque();
        $val = $qtdEst->getValorFIFO($produtoId);
        $valoresUnitarios = [$produtoId => $val]; // Valor unitário FIFO
        if (!$movimentacaoItem->adicionarItens($movimentacaoId, $itens, $valoresUnitarios)) {  
            return false;  
        }  
        $vlrtotal = $quantidade * $val;
        $this->atualizarValorTotal($movimentacaoId, $quantidade * $val); // Valor total 

         SyncQueueHelper::queueChange(
            'movimentacoes',
            $movimentacaoId,
            'SAIDA_DIRETA',
            [
                'produtoId'     => $produtoId,
                'quantidade'    => $quantidade,
                'UserID'        => $usuarioId,
                'ValorFIFOEst'  => $val,
                'observacao'    => $observacao,
                'ValorTotalEst' => $vlrtotal
            ]
        );


        return true;  
    } 
    
    //usado apenas para ajuste pós inventário
    public function registrarAjusteEntrada(int $produtoId, float $quantidade, int $usuarioId, string $observacao = 'Ajuste de Inventário (+)', ?int $inventarioId = null): bool {  
        $tipoId = 3; // ID para Ajuste de Inventário (+)  
        $qtdEst = new Estoque();
        $val = $qtdEst->getValorUltimo($produtoId);
        $vlrtotal = $qtdEst->getSomaValores($produtoId, $quantidade, "DESC");

        $movId = $this->criar($tipoId, $usuarioId, $observacao, null, $inventarioId);  
        if (!$movId) return false;  
        $item = new MovimentacaoItem();  
        if (!$item->adicionarItens($movId, [$produtoId => $quantidade], [$produtoId => $val])) {  
            return false;  
        }  
        $this->atualizarValorTotal($movId, $vlrtotal);  
        return true;  
    }  

        //usado apenas para ajuste pós inventário
    public function registrarAjusteSaida(int $produtoId, float $quantidade, int $usuarioId, string $observacao = 'Ajuste de Inventário (-)', ?int $inventarioId = null): bool {  
        $tipoId = 7; // ID para Ajuste de Inventário (-)  
        $qtdEst = new Estoque();
        $val = $qtdEst->getValorUltimo($produtoId);
        $vlrtotal = $qtdEst->getSomaValores($produtoId, $quantidade, "DESC");

        $movId = $this->criar($tipoId, $usuarioId, $observacao, null, $inventarioId);  
        if (!$movId) return false;  
        $item = new MovimentacaoItem();  
        if (!$item->adicionarItens($movId, [$produtoId => $quantidade], [$produtoId => $val])) {  
            return false;  
        }  
        $this->atualizarValorTotal($movId, $vlrtotal);  
        return true;  
    }

    /**
     * Cria um ajuste compensatório (entrada ou saída) vinculado à movimentação original.
     *
     * @param int   $originalId   ID da movimentação que está sendo corrigida
     * @param array $itens        ['produto_id' => quantidade]  (quantidade pode ser negativa)
     * @param int   $usuarioId    ID do usuário que faz a correção
     * @return bool
     */
    public function criarAjusteCompensatorio(int $originalId, array $itens, int $usuarioId): bool
    {
        $db = Database::getInstance();

        // 1 – buscar tipo de ajuste (+ ou -)
        $tipoAjuste = current($itens) > 0 ? 7 : 3; // 3 = Ajuste (+), 7 = Ajuste (-)
        $movId = $this->criar($tipoAjuste, $usuarioId, '');

        if (!$movId) return false;

        // 2 – inserir itens
        
        $itemModel = new MovimentacaoItem();
        $sql = "SELECT * FROM movimentacao_itens WHERE movimentacao_id = :mov_id AND produto_id = :prod_id";
       
          // 2.1 pegar os valores unitários
        
        $vlrtotal = 0;


        

        
        // Percorre item por item (Chave é o ID, Valor é a Quantidade)
        foreach ($itens as $produtoId => $quantidade) {
            // Chama a função para UM ID e UMA Quantidade específica
            $params = [
                ':mov_id'  => $originalId, // Substitua pela sua variável de ID da movimentação
                ':prod_id' => $produtoId       // Substitua pela sua variável de ID do produto
            ];

            $originalmovItem = $itemModel->rawQueryOne($sql, $params);

            $valorCalculado = $originalmovItem['valor_unitario'];

            $vlrtotal += $valorCalculado;
            // Atribui ao array final
            $valoresUnitarios[$produtoId] = $valorCalculado;
        }
           

        if (!$itemModel->adicionarItens($movId, array_map('abs', $itens), $valoresUnitarios)) {
            return false;
        }

        // 3 – vincular ao original
        $stmt = $db->prepare(
            "UPDATE movimentacoes SET movimentacao_original_id = :ajuste_id, corrigida = 1 WHERE id = :original_id"
        );
        $stmt->execute([
            ':ajuste_id'    => $movId,
            ':original_id'  => $originalId
        ]);

        $this->atualizarValorTotal($movId, $vlrtotal);
        // 4 – atualizar estoque (já feito pelo trigger do item)
        return true;
    }

    public function getById($id) {
        return $this->find($id);
    }
}