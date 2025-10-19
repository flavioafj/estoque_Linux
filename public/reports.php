<?php
require_once '../config/config.php';


use Helpers\Session;
use Models\Category;
use Models\Fornecedor;
use Models\TipoMovimentacao; 

//Auth::check(['admin', 'operador']);

// Inicializa a sessão
if (session_status() === PHP_SESSION_NONE) {
    Session::start();
}

$categories = (new Category())->getAll();
$fornecedores = (new Fornecedor())->getAll();
$tiposMovimentacao = (new TipoMovimentacao())->all(); // NOVO

$title = 'Relatórios de Estoque';


    include __DIR__ . '/../templates/header.php';
    include __DIR__ . '/../templates/navigation.php';
     ?>
    <main>
        <h1>Relatórios de Estoque</h1>
        <form id="report-form">
            <div class="form-group">
                <label for="produto_id">Produto (ID ou Nome):</label>
                <input type="text" id="produto_id" name="produto_id" placeholder="Digite o ID ou nome do produto">
            </div>
            <div class="form-group">
                <label for="categoria_id">Categoria:</label>
                <select id="categoria_id" name="categoria_id">
                    <option value="">Todas</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= htmlspecialchars($category['id']) ?>">
                            <?= htmlspecialchars($category['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="fornecedor_id">Fornecedor:</label>
                <select id="fornecedor_id" name="fornecedor_id">
                    <option value="">Todos</option>
                    <?php foreach ($fornecedores as $fornecedor): ?>
                        <option value="<?= htmlspecialchars($fornecedor['id']) ?>">
                            <?= htmlspecialchars($fornecedor['razao_social']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="tipo_movimentacao">Tipo de Movimentação:</label>
                <select id="tipo_movimentacao" name="tipo_movimentacao">
                    <option value="">Todos</option>
                    
                        <option value="ENTRADA">
                            ENTRADA
                        </option>
                        <option value="SAIDA">
                            SAIDA
                        </option>
                   
                </select>
            </div>
            <div class="form-group">
                <label for="data_inicio">Data Início:</label>
                <input type="date" id="data_inicio" name="data_inicio">
            </div>
            <div class="form-group">
                <label for="data_fim">Data Fim:</label>
                <input type="date" id="data_fim" name="data_fim">
            </div>
            <button type="submit" class="btn">Gerar Relatório</button>
            <button type="button" id="export-csv" class="btn">Exportar CSV</button>
        </form>
        <div id="report-results">
            <table id="report-table" class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nome</th>
                        <th>Categoria</th>
                        <th>Fornecedor</th>
                        <th>Estoque Atual</th>
                        <th>Valor Estoque (FIFO)</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </main>
    <?php include __DIR__ . '/../templates/footer.php'; ?>
