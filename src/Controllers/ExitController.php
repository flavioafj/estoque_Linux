<?php  
namespace Controllers;  

use Middleware\Auth;
use Helpers\Session;
use Models\Movimentacao;  
use Models\MovimentacaoItem;
use Models\Estoque;
  
class ExitController {  
    // Métodos redundantes com Movimentacao; use diretamente para simplicidade  
    public function saidaDireta() {
        Auth::check();
        if (!Session::isOperator()) { echo json_encode(['success'=>false,'message'=>'Acesso negado']); exit; }

        $produtoId = $_POST['produto_id'] ?? 0;
        $quantidade = abs(floatval($_POST['quantidade'] ?? 0));

        if ($quantidade <= 0) {
            echo json_encode(['success'=>false,'message'=>'Quantidade inválida']);
            exit;
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
            // <<< ALTERAÇÃO >>> redireciona para página de pós-saída
            echo json_encode(['success'=>true, 'redirect'=>"/pos_saida.php?mov=$movId"]);
        } else {
            echo json_encode(['success'=>false,'message'=>'Estoque insuficiente']);
        }
        exit;
    }
}