<?php
/**
 * Controlador de Sincronização - Servidor Web (Parte Gerencial)
 * src/Controllers/SyncController.php
 *
 * Compatível com o schema da tabela sincronizacao:
 * id, tabela, registro_id, acao, dados (JSON), sincronizado, tentativas, erro_mensagem, criado_em, sincronizado_em
 */

namespace Controllers;

use Models\Database;
use Models\Movimentacao;
use Models\MovimentacaoItem;
use Models\Estoque;
use Models\Product;

class SyncController extends BaseController
{
    private $db;

    // Tabelas que podem ser sincronizadas (segurança)
    private $allowedTables = [
        'produtos',
        'categorias',
        'fornecedores',
        'unidades_medida',
        'movimentacoes',
        'movimentacao_itens',
        'estoques'
        // Adicione aqui outras tabelas operacionais necessárias
        // NÃO inclua: sincronizacao, auditoria, sessoes, usuarios, perfis, configuracoes, alertas_estoque
    ];

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    //Funções auxiliares

    public function saidaDireta2($produtoId, $quantidade, $UserID, $ValorFIFOEst, $ValorTotalEst, $observacao = '') {
            
            
            $quantidade = abs(floatval($quantidade ?? 0));

            if ($quantidade <= 0) {
                echo 'Quantidade inválida';
                exit;
            }

            $mov = new Movimentacao();
            $tipoSaidaId = 5; // Venda
            $movId = $mov->criar($tipoSaidaId, $UserID, $observacao);

            $item = new MovimentacaoItem();
            $qtdEst = new Estoque();
            $val = $ValorFIFOEst;
            $vlrtotal = $ValorTotalEst;

            if ($item->adicionarItens($movId, [$produtoId => $quantidade], [$produtoId => $val])) {
                
                $mov->atualizarValorTotal($movId, $vlrtotal);
                // <<< ALTERAÇÃO >>> redireciona para página de pós-saída
                echo json_encode(['success'=>true,'message'=>'Saída direta feita com sucesso']);
            } else {
                echo json_encode(['success'=>false,'message'=>'Estoque insuficiente']);
                return false;
            }
            return true;

    }

    /* Seria interessante no futuro colocar o valor unitário do produto nessa função*/
    public function saidaDiretaADM($Itens, $ItensVal, $vlrtotal, $UserID, $observacao = '') {
            
            $mov = new Movimentacao();
            $tipoSaidaId = 5; // Venda
            $movId = $mov->criar($tipoSaidaId, $UserID, $observacao);

            foreach ($Itens as $produtoId => $quantidade){

                $quantidade = abs(floatval($quantidade ?? 0));

                if ($quantidade <= 0) {
                    echo json_encode(['success'=>false,'message'=>'Estoque insuficiente']);
                    return false;
                }

            }
            
                  

            $item = new MovimentacaoItem();
            

            if ($item->adicionarItens($movId, $Itens, $ItensVal)) {
                
                $mov->atualizarValorTotal($movId, $vlrtotal);
               
                echo json_encode(['success'=>true,'message'=>'Saída direta do ADM feita com sucesso']);
            } else {
                echo json_encode(['success'=>false,'message'=>'Estoque insuficiente']);
                return false;
            }
            return true;

    }

    /**
     * Recebe alterações do sistema local (PUSH: local → web)
     * POST /api/sync
     */
    public function receiveSync()
    {
        $headers = getallheaders();
        $auth = $headers['X-Api-Token'] ?? '';
        if (!preg_match('/Bearer\s+(.+)/', $auth, $matches) || $matches[1] !== SYNC_TOKEN) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Token inválido']);
            exit;
        }

        $input = file_get_contents('php://input');
        $payload = json_decode($input, true);

        if (json_last_error() !== JSON_ERROR_NONE ||
            !isset($payload['tabela'], $payload['registro_id'], $payload['acao'], $payload['dados']) ||
            !is_array($payload['dados'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Dados inválidos ou incompletos']);
            exit;
        }

        $tabela      = trim($payload['tabela']);
        $registro_id = (int)$payload['registro_id'];
        $acao        = strtoupper(trim($payload['acao']));
        $dados       = $payload['dados'];

        // Segurança: só tabelas permitidas
        if (!in_array($tabela, $this->allowedTables)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Tabela não autorizada para sincronização']);
            exit;
        }

        try {
            $this->db->beginTransaction();

            switch ($acao) {
                case 'INSERT':
                    $this->db->insert($tabela, $dados);
                    break;

                case 'UPDATE':
                    $this->db->update($tabela, $dados, "id = :id", [':id' => $registro_id]);
                    break;

                case 'DELETE':
                    $this->db->delete($tabela, "id = :id", [':id' => $registro_id]);
                    break;

                case 'SAIDA_DIRETA':
                    $this->saidaDireta2($dados['produtoId'], $dados['quantidade'], $dados['UserID'], $dados['ValorFIFOEst'], $dados['ValorTotalEst'], $dados['observacao']);
                    break;

                case 'SAIDA_DIRETA_ADM':
                    $this->saidaDiretaADM($dados['Itens'], $dados['ItensVal'], $dados['totalGeral'], $dados['UserID'], $dados['observacao']);
                    break;

                default:
                    throw new \Exception("Ação inválida: $acao");
            }

            // Registra a sincronização recebida (compatível com o schema)
            $this->db->insert('sincronizacao', [
                'tabela'          => $tabela,
                'registro_id'     => $registro_id,
                'acao'            => $acao,
                'dados'           => json_encode($dados, JSON_UNESCAPED_UNICODE),
                'sincronizado'    => true,
                'tentativas'      => 0,
                'erro_mensagem'   => null,
                'sincronizado_em' => date('Y-m-d H:i:s')
                // criado_em é preenchido automaticamente pelo DEFAULT CURRENT_TIMESTAMP
            ]);

            $this->db->commit();

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Sincronização recebida e aplicada'
            ]);
        } catch (\Exception $e) {
            $this->db->rollback();

            $error = "Erro receiveSync: " . $e->getMessage() .
                     " | tabela=$tabela | acao=$acao | registro_id=$registro_id";

            error_log($error, 3, __DIR__ . '/../../logs/sync_error.log');

            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Erro ao processar sincronização',
                'detail'  => $e->getMessage()  // Remova 'detail' em produção se desejar
            ]);
        }
    }

    /**
     * Envia alterações pendentes do web para o local (PULL: web → local)
     * GET /api/sync?last_sync=2026-01-01%2012:00:00
     */
    public function sendSync()
    {
        $headers = getallheaders();
        $auth = $headers['X-Api-Token'] ?? '';
        if (!preg_match('/Bearer\s+(.+)/', $auth, $matches) || $matches[1] !== SYNC_TOKEN) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Token inválido']);
            exit;
        }

        $last_sync = $_GET['last_sync'] ?? '2000-01-01 00:00:00';

        $query = "
            SELECT 
                id, tabela, registro_id, acao, dados, criado_em
            FROM sincronizacao
            WHERE sincronizado = FALSE
              AND criado_em > :last_sync
              AND tabela NOT IN ('sincronizacao', 'auditoria', 'sessoes', 'alertas_estoque', 'usuarios', 'perfis', 'configuracoes')
            ORDER BY criado_em ASC
            LIMIT 30
        ";

        $pendentes = $this->db->query($query, [':last_sync' => $last_sync]);

        $count = count($pendentes);

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data'    => $pendentes,
            'message' => $count === 0 ? 'Nenhuma pendência para sincronizar' : "$count pendências enviadas",
            'count'   => $count
        ], JSON_UNESCAPED_UNICODE);
    }
}