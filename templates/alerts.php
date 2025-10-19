<?php
use Helpers\Session;

if (session_status() === PHP_SESSION_NONE) {
    Session::start();
}

// Exibe mensagens de sucesso
if (Session::has('success')) {
    echo '<div class="alert alert-success" role="alert">' . htmlspecialchars(Session::get('success')) . '</div>';
    Session::remove('success'); // CORRIGIDO: Usando o método remove()
}

// Exibe mensagens de erro
if (Session::has('errors')) {
    $errors = Session::get('errors');
    echo '<div class="alert alert-danger" role="alert">';
    echo '<strong>Por favor, corrija os seguintes erros:</strong>';
    echo '<ul>';
    foreach ($errors as $fieldErrors) {
        foreach ($fieldErrors as $error) {
            echo '<li>' . htmlspecialchars($error) . '</li>';
        }
    }
    echo '</ul>';
    echo '</div>';
    Session::remove('errors'); // CORRIGIDO: Usando o método remove()
}
?>

<!-- Adicione um pouco de estilo para as alertas (opcional, pode ir em um arquivo CSS) -->
<style>
.alert {
    padding: 15px;
    margin-bottom: 20px;
    border: 1px solid transparent;
    border-radius: 4px;
}
.alert-success {
    color: #155724;
    background-color: #d4edda;
    border-color: #c3e6cb;
}
.alert-danger {
    color: #721c24;
    background-color: #f8d7da;
    border-color: #f5c6cb;
}
.alert ul {
    margin-bottom: 0;
}
</style>