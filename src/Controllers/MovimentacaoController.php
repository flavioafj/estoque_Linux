<?php

namespace App\Controllers;

use Controllers\BaseController;
use Models\Movimentacao;
use Models\MovimentacaoItem;
use Models\Estoque;
//use Models\Product;  
use Helpers\Session;
use Models\Alert;
use Helpers\SyncQueueHelper;


$productPath = __DIR__ . '/../Models/Product.php';


if (!file_exists($productPath)) {
    error_log("ProductController.php: Não encontrou Product.php em $productPath", 3, __DIR__ . '/../../logs/error.log');
    die("Erro: Não foi possível carregar Product.php.");
}

//require_once $productPath;


class MovimentacaoController extends BaseController
{
    /**
     * Processa o registro de uma entrada de estoque.
     */
    public function registrarEntrada(array $postData, array $fileData)
    {
        try {
            (new \Services\EntradaManualService())->registrar([
                'tipo_movimentacao_id' => $postData['tipo_movimentacao_id'] ?? null,
                'user_id' => Session::get('user_id'),
                'observacao' => $postData['observacao'] ?? '',
                'itens' => $postData['produtos'] ?? [],
                'valores' => $postData['valor_unitario'] ?? [],
            ]);
            Session::setFlash('success', 'Entrada de estoque registrada com sucesso!');
            header('Location: /admin/products.php');
        } catch (\Throwable $e) {
            error_log('Erro na entrada manual: ' . $e->getMessage());
            Session::setFlash('error', 'Erro ao registrar entrada: ' . $e->getMessage());
            header('Location: /admin/entradas.php');
        }
    }

    /**
     * Processa o registro de uma saída de estoque.
     */
    public function registrarSaida(array $postData)
    {
        $session = new Session();
        $usuarioId = $session->get('user_id');
        $itens = $postData['produtos'] ?? [];
        $tipoMovimentacaoId = $postData['tipo_movimentacao_id'] ?? null; // NOVO
        $observacao = $postData['observacao'] ?? '';

        if (!$tipoMovimentacaoId) { // NOVO
            $session->setFlash('error', 'O tipo de movimentação é obrigatório.');
            header('Location: /saidas.php');
            return;
        }


        if (empty(array_filter($itens))) {
            $session->setFlash('error', 'Nenhum produto foi selecionado para saída.');
            header('Location: /saidas.php');
            return;
        }else{
            $qtdEst = new Estoque();
            $itensLimpo = [];
            $valoresUnitarios = [];

        
             foreach($itens as $produtoId => $quantidade){
                if($quantidade>0){
                    $val = $qtdEst->getValorFIFO($produtoId);
                    // Acumula os valores no array
                    $valoresUnitarios[$produtoId] = $val;
                    $itensLimpo[$produtoId] = $quantidade;
                    $totais[$produtoId] = $quantidade * $val;
                    
                }
                
             }
        }

        $movimentacaoModel = new Movimentacao();
        $movimentacaoId = $movimentacaoModel->criar($tipoMovimentacaoId, $usuarioId, $observacao);
        
        // Log para movimentacoes
        $movimentacaoModel->logAudit('DELETE', $movimentacaoId, null, $postData);

        if ($movimentacaoId) {
            $itemModel = new MovimentacaoItem();
            if ($itemModel->adicionarItens($movimentacaoId, $itensLimpo, $valoresUnitarios)) {

               
                // Verifica estoque mínimo para cada item
                $alertModel = new Alert();

                foreach ($itens as $produtoId => $item) {
                    
                    
                    $alertModel->checkLowStock($produtoId);   
                }


                $totalGeral = array_sum($totais);

                $movimentacaoModel->atualizarValorTotal($movimentacaoId, $totalGeral);

               SyncQueueHelper::queueChange(
                    'movimentacoes',
                    $movimentacaoId,
                    'SAIDA_DIRETA_ADM',
                    [
                        'Itens'         => $itensLimpo,
                        'ItensVal'      => $valoresUnitarios,
                        'totalGeral'    => $totalGeral,
                        'UserID'        => $usuarioId,
                        'observacao'    => $observacao
                        
                    ]
                );


                $session->setFlash('success', 'Saída de estoque registrada com sucesso!');

            } else {
                $session->setFlash('error', 'Falha ao registrar os itens da saída.');
            }
        } else {
            $session->setFlash('error', 'Falha ao criar o registro de movimentação.');
        }

        header('Location: /dashboard.php');
    }
}