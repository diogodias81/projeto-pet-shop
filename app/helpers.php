<?php


function config(string $chave)
{
    static $c = null;

    $c ??= require BASE_PATH . '/config/config.php';

    return $c[$chave] ?? null;
}



function e($v): string
{
    return htmlspecialchars(
        (string) $v,
        ENT_QUOTES,
        'UTF-8'
    );
}


/** Endereço de um arquivo de public/ com a data de alteração: o navegador baixa de novo quando o arquivo muda */
function asset(string $caminho): string
{
    return $caminho . '?v=' . @filemtime(BASE_PATH . '/public/' . $caminho);
}


function moeda($v): string
{
    return ($v < 0 ? '-' : '') . 'R$ ' . number_format(
        abs((float) $v),
        2,
        ',',
        '.'
    );
}


function dataBr(string $d): string
{
    return date(
        'd/m/Y H:i',
        strtotime($d)
    );
}



/**
 * Converte "143,99", "R$ 143,99", "1.234,56" ou "143.99" em número.
 * Retorna 0 se o valor for inválido.
 */
function numero($v): float
{
    // mantém só dígitos, vírgula, ponto e sinal de menos (remove "R$", espaços etc.)
    $v = preg_replace('/[^\d,.\-]/', '', (string) $v);

    if (str_contains($v, ',')) {

        $v = str_replace(
            ['.', ','],
            ['', '.'],
            $v
        );
    }

    return is_numeric($v) ? (float) $v : 0.0;
}


function redirect(
    string $pagina,
    array $params = []
): void {

    if (headers_sent($arquivo, $linha)) {

        die('HEADERS JÁ FORAM ENVIADOS<br>' .
            'Arquivo: ' . $arquivo . '<br>' .
            'Linha: ' . $linha);
    }

    $url = http_build_query(
        ['page' => $pagina] + $params
    );

    header(
        'Location: index.php?' . $url
    );

    exit;
}

function flash(
    ?string $msg = null,
    string $tipo = 'ok'
) {

    if ($msg !== null) {

        $_SESSION['flash'][] = [
            $msg,
            $tipo
        ];

        return null;
    }



    $f = $_SESSION['flash'] ?? [];

    unset($_SESSION['flash']);

    return $f;
}


function csrf_campo(): string
{
    $_SESSION['csrf'] ??=
        bin2hex(random_bytes(16));

    return
        '<input type="hidden" name="csrf" value="' .
        e($_SESSION['csrf']) .
        '">';
}


function csrf_validar(): void
{
    $token = $_POST['csrf'] ?? '';

    if (
        empty($_SESSION['csrf']) ||
        !is_string($token) ||
        !hash_equals($_SESSION['csrf'], $token)
    ) {

        http_response_code(400);

        exit('Requisição inválida. ' .
            'Volte e tente novamente.');
    }

    // Texto fora do UTF-8 quebra o json_encode do catálogo e derruba a tela de vendas
    // json_encode falha com UTF-8 inválido em qualquer chave ou valor, em qualquer nível
    if (json_encode($_POST) === false) {
        http_response_code(400);
        exit('Texto com caracteres inválidos. Volte e tente novamente.');
    }
}



function usuario(): ?array
{
    return $_SESSION['user'] ?? null;
}



function temPerfil(array $perfis): bool
{
    return in_array(
        usuario()['perfil'] ?? '',
        $perfis,
        true
    );
}


function exigirPerfil(array $perfis): void
{
    if (!temPerfil($perfis)) {

        paginaErro(403, 'Acesso negado: seu perfil não pode abrir esta tela.');
    }
}



function podeGerirEstoque(): bool
{
    return temPerfil([
        'admin',
        'estoque'
    ]);
}

/** Erro dentro do layout (menu + link de volta) para usuário logado */
function paginaErro(int $codigo, string $msg): never
{
    http_response_code($codigo);

    while (ob_get_level()) {
        ob_end_clean();
    }

    require BASE_PATH . '/app/Views/header.php';

    echo '<section class="card card-body mb-3"><p>' . e($msg) . '</p><p><a href="index.php">Voltar ao início</a></p></section>';

    require BASE_PATH . '/app/Views/footer.php';

    exit;
}


/** Baixa uma planilha CSV (separador ";" e BOM para o Excel em português abrir com acentos) */
function exportarCsv(string $arquivo, array $cabecalho, array $linhas): never
{
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^\w.-]/', '_', $arquivo) . '"');

    $saida = fopen('php://output', 'w');
    fwrite($saida, "\xEF\xBB\xBF");
    fputcsv($saida, $cabecalho, ';', '"', '');

    foreach ($linhas as $linha) {
        // texto que começa com = + - @ viraria fórmula no Excel (injeção de CSV)
        fputcsv($saida, array_map(fn($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) && !is_numeric(strtr($v, ',', '.')) ? "'" . $v : $v, $linha), ';', '"', '');
    }

    exit;
}
