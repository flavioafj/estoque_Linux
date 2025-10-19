<?php

namespace Models;

use Models\BaseModel;

require_once SRC_PATH . '/Models/Product.php'; 

class MovimentacaoItem extends BaseModel
{
    protected $table = 'movimentacao_itens';

    /**
     * Adiciona itens a uma movimentação.
     *
     * @param int $movimentacaoId
     * @param array $itens
     * @return bool
     */
    public function adicionarItens(int $movimentacaoId, array $itens, array $valoresUnitarios = null): bool
    {
        try {
            // Validação básica
            if (empty($itens)) {
                error_log("Nenhum item fornecido para movimentação $movimentacaoId");
                return false;
            }

            // Busca o tipo ('ENTRADA' ou 'SAIDA') da movimentação
            $sqlTipo = "SELECT tm.tipo FROM movimentacoes m JOIN tipos_movimentacao tm ON m.tipo_movimentacao_id = tm.id WHERE m.id = ?";
            $stmtTipo = $this->db->prepare($sqlTipo);
            
            if ($stmtTipo === false) {
                error_log("Erro ao preparar consulta de tipo de movimentação");
                return false;
            }

            $stmtTipo->execute([$movimentacaoId]);
            $movimentacao = $stmtTipo->fetch(\PDO::FETCH_ASSOC);

            if (!$movimentacao) {
                error_log("Movimentação não encontrada: $movimentacaoId");
                return false;
            }

            $tipoMovimentacao = $movimentacao['tipo'];

            
            // Inicia a transação
            
            $this->db->beginTransaction();
         

            

            $productModel = new \Product();

            foreach ($itens as $produtoId => $quantidade) {
                // Validações
                if (!is_numeric($produtoId) || !is_numeric($quantidade) || $quantidade <= 0) {
                    error_log("Dados inválidos - Produto ID: $produtoId, Quantidade: $quantidade");
                    continue;
                }

                $quantidade = floatval($quantidade);
                $produtoId = intval($produtoId);
                $valoresUnitariosf = floatval($valoresUnitarios[$produtoId] ?? 0);
                $valorTotalItem = $quantidade * $valoresUnitariosf;

                $sql = "INSERT INTO {$this->table} (movimentacao_id, produto_id, quantidade, valor_unitario, valor_total) VALUES (?, ?, ?, ?, ?)";
                $stmt = $this->db->prepare($sql);

                if ($stmt === false) {
                    $this->db->rollBack();
                    error_log("Erro ao preparar SQL de inserção de itens");
                    return false;
                }

                // Insere o item na tabela de movimentação
              
                $resultadoInsercao = $stmt->execute([$movimentacaoId, $produtoId, $quantidade, $valoresUnitariosf, $valorTotalItem]);

                               
                if (!$resultadoInsercao) {
                    $errorInfo = $resultadoInsercao;
                    error_log("Erro ao inserir item - Produto: $produtoId, Erro: " . $errorInfo);
                    throw new \Exception("Erro ao inserir item da movimentação");
                }
  
                // Atualiza o estoque do produto usando o tipo correto
                $resultadoEstoque = $productModel->atualizarEstoque($produtoId, $quantidade, $tipoMovimentacao);
                
                
                if (!$resultadoEstoque) {
                    error_log("Erro ao atualizar estoque - Produto: $produtoId, Quantidade: $quantidade, Tipo: $tipoMovimentacao");
                    throw new \Exception("Erro ao atualizar estoque do produto $produtoId");
                }
            }

          

            $this->db->commit();
            
            
            error_log("Movimentação processada com sucesso: $movimentacaoId");
            return true;

        } catch (\Exception $e) {
            $this->db->rollBack();
            error_log("Erro em adicionarItens: " . $e->getMessage());
            return false;
        }
    }
}