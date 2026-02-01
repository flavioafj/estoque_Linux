<?php
/**
 * Modelo para Relatórios
 * src/Models/Report.php
 */

namespace Models;

use Models\BaseModel;

class Report extends BaseModel
{
    protected $table = 'produtos';

    /**
     * Gera relatório personalizado com cálculo FIFO
     * @param array $filters Filtros: produto_id, categoria_id, fornecedor_id, tipo_movimentacao, data_inicio, data_fim
     * @param string $format Formato de saída: 'json' ou 'csv'
     * @return array|string Resultados do relatório ou CSV
     */
    public function getCustomReport($filters, $format = 'json')
    {
        // Query SQL simplificada com placeholders únicos
        $sql = "
            SELECT 
                p.id, 
                p.nome, 
                p.estoque_atual, 
                c.nome AS categoria, 
                f.razao_social AS fornecedor
            FROM produtos p
            LEFT JOIN categorias c ON p.categoria_id = c.id
            LEFT JOIN fornecedores f ON p.fornecedor_principal_id = f.id
            WHERE p.ativo = TRUE
                AND (:produto_id_null IS NULL OR p.id = :produto_id)
                AND (:categoria_id_null IS NULL OR p.categoria_id = :categoria_id)
                AND (:fornecedor_id_null IS NULL OR p.fornecedor_principal_id = :fornecedor_id)
            ORDER BY p.nome ASC;
        ";

        $params = [
            ':produto_id_null' => $filters['produto_id'] ?? null,
            ':produto_id' => $filters['produto_id'] ?? null,
            ':categoria_id_null' => $filters['categoria_id'] ?? null,
            ':categoria_id' => $filters['categoria_id'] ?? null,
            ':fornecedor_id_null' => $filters['fornecedor_id'] ?? null,
            ':fornecedor_id' => $filters['fornecedor_id'] ?? null
        ];

        $results = $this->rawQuery($sql, $params);

        // Processar cálculo FIFO em PHP para cada produto
        foreach ($results as &$row) {
            $row['valor_estoque'] = $this->calculateFIFO($row['id'], $filters['data_fim'] ?? null);
        }

        if ($format === 'csv') {
            return $this->exportCSV($results);
        }

        return $results;
    }

    /**
     * Calcula o valor de estoque FIFO para um produto específico
     * @param int $produto_id ID do produto
     * @param string|null $data_fim Data limite para considerar entradas (formato YYYY-MM-DD)
     * @return float Valor do estoque FIFO
     */
    public function calculateFIFO($produto_id, $data_fim = null)
    {
        // Query para buscar entradas ordenadas (FIFO)
        $sql = "
            SELECT 
                mi.valor_unitario,
                mi.quantidade,
                m.criado_em,
                mi.id
            FROM movimentacao_itens mi
            JOIN movimentacoes m ON mi.movimentacao_id = m.id
            JOIN tipos_movimentacao tm ON m.tipo_movimentacao_id = tm.id
            WHERE tm.tipo = 'ENTRADA'
                AND mi.produto_id = :produto_id
                AND (:data_fim_a IS NULL OR m.criado_em <= :data_fim)
            ORDER BY m.criado_em DESC, mi.id ASC;
        ";

        $params = [
            ':produto_id' => $produto_id,
            ':data_fim_a' => $data_fim,
            ':data_fim' => $data_fim
        ];

        $entradas = $this->rawQuery($sql, $params);

        // Buscar estoque atual
        $estoque_atual = $this->rawQuery("SELECT estoque_atual FROM produtos WHERE id = :id", [':id' => $produto_id])[0]['estoque_atual'] ?? 0;

        if ($estoque_atual <= 0 || empty($entradas)) {
            return 0.0;
        }

        $valor_estoque = 0.0;
        $qtd_restante = $estoque_atual;

        foreach ($entradas as $entrada) {
            if ($qtd_restante <= 0) {
                break;
            }

            $qtd_usada = min($qtd_restante, $entrada['quantidade']);
            $valor_estoque += $qtd_usada * $entrada['valor_unitario'];
            $qtd_restante -= $qtd_usada;
        }

        return $valor_estoque;
    }

    public function valorEstoque()
    {
        $sql = "SELECT SUM(valor_unitario) as total FROM estoques";;
       
       
        $resultado = $this->rawQuery($sql);

        // Para acessar o valor (assumindo que retorna um array de objetos ou array associativo)
        $total = $resultado[0]['total'] ?? 0;
        return $total;
    }

    /**
     * Exporta relatório em CSV
     * @param array $data Dados do relatório
     * @return string CSV gerado
     */
    private function exportCSV($data)
    {
        ob_start();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="relatorio_estoque.csv"');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', 'Nome', 'Categoria', 'Fornecedor', 'Estoque Atual', 'Valor Estoque (FIFO)'], ';');

        foreach ($data as $row) {
            fputcsv($output, [
                $row['id'],
                $row['nome'],
                $row['categoria'] ?? 'Sem Categoria',
                $row['fornecedor'] ?? 'Sem Fornecedor',
                $row['estoque_atual'],
                number_format($row['valor_estoque'], 2, ',', '.')
            ], ';');
        }

        fclose($output);
        return ob_get_clean();
    }
}