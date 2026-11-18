<?php
// Executado por test_alerta_estoque_smtp.py com um banco e um SMTP de teste.
$root = dirname(__DIR__, 2);
$isolatedRoot = $argv[1];
define('LOG_PATH', $isolatedRoot . '/logs');
require $root . '/vendor/autoload.php';
spl_autoload_register(function ($class) use ($root) {
    $path = $root . '/src/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

require $isolatedRoot . '/src/Models/Alert.php';

$_ENV['SMTP_HOST'] = '127.0.0.1';
$_ENV['SMTP_PORT'] = (int) $argv[2];
$_ENV['SMTP_USER'] = 'smtp-teste';
$_ENV['SMTP_PASS'] = 'senha-teste';

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec(<<<'SQL'
CREATE TABLE produtos (id INTEGER PRIMARY KEY, nome TEXT, estoque_atual INTEGER, estoque_minimo INTEGER, ativo BOOLEAN);
CREATE TABLE estoques (produto_id INTEGER);
INSERT INTO produtos VALUES (1, 'Produto teste', 3, 2, TRUE), (2, 'Produto acima do mínimo', 5, 2, TRUE),
    (3, 'Segundo produto movimentado', 2, 2, TRUE), (4, 'Produto crítico sem movimentação', 0, 1, TRUE);
INSERT INTO estoques VALUES (1), (1), (1), (2), (2), (2), (2), (2), (3), (3);
CREATE VIEW vw_produtos_estoque_critico AS
    SELECT p.id, p.nome, COUNT(e.produto_id) AS estoque_atual, p.estoque_minimo
    FROM produtos p LEFT JOIN estoques e ON e.produto_id = p.id
    WHERE p.ativo = TRUE
    GROUP BY p.id
    HAVING COUNT(e.produto_id) <= p.estoque_minimo;
CREATE TABLE usuarios (id INTEGER PRIMARY KEY, email TEXT, perfil_id INTEGER, ativo BOOLEAN);
INSERT INTO usuarios VALUES
    (1, NULL, 1, TRUE), (2, 'invalido', 1, TRUE),
    (3, 'admin-a@example.test', 1, TRUE), (4, 'admin-b@example.test', 1, TRUE),
    (5, 'inativo@example.test', 1, FALSE), (6, 'operador@example.test', 2, TRUE);
CREATE TABLE alertas_estoque (id INTEGER PRIMARY KEY, produto_id INTEGER, tipo_alerta TEXT, mensagem TEXT, lido BOOLEAN, data_leitura TEXT, usuario_leitura_id INTEGER, criado_em TEXT);
CREATE TABLE auditoria (
    tabela TEXT, registro_id INTEGER, acao TEXT CHECK (acao IN ('INSERT', 'UPDATE', 'DELETE')),
    dados_anteriores TEXT, dados_novos TEXT, usuario_id INTEGER, ip_address TEXT, user_agent TEXT
);
SQL);

$reflection = new ReflectionClass(Models\Database::class);
$database = $reflection->newInstanceWithoutConstructor();
$reflection->getProperty('connection')->setValue($database, $pdo);
$reflection->getProperty('instance')->setValue(null, $database);

$alert = new Models\Alert();
$alert->checkLowStock(2);
$pdo->exec('DELETE FROM estoques WHERE rowid = (SELECT MIN(rowid) FROM estoques WHERE produto_id = 1)');
if (!(new Models\Product())->atualizarEstoque(1, 1, 'SAIDA', false)) {
    throw new RuntimeException('A atualização da saída falhou.');
}
$alert->checkLowStockBatch([1, 3, 3]);
$alert->checkLowStockBatch([1, 3]);

$pending = $pdo->query('SELECT produto_id, usuario_leitura_id FROM alertas_estoque ORDER BY produto_id, usuario_leitura_id')
    ->fetchAll(PDO::FETCH_NUM);
if ($pending !== [[1, 1], [1, 2], [1, 3], [1, 4], [3, 1], [3, 2], [3, 3], [3, 4]]) {
    throw new RuntimeException('Esperava um alerta interno por produto movimentado e administrador ativo.');
}
if ((int) $pdo->query("SELECT COUNT(*) FROM auditoria WHERE tabela = 'alertas_estoque' AND acao = 'INSERT'")->fetchColumn() !== 8) {
    throw new RuntimeException('A criação dos alertas não foi auditada corretamente.');
}

// Uma nova checagem deve recriar o alerta apenas para quem já leu o anterior.
if (!$alert->markAllAsRead(3)) {
    throw new RuntimeException('Não foi possível marcar o alerta como lido.');
}
$alert->checkLowStock(1);
$alert->checkLowStock(1);

if ($pdo->query('SELECT usuario_leitura_id FROM alertas_estoque WHERE produto_id = 1 AND lido = FALSE ORDER BY usuario_leitura_id')
        ->fetchAll(PDO::FETCH_COLUMN) !== [1, 2, 3, 4]) {
    throw new RuntimeException('Cada administrador ativo deve ter exatamente um alerta pendente.');
}
if ((int) $pdo->query('SELECT COUNT(*) FROM alertas_estoque WHERE produto_id = 1 AND usuario_leitura_id = 3')->fetchColumn() !== 2) {
    throw new RuntimeException('A pendência de outro administrador bloqueou o novo alerta.');
}

session_start(['save_path' => $isolatedRoot]);
$_SESSION['user_id'] = 3;
$pending = $alert->getPendingAlerts();
if (count($pending) !== 1 || $pending[0]['produto_id'] !== 1) {
    throw new RuntimeException('O novo alerta não apareceu na lista do administrador.');
}
