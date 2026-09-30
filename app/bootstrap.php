<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
date_default_timezone_set('America/Belem');

require BASE_PATH . '/app/helpers.php';
require BASE_PATH . '/app/Database.php';
spl_autoload_register(function (string $classe) {
    $arquivo = BASE_PATH . '/app/Models/' . $classe . '.php';
    if (is_file($arquivo)) require $arquivo;
});

session_start();

set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    error_log((string)$e);
    echo '<h1>Erro interno</h1><p>Algo deu errado. Tente novamente.</p>';
    if (config('debug')) echo '<pre>' . e((string)$e) . '</pre>';
});

Database::migrar();
