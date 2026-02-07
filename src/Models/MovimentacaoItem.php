<?php

namespace Models;

use Models\BaseModel;
use Models\Product;
use Models\Estoque;
use Helpers\SyncQueueHelper;

//require_once SRC_PATH . '/Models/Product.php'; 

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
    public function adicionarItens(int $movimentacaoId, array $itens, array $valoresUnitarios = null, int $tipo = 0): bool
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
            $transacaoIniciadaAqui = false;
            if (!$this->db->inTransaction()) {
                $this->db->beginTransaction();
                $transacaoIniciadaAqui = true;
            }
                
           
         

            // NOVO: Atualizar tabela estoques e estoque_atual
            $estoqueModel = new Estoque();

            $productModel = new Product();

            $dados = [];

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

                // Insere o item na tabela de movimentação_itens
              
                $resultadoInsercao = $stmt->execute([$movimentacaoId, $produtoId, $quantidade, $valoresUnitariosf, $valorTotalItem]);

                               
                if (!$resultadoInsercao) {
                    $errorInfo = $resultadoInsercao;
                    error_log("Erro ao inserir item - Produto: $produtoId, Erro: " . $errorInfo);
                    throw new \Exception("Erro ao inserir item da movimentação");
                }

                $itemId = (int) $this->db->lastInsertId();

                // Registra INSERT de movimentacao_itens para sincronização
                /* SyncQueueHelper::queueInsert(
                    'movimentacao_itens',
                    $itemId,
                    [
                        'movimentacao_id' => $movimentacaoId,
                        'produto_id'      => $produtoId,
                        'quantidade'      => $quantidade,
                        'valor_unitario'  => $valoresUnitariosf,
                        'valor_total'     => $valorTotalItem,
                        'criado_em'       => date('Y-m-d H:i:s')
                    ]
                ); */

                //Atualiza o estoque atual
                $valor = $valoresUnitarios[$produtoId] ?? 0;
                
                if($tipoMovimentacao=='ENTRADA'){
                    $acao = 'INSERT';
                    for ($i = 0; $i < (int)$quantidade; $i++) {
                        $estoqueModel->inserirUnidade($produtoId, $valor);

                    }

                }elseif ($tipoMovimentacao=='SAIDA') {
                    $acao = 'DELETE';
                    if ($tipo) {
                        $estoqueModel->removerUnidadesLifo($produtoId, (int)$quantidade);
                    }else{
                        $estoqueModel->removerUnidades($produtoId, (int)$quantidade);
                    }
                                    
                }
                    
                
                
  
                // Atualiza o estoque do produto usando o tipo correto
                $resultadoEstoque = $productModel->atualizarEstoque($produtoId, $quantidade, $tipoMovimentacao);
                                
                
                if (!$resultadoEstoque) {
                    error_log("Erro ao atualizar estoque - Produto: $produtoId, Quantidade: $quantidade, Tipo: $tipoMovimentacao");
                    throw new \Exception("Erro ao atualizar estoque do produto $produtoId");
                }

                $produto = [
                    'prodId'    => $produtoId,
                    'qtd'       => $quantidade,
                    'valUnit'   => $valoresUnitariosf
                ];

                $dados[] = $produto;
            }

          

            if ($transacaoIniciadaAqui) {
                $this->db->commit();
            }
            
            
            error_log("Movimentação processada com sucesso: $movimentacaoId");
            $estoqueModel->logAudit($acao, $movimentacaoId, null, $dados);

            return true;

        } catch (\Exception $e) {
            $this->db->rollBack();
            error_log("Erro em adicionarItens: " . $e->getMessage());
            return false;
        }
    }
}