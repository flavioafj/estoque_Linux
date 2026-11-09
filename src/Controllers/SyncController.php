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
use Models\ItensNota;
use Models\TipoMovimentacao;

class SyncController extends BaseController
{
    private $db;

    // Tabelas que podem ser sincronizadas (segurança)
    private $allowedTables = [
        'usuarios',
        'produtos',
        'categorias',
        'fornecedores',
        'unidades_medida',
        'movimentacoes',
        'movimentacao_itens',
        'estoques',
        'notas_fiscais',
        'itens_nota'
        // Adicione aqui outras tabelas operacionais necessárias
        // NÃO inclua: sincronizacao, auditoria, sessoes, perfis, configuracoes, alertas_estoque
    ];

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    //Funções auxiliares

    public function saidaDireta2($produtoId, $quantidade, $UserID, $ValorFIFOEst, $ValorTotalEst, $observacao = '') {
            $quantidade = abs(floatval($quantidade ?? 0));

            if ($quantidade <= 0) {
                throw new \Exception('Quantidade inválida');
            }

            $mov = new Movimentacao();
            $tipoSaidaId = 5; // Venda
            $movId = $mov->criar($tipoSaidaId, $UserID, $observacao);

            $item = new MovimentacaoItem();
            $val = $ValorFIFOEst;
            $vlrtotal = $ValorTotalEst;

            if ($item->adicionarItens($movId, [$produtoId => $quantidade], [$produtoId => $val])) {
                $mov->atualizarValorTotal($movId, $vlrtotal);
                return $movId;
            } else {
                throw new \Exception('Estoque insuficiente');
            }
    }

    /* Seria interessante no futuro colocar o valor unitário do produto nessa função*/
    public function saidaDiretaADM($Itens, $ItensVal, $vlrtotal, $UserID, $observacao = '') {
            $mov = new Movimentacao();
            $tipoSaidaId = 5; // Venda
            $movId = $mov->criar($tipoSaidaId, $UserID, $observacao);

            foreach ($Itens as $produtoId => $quantidade){
                $quantidade = abs(floatval($quantidade ?? 0));
                if ($quantidade <= 0) {
                    throw new \Exception('Quantidade inválida nos itens');
                }
            }

            $item = new MovimentacaoItem();

            if ($item->adicionarItens($movId, $Itens, $ItensVal)) {
                $mov->atualizarValorTotal($movId, $vlrtotal);
                return $movId;
            } else {
                throw new \Exception('Estoque insuficiente');
            }
    }

