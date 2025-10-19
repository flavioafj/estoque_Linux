<?php
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