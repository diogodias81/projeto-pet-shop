<?php
// Backup do banco e das fotos. Roda dentro do container (scripts/backup.ps1 agenda no Windows):
//   docker compose exec -T -u www-data php php scripts/backup.php
if (PHP_SAPI !== 'cli') exit("Só pela linha de comando.\n");

define('BASE_PATH', dirname(__DIR__));
date_default_timezone_set('America/Belem');
require BASE_PATH . '/app/helpers.php';
require BASE_PATH . '/app/Database.php';
require BASE_PATH . '/app/Models/Backup.php';

try {
    echo Backup::executar(), "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FALHA no backup: ' . $e->getMessage() . "\n");
    exit(1);
}