    /**
     * Recria o fluxo de registrar_entrada_nfe.php para o evento recebido via sync
     */
    private function registrarEntradaNfe(array $dados)
    {
        // campos obrigatórios
        if (
            empty($dados['fornecedor']) ||
            empty($dados['itens']) || !is_array($dados['itens']) ||
            empty($dados['valores']) || !is_array($dados['valores']) ||
            !isset($dados['total'], $dados['numero'],
                    $dados['chave_acesso'], $dados['data'],
                    $dados['user_id'])
        ) {
            throw new \Exception('dados de entrada nfe incompletos');
        }

        // Verificar duplicata para evitar duplicações no PULL
        $existingNota = $this->db->query(
            "SELECT id FROM notas_fiscais WHERE numero_nota = :numero OR chave_acesso = :chave",
            [':numero' => $dados['numero'], ':chave' => $dados['chave_acesso']]
        );
        if (!empty($existingNota)) {
            // Já existe, pular para evitar duplicação
            return;
        }

        // sanitizações e validações similares às do script web
        $cnpj = preg_replace('/\D/', '', $dados['cnpj'] ?? '');
        if ($cnpj !== '' && strlen($cnpj) !== 14) {
            throw new \Exception('CNPJ inválido na NF‑e');
        }
        if (!strtotime($dados['data'])) {
            throw new \Exception('Data de emissão inválida na NF‑e');
        }
        if (floatval($dados['total']) <= 0) {
            throw new \Exception('Valor total da NF‑e deve ser positivo');
        }

        // constroi vetores de itens e valores, descartando quantidades nulas
        $itens = [];
        $valores = [];
        foreach ($dados['itens'] as $pid => $qtd) {
            $qtd = abs(floatval($qtd));
            if ($qtd <= 0) {
                continue;
            }
            $itens[(int)$pid] = $qtd;
            $valores[(int)$pid] = floatval($dados['valores'][$pid] ?? 0);
        }
        if (empty($itens)) {
            throw new \Exception('nenhum produto válido na NF‑e');
        }

        // atualiza mapeamento nome_xml → produto, se enviado
        if (!empty($dados['nomes_xml']) && is_array($dados['nomes_xml'])) {
            $itensNotaModel = new ItensNota();
            foreach ($dados['nomes_xml'] as $pid => $nome) {
                if ($nome === '' || !isset($itens[(int)$pid])) {
                    continue;
                }
                $exist = $itensNotaModel->getByNomeXml($nome);
                if (!$exist) {
                    $itensNotaModel->insert($nome, (int)$pid);
                }
            }
        }

        if (!empty($dados['fornecedor_evento_id'])) {
            $fornecedorId = \Services\NfeSupplierService::receivedSupplier($this->db->getConnection(), $dados);
        } else {
        // fornecedor novo ou existente
        $fornecedorModel = new \Models\Fornecedor();
        if ($dados['fornecedor'] === 'cadastrar_novo') {
            $existentePorCnpj = $this->db->query(
                "SELECT id FROM fornecedores WHERE REPLACE(REPLACE(REPLACE(cnpj, '.', ''), '/', ''), '-', '') = :cnpj LIMIT 1",
                [':cnpj' => $cnpj]
            );
            if (!empty($existentePorCnpj)) {
                $fornecedorId = $existentePorCnpj[0]['id'];
            } else {
                $fornecedorId = $fornecedorModel->create([
                    'cnpj'         => $cnpj,
                    'razao_social' => $dados['razao_social'] ?? '',
                    'tipo'         => 'PJ',
                    'ativo'        => 1,
                    'criado_por'   => $dados['user_id']
                ]);
                if (!$fornecedorId) {
                    throw new \Exception('erro ao cadastrar fornecedor');
                }
            }
        } else {
            $fornecedorExistente = $fornecedorModel->getById((int)$dados['fornecedor']);
            if ((!$fornecedorExistente || ($cnpj !== '' && preg_replace('/\D/', '', $fornecedorExistente['cnpj']) !== $cnpj)) && $cnpj !== '') {
                $porCnpj = $this->db->query(
                    "SELECT * FROM fornecedores WHERE REPLACE(REPLACE(REPLACE(cnpj, '.', ''), '/', ''), '-', '') = :cnpj LIMIT 1",
                    [':cnpj' => $cnpj]
                );
                if (!empty($porCnpj)) {
                    $fornecedorExistente = $porCnpj[0];
                }
            }
            if (!$fornecedorExistente) {
                throw new \Exception('fornecedor inválido');
            }
            $fornecedorId = $fornecedorExistente['id'];
            $cnpjDb = preg_replace('/\D/', '', $fornecedorExistente['cnpj']);
            if ($cnpj !== '' && $cnpjDb !== $cnpj) {
                throw new \Exception('CNPJ do fornecedor não bate com a nota');
            }
        }

        }

        // cria movimentação de entrada usando primeiro tipo disponível
        $tipoModel = new TipoMovimentacao();
        $tiposEntrada = $tipoModel->findByTipo('ENTRADA');
        if (empty($tiposEntrada)) {
            throw new \Exception('nenhum tipo de movimentação de entrada configurado');
        }
        $tipoMovId = $tiposEntrada[0]['id'];

        $mov = new Movimentacao();
        $movId = $mov->criar(
            $tipoMovId,
            $dados['user_id'],
            'Entrada via NF‑e ' . $dados['numero'],
            $fornecedorId
        );
        if (!$movId) {
            throw new \Exception('falha ao criar movimentação');
        }

        $itemModel = new MovimentacaoItem();
        if (!$itemModel->adicionarItens($movId, $itens, $valores)) {
            throw new \Exception('erro ao adicionar itens');
        }

        $this->db->getConnection()->prepare('UPDATE movimentacoes SET valor_total = ?, documento_numero = ? WHERE id = ?')->execute([floatval($dados['total']), $dados['numero'], $movId]);

        // insere nota fiscal
        if (!$this->db->insert('notas_fiscais', [
            'movimentacao_id' => $movId,
            'numero_nota'     => $dados['numero'],
            'chave_acesso'    => $dados['chave_acesso'],
            'data_emissao'    => date('Y-m-d', strtotime($dados['data'])),
            'fornecedor_id'   => $fornecedorId,
            'valor_total'     => floatval($dados['total']),
            'processado'      => true,
            'usuario_id'      => $dados['user_id']
        ])) throw new \RuntimeException('Falha ao registrar nota fiscal recebida.');
    }

