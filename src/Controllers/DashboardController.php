<?php
/**
 * Controlador do Dashboard
 * src/Controllers/DashboardController.php
 */

namespace Controllers;

use Models\Report;
use Helpers\Session;

require_once __DIR__ . '/../Models/Report.php';
require_once __DIR__ . '/../Helpers/Session.php';

class DashboardController extends BaseController
{
    private $reportModel;

    public function __construct()
    {
        //parent::__construct();
        $this->reportModel = new Report();
        // Verificar permissão de administrador
        if (!Session::isLoggedIn() || !Session::isAdmin()) {
            http_response_code(403);
            echo json_encode(['error' => 'Acesso não autorizado']);
            exit;
        }
    }

    /**
     * Retorna resumo do dashboard (valor FIFO total)
     */
    public function getSummary()
    {
        try {
            $sql = "SELECT SUM(estoque_atual) as total_estoque FROM produtos WHERE ativo = TRUE";
            $total_estoque = $this->reportModel->rawQuery($sql)[0]['total_estoque'] ?? 0;
            $valor_fifo = 0;
            $produtos = $this->reportModel->rawQuery("SELECT id FROM produtos WHERE ativo = TRUE");
            foreach ($produtos as $produto) {
                $valor_fifo += $this->reportModel->calculateFIFO($produto['id']);
            }
            echo json_encode([
                'total_estoque' => $total_estoque,
                'valor_fifo' => number_format($valor_fifo, 2, '.', '')
            ]);
        } catch (\Exception $e) {
            error_log("DashboardController::getSummary: " . $e->getMessage(), 3, __DIR__ . '/../../logs/error.log');
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao carregar resumo']);
        }
    }

    /**
     * Retorna produtos com estoque baixo
     */
    public function getLowStock()
    {
        try {
            $sql = "SELECT id, codigo, nome, estoque_atual, estoque_minimo, categoria, fornecedor 
                    FROM vw_produtos_estoque_critico 
                    ORDER BY estoque_atual ASC 
                    LIMIT 10";
            $result = $this->reportModel->rawQuery($sql);
            echo json_encode($result);
        } catch (\Exception $e) {
            error_log("DashboardController::getLowStock: " . $e->getMessage(), 3, __DIR__ . '/../../logs/error.log');
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao carregar estoque baixo']);
        }
    }

    /**
     * Retorna movimentações recentes
     */
    public function getRecentMovements()
    {
        try {
            $sql = "SELECT id, tipo, categoria, documento_numero, data_movimentacao, valor_total, fornecedor, usuario 
                    FROM vw_movimentacoes_recentes 
                    ORDER BY data_movimentacao DESC 
                    LIMIT 10";
            $result = $this->reportModel->rawQuery($sql);
            echo json_encode($result);
        } catch (\Exception $e) {
            error_log("DashboardController::getRecentMovements: " . $e->getMessage(), 3, __DIR__ . '/../../logs/error.log');
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao carregar movimentações']);
        }
    }

    /**
     * Retorna dados para gráfico de giro de estoque
     */
    public function getStockTurnover()
    {
        try {
            $sql = "
                SELECT 
                    DATE_FORMAT(m.data_movimentacao, '%Y-%m') as periodo,
                    tm.tipo,
                    SUM(mi.quantidade) as total_quantidade
                FROM movimentacoes m
                JOIN movimentacao_itens mi ON m.id = mi.movimentacao_id
                JOIN tipos_movimentacao tm ON m.tipo_movimentacao_id = tm.id
                WHERE m.status = 'PROCESSADO'
                    AND m.data_movimentacao >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                GROUP BY periodo, tm.tipo
                ORDER BY periodo ASC";
            $result = $this->reportModel->rawQuery($sql);
            
            // Formatar para Chart.js
            $entradas = [];
            $saidas = [];
            $periodos = [];
            foreach ($result as $row) {
                if (!in_array($row['periodo'], $periodos)) {
                    $periodos[] = $row['periodo'];
                }
                if ($row['tipo'] == 'ENTRADA') {
                    $entradas[$row['periodo']] = (float)$row['total_quantidade'];
                } else {
                    $saidas[$row['periodo']] = (float)$row['total_quantidade'];
                }
            }
            echo json_encode([
                'labels' => $periodos,
                'entradas' => array_values(array_replace(array_fill_keys($periodos, 0), $entradas)),
                'saidas' => array_values(array_replace(array_fill_keys($periodos, 0), $saidas))
            ]);
        } catch (\Exception $e) {
            error_log("DashboardController::getStockTurnover: " . $e->getMessage(), 3, __DIR__ . '/../../logs/error.log');
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao carregar dados do gráfico']);
        }
    }

