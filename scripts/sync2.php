<?php
/**
 * Script de Sincronização - scripts/sync.php
 * Para Raspberry Pi OS (local)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Models/Database.php';

use Models\Database;
use Models\Movimentacao;
use Models\MovimentacaoItem;
use Models\Product;
use Models\ItensNota;
use Models\TipoMovimentacao;

$db = Database::getInstance();
//Funções auxiliares
function atualizarTotalRecebido(int $movId, float $total, $numero = null): void {
    global $db;
    $db->getConnection()->prepare('UPDATE movimentacoes SET valor_total = ?, documento_numero = ? WHERE id = ?')->execute([$total, $numero, $movId]);
}

function saidaDireta2($produtoId, $quantidade, $UserID, $ValorFIFOEst, $ValorTotalEst, $observacao = '') {
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

        if (!$movId) throw new \RuntimeException('Falha ao criar saída.');
        if ($item->adicionarItens($movId, [$produtoId => $quantidade], [$produtoId => $val])) {
            atualizarTotalRecebido($movId, $vlrtotal);
            return $movId;
        } else {
            throw new \Exception('Estoque insuficiente');
        }
}


function saidaDiretaADM($Itens, $ItensVal, $vlrtotal, $UserID, $observacao = '') {
            $mov = new Movimentacao();
            $tipoSaidaId = 5; // Venda
            $movId = $mov->criar($tipoSaidaId, $UserID, $observacao);

            if (!$movId || empty($Itens)) throw new \RuntimeException('Falha ao criar saída ou saída sem itens.');
            foreach ($Itens as $produtoId => $quantidade){
                $quantidade = abs(floatval($quantidade ?? 0));
                if ($quantidade <= 0) {
                    throw new \Exception('Quantidade inválida nos itens');
                }
            }

            $item = new MovimentacaoItem();

            if ($item->adicionarItens($movId, $Itens, $ItensVal)) {
                atualizarTotalRecebido($movId, $vlrtotal);
                return $movId;
            } else {
                throw new \Exception('Estoque insuficiente');
            }
    }
    

    /**
     * Recria o fluxo de registrar_entrada_nfe.php para o evento recebido via sync
     */
