<?php
// public/audit.php
//require_once __DIR__ . '/../src/Models/User.php'; // Para getAll
require_once '../config/config.php';

use Models\User;

$userModel = new User();
$users = $userModel->getAllWithProfiles(); // Assumindo getAll existe em User.php; se não, ajuste para getAllWithProfiles ou similar

$title = 'Auditoria do Sistema';


    include __DIR__ . '/../templates/header.php';
    include __DIR__ . '/../templates/navigation.php';
     ?>
<main class="container dashboard-container mt-4">
    <form id="filterForm">
        <label for="tabela">Tabela:</label>
        <select id="tabela" name="tabela">
            <option value="">Todas</option>
            <option value="produtos">Produtos</option>
            <option value="movimentacoes">Movimentações</option>
            <option value="movimentacao_itens">Itens de Movimentação</option>
            <option value="usuarios">Usuários</option>
            <option value="categorias">Categorias</option>
        </select>
        
        <label for="acao">Ação:</label>
        <select id="acao" name="acao">
            <option value="">Todas</option>
            <option value="INSERT">INSERT</option>
            <option value="UPDATE">UPDATE</option>
            <option value="DELETE">DELETE</option>
            <option value="LOGIN">LOGIN</option>
            <option value="LOGOUT">LOGOUT</option>
        </select>
        
        <label for="usuario_id">Usuário:</label>
        <select id="usuario_id" name="usuario_id">
            <option value="">Todos</option>
            <?php foreach ($users as $user): ?>
                <option value="<?php echo $user['id']; ?>"><?php echo $user['nome_completo']; ?></option>
            <?php endforeach; ?>
        </select>
        
        <label for="data_inicio">Data Início:</label>
        <input type="date" id="data_inicio" name="data_inicio">
        
        <label for="data_fim">Data Fim:</label>
        <input type="date" id="data_fim" name="data_fim">
        
        <button type="submit">Filtrar</button>
    </form>
    
    <table id="auditTable" class="responsive-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Tabela</th>
                <th>Registro ID</th>
                <th>Ação</th>
                <th>Usuário</th>
                <th>IP</th>
                <th>User Agent</th>
                <th>Data</th>
                <th>Dados Anteriores</th>
                <th>Dados Novos</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>

</main>
    
    <script src="assets/js/audit.js"></script>
<?php include __DIR__ . '/../templates/footer.php'; ?>