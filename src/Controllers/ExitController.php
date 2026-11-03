<?php  
namespace Controllers;  

use Middleware\Auth;
use Helpers\Session;
use Models\Movimentacao;  
use Models\MovimentacaoItem;
use Models\Estoque;
use Helpers\SyncQueueHelper;
  
class ExitController {  
    // Métodos redundantes com Movimentacao; use diretamente para simplicidade  
    public function saidaDireta() {
        Auth::check();
        try {
            if (!Session::isOperator()) {
                echo json_encode(['success' => false, 'message' => 'Acesso negado']);
                return;
            }

            $produtoId = $_POST['produto_id'] ?? 0;
            $quantidade = abs(floatval($_POST['quantidade'] ?? 0));

            if ($quantidade <= 0) {
                echo json_encode(['success' => false, 'message' => 'Quantidade inválida']);
                return;
            }

            $mov = new Movimentacao();
            $tipoSaidaId = 5; // Venda
            $movId = $mov->criar($tipoSaidaId, Session::getUserId());

            $item = new MovimentacaoItem();
            $qtdEst = new Estoque();
            $val = $qtdEst->getValorFIFO($produtoId);
            $vlrtotal = $qtdEst->getSomaValores((int)$produtoId, $quantidade);

            if ($item->adicionarItens($movId, [$produtoId => $quantidade], [$produtoId => $val])) {
                $mov->atualizarValorTotal($movId, $vlrtotal);

                SyncQueueHelper::queueChange(
                        'movimentacoes',
                        $movId,
                        'SAIDA_DIRETA',
                        [
                            'produtoId'     => $produtoId,
                            'quantidade'    => $quantidade,
                            'UserID'        => Session::getUserId(),
                            'ValorFIFOEst'  => $val,
                            'ValorTotalEst' => $vlrtotal,
                            'observacao'    => ''
                        ]
                    );

                echo json_encode(['success' => true, 'redirect' => "/pos_saida.php?mov=$movId"]);
                return;
            }

            echo json_encode(['success' => false, 'message' => 'Estoque insuficiente']);
        } finally {
            // O bloqueio é criado em produtos.php para esta requisição e deve
            // ser liberado antes de o cliente poder iniciar uma nova saída.
            Session::set('is_processing', false);
        }
    }
}