    /**
 * Retorna dados para giro de estoque de um produto específico
 */
    public function getProductTurnover($product_id) {
        try {
            
            // Define as datas padrão
            $default_start_date = date('Y-m-d', strtotime('-6 months'));
            $default_end_date = date('Y-m-d');

            // Recebe as datas da URL, se existirem
            $start_date_input = filter_input(INPUT_GET, 'start_date', FILTER_VALIDATE_REGEXP, array(
                'options' => array(
                    'regexp' => '/^\d{4}-\d{2}-\d{2}$/'
                )
            ));

            $end_date_input = filter_input(INPUT_GET, 'end_date', FILTER_VALIDATE_REGEXP, array(
                'options' => array(
                    'regexp' => '/^\d{4}-\d{2}-\d{2}$/'
                )
            ));

            // Atribui as datas, usando as padrão se a entrada for inválida
            $start_date = $start_date_input ?: $default_start_date;
            $end_date = $end_date_input ?: $default_end_date;

            // Dados para gráfico
            $sql_graph = "
                SELECT 
                    DATE_FORMAT(m.data_movimentacao, '%Y-%m') as periodo,
                    tm.tipo,
                    SUM(mi.quantidade) as total_quantidade
                FROM movimentacoes m
                JOIN movimentacao_itens mi ON m.id = mi.movimentacao_id
                JOIN tipos_movimentacao tm ON m.tipo_movimentacao_id = tm.id
                WHERE m.status = 'PROCESSADO'
                    AND mi.produto_id = :product_id
                    AND m.data_movimentacao BETWEEN :start_date AND :end_date
                GROUP BY periodo, tm.tipo
                ORDER BY periodo ASC";
            $params_graph = [':product_id' => $product_id, ':start_date' => $start_date, ':end_date' => $end_date];
            $result_graph = $this->reportModel->rawQuery($sql_graph, $params_graph);
            
            $entradas = [];
            $saidas = [];
            $periodos = [];
            foreach ($result_graph as $row) {
                if (!in_array($row['periodo'], $periodos)) {
                    $periodos[] = $row['periodo'];
                }
                if ($row['tipo'] == 'ENTRADA') {
                    $entradas[$row['periodo']] = (float)$row['total_quantidade'];
                } else {
                    $saidas[$row['periodo']] = (float)$row['total_quantidade'];
                }
            }

            // Lista de entradas
            $sql_entradas = "
                SELECT m.documento_numero, m.data_movimentacao, u.nome_completo as usuario, mi.quantidade
                FROM movimentacoes m
                JOIN movimentacao_itens mi ON m.id = mi.movimentacao_id
                JOIN tipos_movimentacao tm ON m.tipo_movimentacao_id = tm.id
                JOIN usuarios u ON m.usuario_id = u.id
                WHERE tm.tipo = 'ENTRADA' 
                    AND mi.produto_id = :product_id
                    AND m.data_movimentacao BETWEEN :start_date AND :end_date
                ORDER BY m.data_movimentacao DESC";
            $entradas_list = $this->reportModel->rawQuery($sql_entradas, $params_graph);

            // Lista de saídas
            $sql_saidas = "
                SELECT m.documento_numero, m.data_movimentacao, u.nome_completo as usuario, mi.quantidade
                FROM movimentacoes m
                JOIN movimentacao_itens mi ON m.id = mi.movimentacao_id
                JOIN tipos_movimentacao tm ON m.tipo_movimentacao_id = tm.id
                JOIN usuarios u ON m.usuario_id = u.id
                WHERE tm.tipo = 'SAIDA' 
                    AND mi.produto_id = :product_id
                    AND m.data_movimentacao BETWEEN :start_date AND :end_date
                ORDER BY m.data_movimentacao DESC";
            $saidas_list = $this->reportModel->rawQuery($sql_saidas, $params_graph);

            echo json_encode([
                'labels' => $periodos,
                'entradas' => array_values(array_replace(array_fill_keys($periodos, 0), $entradas)),
                'saidas' => array_values(array_replace(array_fill_keys($periodos, 0), $saidas)),
                'entradas_list' => $entradas_list,
                'saidas_list' => $saidas_list
            ]);
        } catch (\Exception $e) {
            error_log("DashboardController::getProductTurnover: " . $e->getMessage(), 3, __DIR__ . '/../../logs/error.log');
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao carregar dados do produto']);
        }
    }
}