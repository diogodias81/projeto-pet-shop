<?php

function config(string $chave) {
    static $c = null;
    $c ??= require BASE_PATH . '/config/config.php';
    return $c[$chave] ?? null;
}

function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function moeda($v): string {
    return 'R$ ' . number_format((float)$v, 2, ',', '.');
}

function dataBr(string $d): string {
    return date('d/m/Y H:i', strtotime($d));
}

/** Converte "1.234,56" ou "12,5" ou "12.5" em float */
function numero($v): float {
    $v = trim((string)$v);

    if (str_contains($v, ',')) {
        $v = str_replace(['.', ','], ['', '.'], $v);
    }

    return (float)$v;
}

function redirect(string $pagina, array $params = []): void {

    if (headers_sent($arquivo, $linha)) {
        die(
            'HEADERS JÁ FORAM ENVIADOS<br>' .
            'Arquivo: ' . $arquivo . '<br>' .
            'Linha: ' . $linha
        );
    }

    header('Location: index.php?' . http_build_query(['page' => $pagina] + $params));
    exit;
}

function flash(?string $msg = null, string $tipo = 'ok') {
    if ($msg !== null) {
        $_SESSION['flash'][] = [$msg, $tipo];
        return null;
    }

    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return $f;
}

// CSRF
function csrf_campo(): string {
    $_SESSION['csrf'] ??= bin2hex(random_bytes(16));

    return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">';
}

function csrf_validar(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400);
        exit('Requisição inválida. Volte e tente novamente.');
    }
}

// Autenticação e perfis
function usuario(): ?array {
    return $_SESSION['user'] ?? null;
}

function temPerfil(array $perfis): bool {
    return in_array(usuario()['perfil'] ?? '', $perfis, true);
}

function exigirPerfil(array $perfis): void {
    if (!temPerfil($perfis)) {
        http_response_code(403);
        exit('Acesso negado.');
    }
}

/** Quem pode alterar produtos, categorias e entradas de estoque */
function podeGerirEstoque(): bool {
    return temPerfil(['admin', 'estoque']);
}