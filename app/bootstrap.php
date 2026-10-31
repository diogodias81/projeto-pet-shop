<?php
declare(strict_types=1);
define('BASE_PATH', dirname(__DIR__));
date_default_timezone_set('America/Belem');
require BASE_PATH . '/app/helpers.php';
require BASE_PATH . '/app/Database.php';

spl_autoload_register(function (string $classe): void {
    $arquivo = BASE_PATH . '/app/Models/' . $classe . '.php';

    if (is_file($arquivo)) {
        require $arquivo;
    }
});

ini_set('display_errors', config('debug') ? '1' : '0');

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => ($_SERVER['HTTPS'] ?? 'off') !== 'off',
    'use_strict_mode' => true,
]);

$_SESSION['csrf'] ??= bin2hex(random_bytes(16));

header_remove('X-Powered-By');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
header("Content-Security-Policy: default-src 'self'; img-src 'self'; style-src 'self' 'unsafe-inline'; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");

set_exception_handler(function (Throwable $e): void {
    error_log((string) $e);

    while (ob_get_level()) {
        ob_end_clean();
    }

    // Tipo errado vindo do usuário (ex.: campo enviado como lista) é erro de quem pediu, não do servidor.
    if ($e instanceof TypeError || $e instanceof ValueError) {
        http_response_code(400);
        echo '<h1>Requisição inválida</h1><p>Volte e tente novamente.</p>';
    } elseif ($e instanceof PDOException && in_array($e->errorInfo[1] ?? 0, [5, 6], true)) {
        // SQLITE_BUSY / SQLITE_LOCKED: outra gravação demorou mais que o busy_timeout
        http_response_code(503);
        echo '<h1>Sistema ocupado</h1><p>Tente novamente em alguns segundos.</p>';
    } else {
        http_response_code(500);
        echo '<h1>Erro interno</h1><p>Algo deu errado. Tente novamente.</p>';
    }

    if (config('debug')) {
        echo '<pre>' . e((string) $e) . '</pre>';
    }
});

// Parâmetros chegam como texto. Lista só em devolver[id] (tela de devolução), e sem listas dentro.
foreach ([$_GET, $_POST] as $entrada) {
    foreach ($entrada as $chave => $valor) {
        if (is_array($valor) && ($chave !== 'devolver' || array_filter($valor, 'is_array'))) {
            http_response_code(400);
            exit('Requisição inválida. Volte e tente novamente.');
        }
    }
}

Database::migrar();

// Recarrega o usuário a cada requisição: excluído ou desativado perde a sessão, perfil alterado vale na hora.
if (isset($_SESSION['user'])) {
    $st = Database::pdo()->prepare('SELECT id, nome, perfil, trocar_senha FROM usuarios WHERE id = ? AND ativo = 1');
    $st->execute([$_SESSION['user']['id']]);

    if ($u = $st->fetch()) {
        $_SESSION['user'] = ['id' => (int) $u['id'], 'nome' => $u['nome'], 'perfil' => $u['perfil'], 'trocar_senha' => (int) $u['trocar_senha']];
    } else {
        $_SESSION = ['csrf' => bin2hex(random_bytes(16))];
        session_regenerate_id(true);
    }
}
