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

$db = Database::getInstance();
//Funções auxiliares

function saidaDireta2($produtoId, $quantidade, $UserID, $ValorFIFOEst, $ValorTotalEst) {
        
        
        $quantidade = abs(floatval($quantidade ?? 0));

        if ($quantidade <= 0) {
            echo 'Quantidade inválida';
            exit;
        }

        $mov = new Movimentacao();
        $tipoSaidaId = 5; // Venda
        $movId = $mov->criar($tipoSaidaId, $UserID);

        $item = new MovimentacaoItem();
        $qtdEst = new Estoque();
        $val = $ValorFIFOEst;
        $vlrtotal = $ValorTotalEst;

        if ($item->adicionarItens($movId, [$produtoId => $quantidade], [$produtoId => $val])) {
            
            $mov->atualizarValorTotal($movId, $vlrtotal);
            // <<< ALTERAÇÃO >>> redireciona para página de pós-saída
            echo 'Saída direta feita com sucesso';
        } else {
            echo 'Estoque insuficiente';
        }
        exit;
}


function saidaDiretaADM($Itens, $ItensVal, $vlrtotal, $UserID, $observacao = '') {
            
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
     * Recria o fluxo de registrar_entrada_nfe.php para o evento recebido via sync
     */
function registrarEntradaNfe(array $dados)
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

        // fornecedor novo ou existente
        $fornecedorModel = new \Models\Fornecedor();
        if ($dados['fornecedor'] === 'cadastrar_novo') {
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

        $mov->atualizarValorTotal($movId, floatval($dados['total']), $dados['numero']);

        // insere nota fiscal
        $this->db->insert('notas_fiscais', [
            'movimentacao_id' => $movId,
            'numero_nota'     => $dados['numero'],
            'chave_acesso'    => $dados['chave_acesso'],
            'data_emissao'    => date('Y-m-d', strtotime($dados['data'])),
            'fornecedor_id'   => $fornecedorId,
            'valor_total'     => floatval($dados['total']),
            'processado'      => true,
            'usuario_id'      => $dados['user_id']
        ]);
        
    }



// PUSH Local → Web
$pendentes = $db->query(
    "SELECT * FROM sincronizacao WHERE sincronizado = 0 AND tentativas < 5 ORDER BY criado_em ASC LIMIT 20",
    []  // Sem params
);

if (!empty($pendentes)) {  // Usa retorno array de query()
    foreach ($pendentes as $item) {
        $payload = [
            'tabela' => $item['tabela'],
            'registro_id' => $item['registro_id'],
            'acao' => $item['acao'],
            'dados' => json_decode($item['dados'], true)
        ];

        $ch = curl_init($_ENV['SYNC_URL']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Api-Token: Bearer ' . $_ENV['SYNC_TOKEN']],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30  // Para RPi redes lentas
        ]);

        // Montando o comando cURL para o Postman
        $headers = [
            'Content-Type: application/json',
            'X-Api-Token: Bearer ' . $_ENV['SYNC_TOKEN']
        ];

        $curlCommand = "curl --location --request POST '" . $_ENV['SYNC_URL'] . "' \\\n";
        foreach ($headers as $header) {
            $curlCommand .= "--header '" . $header . "' \\\n";
        }
        $curlCommand .= "--data-raw '" . json_encode($payload, JSON_PRETTY_PRINT) . "'";

        // Imprime na tela de forma legível
        echo "<pre>Copiável para o Postman:\n\n";
        echo $curlCommand;
        echo "</pre>";
        // Montando o comando cURL para o Postman FIM

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code === 200) {
            $db->update('sincronizacao', ['sincronizado' => 1, 'sincronizado_em' => date('Y-m-d H:i:s')], "id = :id", ['id' => $item['id']]);
        } else {
            $db->update('sincronizacao', ['tentativas' => $item['tentativas'] + 1, 'erro_mensagem' => $response], "id = :id", ['id' => $item['id']]);
            error_log("Push erro: HTTP $http_code - $response", 3, LOG_PATH . '/sync_error.log');
        }
    }
}

// PULL Web → Local
$last_sync = $db->query("SELECT MAX(sincronizado_em) FROM sincronizacao")[0]['MAX(sincronizado_em)'] ?? '2000-01-01';
$ch = curl_init($_ENV['SYNC_URL'] . '?last_sync=' . urlencode($last_sync));
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => ['X-Api-Token: Bearer ' . $_ENV['SYNC_TOKEN']],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30
]);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code === 200) {
    $data = json_decode($response, true);
    if (isset($data['success']) && !empty($data['data'])) {
        $db->beginTransaction();
        try {
            foreach ($data['data'] as $item) {
                $dados = json_decode($item['dados'], true);
                switch ($item['acao']) {
                    case 'INSERT': $db->insert($item['tabela'], $dados); break;
                    case 'UPDATE': $db->update($item['tabela'], $dados, "id = :id", ['id' => $item['registro_id']]); break;
                    case 'DELETE': $db->delete($item['tabela'], "id = :id", ['id' => $item['registro_id']]); break;
                    case 'SAIDA_DIRETA': saidaDireta2($dados['produtoId'], $dados['quantidade'], $dados['UserID'], $dados['ValorFIFOEst'], $dados['ValorTotalEst']);
                    case 'SAIDA_DIRETA_ADM':
                    $this->saidaDiretaADM($dados['Itens'], $dados['ItensVal'], $dados['totalGeral'], $dados['UserID'], $dados['observacao']);
                    break;

                    case 'ENTRADA_NFE':
                    $this->registrarEntradaNfe($dados);
                    break;
                }
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
            error_log("Pull erro: " . $e->getMessage(), 3, LOG_PATH . '/sync_error.log');
        }
    }
    echo $data['message'];
}