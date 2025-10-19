<?php
use Models\BaseModel;
use Models\Alert;

$baseModelPath = __DIR__ . '/BaseModel.php';
if (!file_exists($baseModelPath)) {
    error_log("Product.php: Não encontrou BaseModel.php em $baseModelPath", 3, __DIR__ . '/../../logs/error.log');
    die("Erro: Não foi possível carregar BaseModel.php.");
}
//require_once $baseModelPath;

class Product extends BaseModel {
    protected $table = 'produtos';
    protected $fillable = [
        'nome', 'codigo', 'codigo_barras', 'categoria_id', 'unidade_medida_id',
        'estoque_atual', 'estoque_minimo', 'estoque_maximo', 'preco_custo',
        'preco_venda', 'margem_lucro', 'localizacao', 'fornecedor_principal_id',
        'descricao', 'ativo', 'criado_por', 'foto_url', 'observacoes'
    ];

     // ADICIONE ESTE CONSTRUTOR
    public function __construct()
    {
        parent::__construct();
    }

    public function create($data) {
        $data['criado_por'] = $_SESSION['user_id'] ?? null;
        return parent::create($data);
    }

    public function update($id, $data) {
        return parent::update($id, $data);
    }

    public function delete($id) {
        return parent::softDelete($id);
    }

    public function getAll() {
        return $this->where('ativo', 1);
    }

    public function getAllinativo() {
        return $this->where('ativo', 0);
    }

    public function getById($id) {
        return $this->find($id);
    }

    
    /**
     * Atualiza a quantidade em estoque de um produto.
     *
     * @param int $produtoId
     * @param float $quantidade
     * @param string $tipoMovimentacao 'ENTRADA' ou 'SAIDA'
     * @return bool
     */
    public function atualizarEstoque(int $produtoId, float $quantidade, string $tipoMovimentacao): bool
    {
        try {
            // Validação dos parâmetros
            if ($quantidade <= 0) {
                error_log("Quantidade inválida: $quantidade");
                return false;
            }

            if (!in_array($tipoMovimentacao, ['ENTRADA', 'SAIDA'])) {
                error_log("Tipo de movimentação inválido: $tipoMovimentacao");
                return false;
            }

            // Verifica se o produto existe
            $produto = $this->find($produtoId);
            if (!$produto) {
                error_log("Produto não encontrado: $produtoId");
                return false;
            }

            // Monta a SQL corretamente - usando 'estoque_atual' em vez de 'quantidade_estoque'
            if ($tipoMovimentacao === 'ENTRADA') {
                $sql = "UPDATE {$this->table} SET estoque_atual = estoque_atual + ? WHERE id = ?";
            } else { // SAIDA
                // Para saídas, verifica se há estoque suficiente
                if ($produto['estoque_atual'] < $quantidade) {
                    error_log("Estoque insuficiente para produto $produtoId. Atual: {$produto['estoque_atual']}, Solicitado: $quantidade");
                    return false;
                }
                $sql = "UPDATE {$this->table} SET estoque_atual = estoque_atual - ? WHERE id = ?";
            }

            // Verifica se a conexão existe
            if (!$this->db || !is_object($this->db)) {
                error_log("Conexão com banco de dados não encontrada");
                return false;
            }

            $stmt = $this->db->prepare($sql);
            
            // Verifica se o prepare foi bem-sucedido
            if ($stmt === false) {
                $errorInfo = $this->db->errorInfo();
                error_log("Erro ao preparar SQL: " . implode(', ', $errorInfo));
                return false;
            }

            $resultado = $stmt->execute([$quantidade, $produtoId]);

            // Verifica estoque mínimo após atualização
            $alertModel = new Alert();
            $alertModel->checkLowStock($produtoId);


            if ($resultado) {
                error_log("Estoque atualizado com sucesso. Produto: $produtoId, Quantidade: $quantidade, Tipo: $tipoMovimentacao");
                return true;
            } else {
                $errorInfo = $stmt->errorInfo();
                error_log("Erro ao executar SQL: " . implode(', ', $errorInfo));
                return false;
            }

        } catch (\Exception $e) {
            error_log("Exceção em atualizarEstoque: " . $e->getMessage());
            return false;
        }
    }

        /**  
     * Retorna produtos ordenados por número de saídas descendente.  
     *  
     * @return array  
     */  
    public function getProdutosPorSaidasDesc() {  
            $sql = "  
                SELECT 
                    p.*,  
                    COUNT(CASE WHEN tm.tipo = 'SAIDA' THEN mi.id END) as saidas_count  
                FROM {$this->table} p  
                LEFT JOIN movimentacao_itens mi ON p.id = mi.produto_id  
                LEFT JOIN movimentacoes m ON mi.movimentacao_id = m.id  
                LEFT JOIN tipos_movimentacao tm ON m.tipo_movimentacao_id = tm.id  
                WHERE p.ativo = 1  
                GROUP BY p.id  
                ORDER BY saidas_count DESC  
            ";  
            $stmt = $this->db->prepare($sql);  
            $stmt->execute();  
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);  
        }  
    }

class UnidadeDeMedida extends BaseModel {
    protected $table = 'unidades_medida';
}