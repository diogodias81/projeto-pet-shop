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
 * Converte "143,99", "R$ 143,99", "1.234,56", "1.250" (milhar) ou "143.99" em número. Vazio vira 0.
 * Outro texto (ex.: "1' OR '1'='1", "12abc", "1e5") é recusado com $erro: antes os dígitos eram juntados e viravam outro valor.
 * O PDV (numero() em app.js) aceita os mesmos formatos.
 */
function numero($v, string $erro = 'Informe um valor válido, ex.: 1.250,00'): float
{
    // "R$" e espaços (comum, tab, NBSP do moeda() do navegador) só nas pontas: "12 15" e "1R$2" não viram outro número
    if (!preg_match('/^[ \t\x{A0}]*(?:R\$)?[ \t\x{A0}]*(.*?)[ \t\x{A0}]*\z/isu', (string) $v, $m)) {
        throw new DomainException($erro);
    }

    $v = $m[1];

    if ($v === '') {
        return 0.0;
    }

    // Formato brasileiro: "1.250,00", "1250,5", "12"; "1.250" sem vírgula é milhar (R$ 1.250,00), não 1,25
    if (preg_match('/^-?([1-9]\d{0,2}(\.\d{3})+|\d+)(,\d*)?$/D', $v)) {
        $x = (float) str_replace(['.', ','], ['', '.'], $v);
    } elseif (preg_match('/^-?\d+(\.\d+)?$/D', $v)) {
        // Ponto decimal: "12.5"
        $x = (float) $v;
    } else {
        throw new DomainException($erro);
    }

    // Dinheiro tem centavos: "0,001" seria exibido como R$ 0,00 e "50,001" como R$ 50,00 ("0.500" = 0,50 passa)
    if (round($x, 2) != $x) {
        throw new DomainException($erro);
    }

    return $x;
}


function redirect(
    string $pagina,
    array $params = []
): void {

    if (headers_sent($arquivo, $linha)) {

        error_log("redirect() depois de os cabeçalhos saírem: $arquivo:$linha");

        die('Não foi possível continuar. Volte e tente novamente.');
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
    // Envio maior que o post_max_size do PHP chega com $_POST vazio: avisa em vez da página crua (nada é gravado)
    if (!$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        flash('O envio ficou grande demais: a foto deve ter no máximo 2 MB.', 'erro');
        redirect((string) ($_GET['page'] ?? 'inicio'));
    }

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


/** Até duas iniciais do nome, para os avatares ("Maria Souza" → "MS") */
function iniciais(?string $nome): string
{
    $partes = array_slice(preg_split('/\s+/', trim((string) $nome), -1, PREG_SPLIT_NO_EMPTY), 0, 2);
    return $partes ? mb_strtoupper(implode('', array_map(fn($p) => mb_substr($p, 0, 1), $partes))) : '?';
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
