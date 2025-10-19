<?php
$senhaTexto = '0716';
$senhaHash = password_hash($senhaTexto, PASSWORD_DEFAULT);
echo $senhaHash;
?>