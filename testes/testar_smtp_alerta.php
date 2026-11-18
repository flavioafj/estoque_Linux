<?php

use Dotenv\Dotenv;
use Models\Alert;
use PHPMailer\PHPMailer\PHPMailer;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if ($argc === 2 && $argv[1] === '--help') {
    echo "Uso: php testes/testar_smtp_alerta.php destinatario@exemplo.com\n";
    echo "Envia um alerta fictício pelo SMTP configurado para o projeto.\n";
    exit(0);
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$recipient = $argv[1] ?? '';
if ($argc !== 2 || !PHPMailer::validateAddress($recipient)) {
    fwrite(STDERR, "Informe um único endereço de e-mail válido. Use --help para ver o comando.\n");
    exit(2);
}

require $root . '/src/Models/BaseModel.php';
require $root . '/src/Models/Alert.php';

try {
    Dotenv::createUnsafeImmutable($root)->safeLoad();
    foreach (['SMTP_HOST', 'SMTP_USER', 'SMTP_PASS'] as $key) {
        if (empty($_ENV[$key])) {
            fwrite(STDERR, "Configuração obrigatória ausente: {$key}.\n");
            exit(2);
        }
    }

    $mail = Alert::createMailer([[
        'nome' => 'Produto de teste',
        'estoque_atual' => 2,
        'estoque_minimo' => 2,
    ]], 'Teste de envio. Este alerta usa dados fictícios.');
    $mail->Subject = '[TESTE] ' . $mail->Subject;
    $mail->Timeout = 15;
    $mail->addAddress($recipient);
    $mail->send();
    echo "O servidor SMTP aceitou o alerta de teste para {$recipient}. Confira a caixa de entrada e o spam.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Falha no teste SMTP: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
