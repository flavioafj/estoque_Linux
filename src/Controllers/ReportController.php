<?php
/**
 * Controlador de Relatórios
 * src/Controllers/ReportController.php
 */

namespace Controllers;

use Models\Report;
use Middleware\Auth;
use Models\Category;
use Models\Fornecedor;
use Models\TipoMovimentacao; 
use Helpers\Session;


require_once __DIR__ . '/../Models/TipoMovimentacao.php';
//require_once __DIR__ . '/../Helpers/Session.php'; // Conforme Parte 1
require_once __DIR__ . '/../Middleware/Auth.php'; // Conforme Parte 1

class ReportController extends BaseController
{
    private $reportModel;
    private $categoryModel;
    private $fornecedorModel;
    private $tipoMovimentacaoModel;

    public function __construct()
    {
        $this->reportModel = new Report();
        $this->categoryModel = new Category();
        $this->fornecedorModel = new Fornecedor();
        $this->tipoMovimentacaoModel = new TipoMovimentacao();
        // Verificar permissão de administrador
        if (!Session::isAdmin()) {
            header('Location: /estoque-sorveteria/public/login.php');
            exit;
        }
    }

    /**
     * Exibe a página de relatórios
     */
    public function index()
    {
        $categories = $this->categoryModel->getAll();
        $fornecedores = $this->fornecedorModel->getAll();
        $tiposMovimentacao = $this->tipoMovimentacaoModel->findAll();

        $this->render('reports', [
            'title' => 'Relatórios de Estoque',
            'categories' => $categories,
            'fornecedores' => $fornecedores,
            'tiposMovimentacao' => $tiposMovimentacao
        ]);
    }

    /**
     * Processa requisição de relatório
     */
    public function generate()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: /estoque-sorveteria/public/reports.php');
            exit;
        }

        $filters = [
            'produto_id' => !empty($_POST['produto_id']) ? (int)$_POST['produto_id'] : null,
            'categoria_id' => !empty($_POST['categoria_id']) ? (int)$_POST['categoria_id'] : null,
            'fornecedor_id' => !empty($_POST['fornecedor_id']) ? (int)$_POST['fornecedor_id'] : null,
            'tipo_movimentacao' => !empty($_POST['tipo_movimentacao']) ? $_POST['tipo_movimentacao'] : null,
            'data_inicio' => !empty($_POST['data_inicio']) ? $_POST['data_inicio'] : null,
            'data_fim' => !empty($_POST['data_fim']) ? $_POST['data_fim'] : null
        ];

        $format = !empty($_POST['format']) && $_POST['format'] === 'csv' ? 'csv' : 'json';
        $results = $this->reportModel->getCustomReport($filters, $format);

        if ($format === 'csv') {
            echo $results;
            exit;
        }

        header('Content-Type: application/json');
        echo json_encode(['data' => $results]);
        exit;
    }
}