    /**
     * Recebe alterações do sistema local (PUSH: local → web)
     * POST /api/sync
     */
    public function receiveSync()
    {
        if (!$this->authenticateSync()) return;

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
            echo json_encode(['success' => false, 'message' => 'Tabela não autorizada para sincronização' . json_encode($this->allowedTables)]);
            exit;
        }

        try {
            $this->db->beginTransaction();

            if ($tabela === 'fornecedores' && isset($dados['evento_id'])) {
                (new \Services\SupplierSyncService($this->db->getConnection()))->receber($acao, $registro_id, $dados);
            } elseif ($tabela === 'usuarios') {
                (new \Services\UserSyncService($this->db->getConnection()))->receber($acao, $registro_id, $dados);
            } elseif ($tabela === 'produtos') {
                (new \Services\ProductSyncService($this->db->getConnection()))->receber($acao, $registro_id, $dados);
            } else switch ($acao) {
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
                    $destinoId = $this->saidaDireta2($dados['produtoId'], $dados['quantidade'], $dados['UserID'], $dados['ValorFIFOEst'], $dados['ValorTotalEst'], $dados['observacao'] ?? '');
                    break;

                case 'SAIDA_DIRETA_ADM':
                    $destinoId = $this->saidaDiretaADM($dados['Itens'], $dados['ItensVal'], $dados['totalGeral'], $dados['UserID'], $dados['observacao'] ?? '');
                    break;

                case 'AJUSTE_INVENTARIO':
                    if ($tabela !== 'movimentacoes') throw new \RuntimeException('Tabela inválida para inventário.');
                    (new \Services\InventoryService())->receber($dados);
                    break;

                case 'CORRECAO_SAIDA':
                    if ($tabela !== 'movimentacoes') throw new \RuntimeException('Tabela inválida para correção.');
                    (new \Services\CorrecaoSaidaService())->receber($dados);
                    break;

                case 'ENTRADA_MANUAL':
                    if ($tabela !== 'movimentacoes') {
                        throw new \RuntimeException('Tabela inválida para entrada manual.');
                    }
                    (new \Services\EntradaManualService())->receber($dados);
                    break;

                case 'ENTRADA_NFE':
                    $this->registrarEntradaNfe($dados);
                    break;

                default:
                    throw new \Exception("Ação inválida: $acao");
            }

            // Registra a sincronização recebida (compatível com o schema)
            if (!($tabela === 'fornecedores' && isset($dados['evento_id'])) && $tabela !== 'usuarios' && !in_array($acao, ['ENTRADA_MANUAL', 'CORRECAO_SAIDA', 'AJUSTE_INVENTARIO'], true)) {
                if (isset($destinoId)) $dados['_movimento_destino_id'] = $destinoId;
                $receiptId = $this->db->insert('sincronizacao', [
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
                if (!$receiptId) throw new \RuntimeException('Falha ao registrar recebimento.');
            }

            if ($this->db->inTransaction()) {
                $this->db->commit();
            }

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Sincronização recebida e aplicada'
            ]);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }

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

    private function authenticateSync(): bool
    {
        $headers = array_change_key_case(getallheaders(), CASE_LOWER);
        $auth = $headers['x-api-token'] ?? '';
        $token = defined('SYNC_TOKEN') ? SYNC_TOKEN : ($_ENV['SYNC_TOKEN'] ?? '');
        if (!$token || !preg_match('/^Bearer\s+(.+)$/i', $auth, $matches) || !hash_equals($token, $matches[1])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Token inválido']);
            return false;
        }
        return true;
    }

    public function acknowledgeSync()
    {
        if (!$this->authenticateSync()) return;
        try {
            $payload = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload['recebimentos'] ?? null)) throw new \RuntimeException('Recibos ausentes.');
            $service = new \Services\SyncPullService($this->db->getConnection());
            $ids = $service->confirmarNoServidor($payload['recebimentos']);
            echo json_encode(['success' => true, 'confirmed' => $ids]);
        } catch (\Throwable $e) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Não foi possível confirmar os recibos.']);
        }
    }

    /** Protocolo 2: pendências sem cursor de data; confirmação somente por ACK. */
    public function sendSync()
    {
        if (!$this->authenticateSync()) return;
        if (($_GET['protocol'] ?? '') !== '2') {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Atualize sync2.php para o protocolo 2.']);
            return;
        }
        try {
            $service = new \Services\SyncPullService($this->db->getConnection());
            $pendentes = $service->listarPendentes();
            echo json_encode(['success' => true, 'protocol' => 2, 'data' => $pendentes,
                'message' => count($pendentes) . ' pendências disponíveis para recebimento',
                'count' => count($pendentes)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Falha ao consultar pendências.']);
        }
    }
}
