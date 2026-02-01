# Manual do Sistema de Controle de Estoques - Sorveteria

## 1. Introdução
Este sistema gerencia o estoque de uma sorveteria, com módulos local (saídas) e web (gestão). Suporta CRUD de produtos, movimentações, relatórios, auditoria e sincronização.

## 2. Requisitos
- **Servidor Local**: PHP 7.4+, MySQL 5.7+, Apache/Nginx
- **Servidor Web**: PHP 7.4+, MySQL 5.7+, Apache/Nginx, Composer
- **Dependências**: `ext-pdo`, `ext-curl`, `ext-zlib`, `mysqldump`
- **Cron**: Para sincronização e backups automáticos

## 3. Instalação
1. **Clone o Repositório**:
   ```bash
   git clone <repositorio>
   cd estoque-sorveteria

Conteúdo: Script para exportar o banco de dados com mysqldump, salvar no diretório backups/ com nome baseado na data, e remover backups mais antigos que 30 dias.
Propósito: Garantir backups automáticos.

php<?php
/**
 * Script de Backup Automático
 * scripts/backup.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Models/Database.php';

use Models\Database;

// Criar diretório de backups se não existir
if (!is_dir(BACKUP_PATH)) {
    mkdir(BACKUP_PATH, 0755, true);
}

// Gerar nome do arquivo de backup
$backup_file = BACKUP_PATH . '/backup_' . date('Ymd_His') . '.sql.gz';

// Comando mysqldump
$command = sprintf(
    'mysqldump --host=%s --port=%s --user=%s --password=%s %s | gzip > %s',
    DB_HOST, DB_PORT, DB_USER, DB_PASS, DB_NAME, $backup_file
);

// Executar backup
exec($command, $output, $return_var);

if ($return_var !== 0) {
    error_log("Erro ao criar backup: " . implode("\n", $output), 3, LOG_PATH . '/backup_error.log');
    exit(1);
}

// Remover backups antigos
$files = glob(BACKUP_PATH . '/*.sql.gz');
$limit = strtotime('-' . BACKUP_DAYS_TO_KEEP . ' days');
foreach ($files as $file) {
    if (filemtime($file) < $limit) {
        unlink($file);
    }
}

echo "Backup criado com sucesso: $backup_file\n";
5. docs/manual.md

Conteúdo: Documentação técnica com instruções de instalação, configuração, uso do sistema (funcionalidades para administradores e operadores), e plano de manutenção.
Propósito: Orientar usuários e desenvolvedores.

markdown# Manual do Sistema de Controle de Estoques - Sorveteria

## 1. Introdução
Este sistema gerencia o estoque de uma sorveteria, com módulos local (saídas) e web (gestão). Suporta CRUD de produtos, movimentações, relatórios, auditoria e sincronização.

## 2. Requisitos
- **Servidor Local**: PHP 7.4+, MySQL 5.7+, Apache/Nginx
- **Servidor Web**: PHP 7.4+, MySQL 5.7+, Apache/Nginx, Composer
- **Dependências**: `ext-pdo`, `ext-curl`, `ext-zlib`, `mysqldump`
- **Cron**: Para sincronização e backups automáticos

## 3. Instalação
1. **Clone o Repositório**:
   ```bash
   git clone <repositorio>
   cd estoque-sorveteria

Configurar Banco de Dados:

Execute database/schema.sql no MySQL.
Atualize .env com:
plaintextDB_HOST=localhost
DB_PORT=3306
DB_NAME=sorveteria_estoque
DB_USER=seu_usuario
DB_PASS=sua_senha
SYNC_URL=https://seu-servidor.com/api/sync
SYNC_TOKEN=seu_token_secreto
BACKUP_PATH=/caminho/absoluto/backups
LOG_PATH=/caminho/absoluto/logs



Instalar Dependências (se usar Composer):
bashcomposer install

Configurar Permissões:
bashchmod -R 755 public/ backups/ logs/
chmod -R 644 public/*.php public/assets/*

Configurar Cron (local e web):
bash*/5 * * * * php /caminho/estoque-sorveteria/scripts/sync.php >> /caminho/logs/sync.log 2>&1
0 2 * * * php /caminho/estoque-sorveteria/scripts/backup.php >> /caminho/logs/backup.log 2>&1


4. Configuração

Ambiente Local:

Use APP_ENV=development em .env.
Configure database.php para conectar ao MySQL local.


Ambiente Web:

Use APP_ENV=production em .env.
Configure servidor (ex.: AWS, Hostinger) com HTTPS.
Ajuste .htaccess para roteamento e segurança.


Sincronização:

Verifique SYNC_URL e SYNC_TOKEN em .env.
Teste a API: curl -H "Authorization: Bearer seu_token" https://seu-servidor.com/api/sync



5. Uso

Operadores:

Acesse http://localhost/estoque-sorveteria/login.php.
Faça login (ex.: admin/admin123).
Registre saídas em /saidas.php (interface tipo e-commerce).


Administradores:

Acesse /admin/products.php para gerenciar produtos.
Use /admin/relatorios.php para relatórios.
Configure alertas em /admin/configuracoes.php.


Sincronização:

Executada automaticamente a cada 5 minutos.
Manual: php scripts/sync.php.


Backup:

Backups diários em /backups/.
Restaurar: gunzip backup_YYYYMMDD_HHMMSS.sql.gz && mysql -u usuario -p sorveteria_estoque < backup_YYYYMMDD_HHMMSS.sql



6. Manutenção

Logs: Monitore logs/error.log, logs/sync_error.log, logs/backup_error.log.
Atualizações: Verifique dependências (composer update) e schema do banco.
Segurança: Atualize SYNC_TOKEN regularmente e restrinja acesso a /backups e /logs.