function registrarEntradaNfe(array $dados)
    {
        global $db;
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
        $existingNota = $db->query(
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

        // fornecedor novo ou existente
        $fornecedorModel = new \Models\Fornecedor();
        if ($dados['fornecedor'] === 'cadastrar_novo') {
            $existentePorCnpj = $db->query(
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
            if (!$fornecedorExistente) {
                throw new \Exception('fornecedor inválido');
            }
            $fornecedorId = $fornecedorExistente['id'];
            $cnpjDb = preg_replace('/\D/', '', $fornecedorExistente['cnpj']);
            if ($cnpj !== '' && $cnpjDb !== $cnpj) {
                throw new \Exception('CNPJ do fornecedor não bate com a nota');
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

        atualizarTotalRecebido($movId, floatval($dados['total']), $dados['numero']);

        // insere nota fiscal
        if (!$db->insert('notas_fiscais', [
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

// O novo protocolo exige recibo local persistente e confirmação explícita do PULL.
$sync_url = defined('SYNC_URL')
    ? SYNC_URL
    : ($_SERVER['SYNC_URL'] ?? $_ENV['SYNC_URL'] ?? getenv('SYNC_URL'));

$sync_token = defined('SYNC_TOKEN')
    ? SYNC_TOKEN
    : ($_SERVER['SYNC_TOKEN'] ?? $_ENV['SYNC_TOKEN'] ?? getenv('SYNC_TOKEN'));

if (!is_string($sync_url) || trim($sync_url) === '' ||
    !is_string($sync_token) || trim($sync_token) === '') {
    fwrite(STDERR, "SYNC_URL ou SYNC_TOKEN ausente na configuração.\n");
    exit(1);
}

$client = new \Services\SyncHttpClient($sync_url, $sync_token);	
$pdo = $db->getConnection();
$pull = new \Services\SyncPullService($pdo);
$falhas = 0;
$enviados = $aplicados = $repetidos = $confirmados = 0;

function confirmarRecebimentos($client, $pull, string $origem): int
{
    $total = 0;
    while ($recibos = $pull->pendentesConfirmacao($origem)) {
        $resposta = $client->request('POST', ['ack' => 1], ['recebimentos' => $recibos]);
        $esperados = array_map('intval', array_column($recibos, 'id'));
        $retornados = $resposta['confirmed'] ?? null;
        if (!is_array($retornados)) throw new \RuntimeException('API não retornou os identificadores confirmados.');
        $retornados = array_map('intval', $retornados);
        sort($esperados); sort($retornados);
        if ($esperados !== $retornados) throw new \RuntimeException('API não confirmou todos os recibos enviados.');
        $pull->marcarConfirmados($origem, $recibos);
        $total += count($recibos);
    }
    return $total;
}

try {
    $pull->prepararRecibos();
    // Verifica o protocolo antes de enviar/aplicar qualquer movimentação.
    $lote = $client->request('GET', ['protocol' => 2]);
    if (($lote['protocol'] ?? null) !== 2 || !is_array($lote['data'] ?? null)) {
        throw new \RuntimeException('Atualize a API do servidor: protocolo de confirmação não disponível.');
    }
    $confirmados += confirmarRecebimentos($client, $pull, $sync_url);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Sincronização interrompida: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

// PUSH: HTTP 200, sozinho, não comprova que a API aplicou o evento.
$pendentes = $pdo->query('SELECT * FROM sincronizacao WHERE sincronizado = 0 AND tentativas < 5 ORDER BY id LIMIT 20')->fetchAll(\PDO::FETCH_ASSOC);
foreach ($pendentes as $item) {
    try {
        $client->request('POST', [], ['tabela' => $item['tabela'], 'registro_id' => $item['registro_id'],
            'acao' => $item['acao'], 'dados' => json_decode($item['dados'], true, 512, JSON_THROW_ON_ERROR)]);
        $pdo->prepare('UPDATE sincronizacao SET sincronizado = 1, sincronizado_em = CURRENT_TIMESTAMP, erro_mensagem = NULL WHERE id = ?')->execute([$item['id']]);
        $enviados++;
    } catch (\Throwable $e) {
        $falhas++;
        $pdo->prepare('UPDATE sincronizacao SET tentativas = tentativas + 1, erro_mensagem = ? WHERE id = ?')->execute([$e->getMessage(), $item['id']]);
        fwrite(STDERR, 'PUSH evento ' . $item['id'] . ': ' . $e->getMessage() . PHP_EOL);
    }
}

// Cada evento possui commit próprio. Apenas os commits concluídos recebem ACK.
foreach ($lote['data'] as $item) {
    try {
        $novo = $pull->aplicar($sync_url, $item, function (array $evento, array $dados) use ($db) {
            if ($evento['tabela'] === 'produtos') {
                (new \Services\ProductSyncService($db->getConnection()))->receber(
                    $evento['acao'], (int) $evento['registro_id'], $dados);
                return;
            }
            switch ($evento['acao']) {
                case 'INSERT':
                case 'UPDATE':
                    foreach (array_keys($dados) as $coluna) {
                        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', (string) $coluna)) throw new \RuntimeException('Coluna inválida no evento.');
                    }
                    if (!$dados) throw new \RuntimeException('Evento sem campos.');
                    $ok = $evento['acao'] === 'INSERT'
                        ? $db->insert($evento['tabela'], $dados)
                        : $db->update($evento['tabela'], $dados, 'id = :id', ['id' => $evento['registro_id']]);
                    if (!$ok) throw new \RuntimeException('Falha ao gravar alteração recebida.');
                    break;
                case 'DELETE':
                    if (!$db->delete($evento['tabela'], 'id = :id', ['id' => $evento['registro_id']])) throw new \RuntimeException('Falha ao excluir registro recebido.');
                    break;
                case 'SAIDA_DIRETA':
                    $destinoId = saidaDireta2($dados['produtoId'], $dados['quantidade'], $dados['UserID'], $dados['ValorFIFOEst'], $dados['ValorTotalEst'], $dados['observacao'] ?? '');
                    break;
                case 'SAIDA_DIRETA_ADM':
                    $destinoId = saidaDiretaADM($dados['Itens'], $dados['ItensVal'], $dados['totalGeral'], $dados['UserID'], $dados['observacao'] ?? '');
                    break;
                case 'CORRECAO_SAIDA':
                    (new \Services\CorrecaoSaidaService())->receber($dados);
                    break;
                case 'ENTRADA_MANUAL':
                    (new \Services\EntradaManualService())->receber($dados);
                    break;
                case 'ENTRADA_NFE':
                    registrarEntradaNfe($dados);
                    break;
                default:
                    throw new \RuntimeException('Ação de sincronização não suportada.');
            }
            if (isset($destinoId)) {
                $dados['_movimento_destino_id'] = $destinoId;
                $db->getConnection()->prepare('INSERT INTO sincronizacao (tabela, registro_id, acao, dados, sincronizado, tentativas, criado_em) VALUES (?, ?, ?, ?, 1, 0, NOW())')->execute(['movimentacoes', $evento['registro_id'], $evento['acao'], json_encode($dados, JSON_THROW_ON_ERROR)]);
            }
        });
        if ($novo) $aplicados++; else $repetidos++;
    } catch (\Throwable $e) {
        $falhas++;
        $id = is_array($item) ? ($item['id'] ?? '?') : '?';
        fwrite(STDERR, 'PULL evento ' . $id . ': ' . $e->getMessage() . PHP_EOL);
        error_log('PULL evento ' . $id . ': ' . $e->getMessage() . PHP_EOL, 3, LOG_PATH . '/sync_error.log');
    }
}
try {
    $confirmados += confirmarRecebimentos($client, $pull, $sync_url);
} catch (\Throwable $e) {
    $falhas++;
    fwrite(STDERR, 'Confirmação pendente (a próxima execução tentará novamente): ' . $e->getMessage() . PHP_EOL);
}
echo "PUSH: {$enviados} enviados. PULL: {$aplicados} aplicados, {$repetidos} já recebidos, {$confirmados} confirmados. Falhas: {$falhas}." . PHP_EOL;
if ($falhas) exit(1);
