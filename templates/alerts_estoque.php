<?php
use Helpers\Session;
use Models\Alert;

if (session_status() === PHP_SESSION_NONE) {
    Session::start();
}

require_once '../templates/header.php';
require_once '../templates/navigation.php';
?>

<main class="container mt-4">
    <h2>Alertas de Estoque Pendente</h2>
    <?php if (Session::has('success')): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars(Session::get('success')); Session::remove('success'); ?></div>
    <?php endif; ?>
    <?php if (Session::has('errors')): ?>
        <div class="alert alert-danger">
            <strong>Por favor, corrija os seguintes erros:</strong>
            <ul>
                <?php foreach (Session::get('errors') as $fieldErrors): ?>
                    <?php foreach ($fieldErrors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </ul>
            <?php Session::remove('errors'); ?>
        </div>
    <?php endif; ?>

    <div class="alerts-container">
        <?php if (empty($alerts)): ?>
            <p>Nenhum alerta pendente.</p>
        <?php else: ?>
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Produto</th>
                        <th>Tipo</th>
                        <th>Mensagem</th>
                        <th>Data</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($alerts as $alert): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($alert['nome']); ?></td>
                            <td><?php echo htmlspecialchars($alert['tipo_alerta']); ?></td>
                            <td><?php echo htmlspecialchars($alert['mensagem']); ?></td>
                            <td><?php echo htmlspecialchars($alert['criado_em']); ?></td>
                            <td>
                                <?php if (Session::isAdmin()): ?>
                                    <button class="btn btn-danger mark-read" data-alert-id="<?php echo $alert['id']; ?>">Marcar como Lido</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</main>

<script src="assets/js/alerts.js"></script>

<?php
require_once '../templates/footer.php';
?>