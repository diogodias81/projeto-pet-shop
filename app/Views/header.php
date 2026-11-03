<?php

$rotas = require BASE_PATH . '/config/routes.php';

$atual = $_GET['page'] ?? '';

$icones = [
    'dashboard' => 'speedometer2', 'vendas' => 'cart3', 'historico' => 'receipt',
    'produtos' => 'box-seam', 'estoque' => 'boxes', 'categorias' => 'tags',
    'despesas' => 'wallet2', 'relatorios' => 'bar-chart-line', 'usuarios' => 'people',
    'auditoria' => 'shield-check',
];

// Itens do menu que este perfil pode ver
$menu = [];

foreach ($rotas as $chave => [$rotulo, $perfis]) {

    if ($rotulo !== null && temPerfil($perfis)) {
        $menu[$chave] = $rotulo;
    }
}

$nAlertas = isset($menu['estoque']) && temPerfil(['admin', 'estoque']) ? Estoque::contarAlertas() : 0;

$tiposAviso = ['ok' => 'success', 'erro' => 'danger', 'alerta' => 'warning'];

// Um item do menu (lateral, barra inferior e "Mais" usam o mesmo)
$item = function (string $chave, string $classe) use ($menu, $icones, $atual, $nAlertas): string {

    $ativo = $atual === $chave;

    $selo = $chave === 'estoque' && $nAlertas
        ? '<span class="badge rounded-pill text-bg-warning" title="Alertas de validade e estoque baixo">' . $nAlertas . '</span>'
        : '';

    return '<a href="index.php?page=' . $chave . '" class="' . $classe . ($ativo ? ' active' : '') . '"'
        . ($ativo ? ' aria-current="page"' : '') . '>'
        . '<i class="bi bi-' . ($icones[$chave] ?? 'circle') . '" aria-hidden="true"></i>'
        . '<span>' . e($menu[$chave]) . '</span>' . $selo . '</a>';
};

// Menu agrupado por assunto (só entram os grupos com algum item que o perfil vê)
$grupos = array_filter(array_map(
    fn($chaves) => array_values(array_filter($chaves, fn($c) => isset($menu[$c]))),
    ['Operação' => ['dashboard', 'vendas', 'historico'], 'Catálogo' => ['produtos', 'estoque', 'categorias'],
     'Gestão' => ['despesas', 'relatorios'], 'Administração' => ['usuarios', 'auditoria']]
));

$u = usuario();
$iniciais = mb_strtoupper(implode('', array_map(fn($p) => mb_substr($p, 0, 1), array_slice(preg_split('/\s+/', trim($u['nome'])), 0, 2))));
$perfilNome = Usuario::PERFIS[$u['perfil']] ?? '';

$minhaSenha = '<a class="btn ' . ($atual === 'minha_senha' ? 'btn-primary' : 'btn-light') . ' w-100" href="index.php?page=minha_senha"'
    . ($atual === 'minha_senha' ? ' aria-current="page"' : '') . '><i class="bi bi-key" aria-hidden="true"></i> Minha senha</a>';

$sair = '<form method="post" action="index.php?page=logout" class="m-0">' . csrf_campo()
    . '<button class="btn btn-light w-100"><i class="bi bi-box-arrow-right" aria-hidden="true"></i> Sair</button></form>';

?>

<!DOCTYPE html>

<html lang="pt-BR">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1, viewport-fit=cover">

    <title>
        <?php // páginas fora do menu (rótulo null) também ganham nome na aba ?>
        <?php $tituloAba = $menu[$atual] ?? ['comprovante' => 'Comprovante', 'minha_senha' => 'Minha senha'][$atual] ?? null; ?>
        <?= e($tituloAba ? "$tituloAba · " : '') ?><?= e(config('app_nome')) ?>
    </title>

    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">

    <link rel="stylesheet" href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css">

    <link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">

</head>

<body>

    <!-- Teclado: pula as 13 paradas do menu direto para a tela -->
    <a class="visually-hidden-focusable btn btn-primary pular" href="#conteudo">Pular para o conteúdo</a>

    <!-- Computador: menu lateral -->
    <aside class="lateral d-none d-lg-flex d-print-none">

        <a class="marca" href="index.php">
            <span class="marca-icone" aria-hidden="true">🐾</span>
            <?= e(config('app_nome')) ?>
        </a>

        <?php if (isset($menu['vendas'])): ?>
            <a class="btn btn-primary lateral-cta" href="index.php?page=vendas"><i class="bi bi-plus-lg" aria-hidden="true"></i> Nova venda</a>
        <?php endif; ?>

        <nav class="lateral-nav" aria-label="Menu principal">
            <?php foreach ($grupos as $titulo => $chaves): ?>
                <div class="lateral-grupo">
                    <span class="lateral-titulo"><?= e($titulo) ?></span>
                    <?php foreach ($chaves as $chave): ?>
                        <?= $item($chave, 'nav-link') ?>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </nav>

        <div class="lateral-usuario">
            <span class="avatar" aria-hidden="true"><?= e($iniciais) ?></span>
            <div class="lateral-quem">
                <strong><?= e($u['nome']) ?></strong>
                <small><?= e($perfilNome) ?></small>
            </div>
            <a class="btn-icone<?= $atual === 'minha_senha' ? ' active' : '' ?>" href="index.php?page=minha_senha" title="Minha senha" aria-label="Minha senha"<?= $atual === 'minha_senha' ? ' aria-current="page"' : '' ?>><i class="bi bi-key" aria-hidden="true"></i></a>
            <form method="post" action="index.php?page=logout" class="m-0"><?= csrf_campo() ?>
                <button class="btn-icone" title="Sair" aria-label="Sair"><i class="bi bi-box-arrow-right" aria-hidden="true"></i></button>
            </form>
        </div>

    </aside>

    <!-- Celular: topo com o nome da tela -->
    <header class="topo-movel d-lg-none d-print-none">
        <a class="marca" href="index.php"><span class="marca-icone" aria-hidden="true">🐾</span> <?= e(config('app_nome')) ?></a>
        <span class="avatar avatar-sm" title="<?= e($u['nome'] . ' · ' . $perfilNome) ?>" aria-hidden="true"><?= e($iniciais) ?></span>
    </header>

    <main class="conteudo" id="conteudo" tabindex="-1">

        <?php if (!in_array($atual, ['dashboard', 'comprovante'], true) && $tituloAba): // o dashboard tem saudação própria ?>
            <header class="pagina-topo d-print-none">
                <h1><?= e($tituloAba) ?></h1>
            </header>
        <?php endif; ?>

        <?php foreach (flash() as [$msg, $tipo]): ?>

            <div class="alert alert-<?= $tiposAviso[$tipo] ?? 'info' ?>" role="alert">
                <?= e($msg) ?>
            </div>

        <?php endforeach; ?>
