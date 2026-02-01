<?php
$senhaTexto = 'in123';
$senhaHash = password_hash($senhaTexto, PASSWORD_DEFAULT);
echo $senhaHash;
?>