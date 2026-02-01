<?php
/**
 * Script de Backup Automático - scripts/backup.php
 * Compatível Raspberry Pi OS e AlmaLinux 9
 */

require_once __DIR__ . '/../config/config.php';  // Define DB_*, BACKUP_PATH, LOG_PATH, BACKUP_DAYS_TO_KEEP
require_once __DIR__ . '/../src/Models/Database.php';

use Models\Database;

// Garantir diretório (compatível com permissões RPi/Webmin)
if (!is_dir(BACKUP_PATH)) {
    if (!mkdir(BACKUP_PATH, 0755, true)) {
        error_log("Falha ao criar " . BACKUP_PATH, 3, LOG_PATH . '/backup_error.log');
        exit(1);
    }
}

$timestamp = date('Y-m-d_H-i-s');
$backup_file = BACKUP_PATH . "/backup_{$timestamp}.sql.gz";
$dump_temp = BACKUP_PATH . "/backup_{$timestamp}.sql";  // Temp para dump

// Caminhos absolutos (ajuste se necessário: verifique com `which mysqldump` no terminal)
$mysqldump = '/usr/bin/mysqldump';  // Padrão em Debian/RPi e AlmaLinux
$gzip = '/usr/bin/gzip';

// Comando com escaping (seguro para senhas complexas)
$command = escapeshellcmd($mysqldump) . ' ' .
           '--host=' . escapeshellarg(DB_HOST) . ' ' .
           '--port=' . escapeshellarg(DB_PORT) . ' ' .
           '--user=' . escapeshellarg(DB_USER) . ' ' .
           '--password=' . escapeshellarg(DB_PASS) . ' ' .
           '--single-transaction --quick --lock-tables=false ' .
           escapeshellarg(DB_NAME) . ' > ' . escapeshellarg($dump_temp) . ' && ' .
           escapeshellcmd($gzip) . ' ' . escapeshellarg($dump_temp);

exec($command . ' 2>&1', $output, $return_var);

if ($return_var !== 0) {
    $error = "Erro backup (cód. $return_var): " . implode("\n", $output);
    error_log($error, 3, LOG_PATH . '/backup_error.log');
    if (file_exists($dump_temp)) unlink($dump_temp);
    exit(1);
}

if (file_exists($dump_temp)) unlink($dump_temp);

// Limpeza antigos
$files = glob(BACKUP_PATH . '/backup_*.sql.gz');
$limit = time() - (BACKUP_DAYS_TO_KEEP * 86400);
foreach ($files as $file) {
    if (filemtime($file) < $limit) unlink($file);
}

error_log("Backup OK: $backup_file", 3, LOG_PATH . '/backup.log');
echo "Backup concluído: $backup_file\n";