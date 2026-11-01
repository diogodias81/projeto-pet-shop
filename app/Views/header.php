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

$minhaSenha = '<a class="btn ' . ($atual === 'minha_senha' ? 'btn-primary' : 'btn-light') . ' w-100 mb-2" href="index.php?page=minha_senha"'
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

        <nav class="nav flex-column" aria-label="Menu principal">
            <?php foreach (array_keys($menu) as $chave): ?>
                <?= $item($chave, 'nav-link') ?>
            <?php endforeach; ?>
        </nav>

        <div class="lateral-usuario">
            <div class="small text-body-secondary">Conectado como</div>
            <strong><?= e(usuario()['nome']) ?></strong>
            <?= $minhaSenha ?>
            <?= $sair ?>
        </div>

    </aside>

    <!-- Celular: topo com o nome da tela -->
    <header class="topo-movel d-lg-none d-print-none">
        <a class="marca" href="index.php"><span class="marca-icone" aria-hidden="true">🐾</span> <?= e(config('app_nome')) ?></a>
        <span class="text-body-secondary small"><?= e($tituloAba ?? '') ?></span>
    </header>

    <main class="conteudo" id="conteudo" tabindex="-1">

        <?php foreach (flash() as [$msg, $tipo]): ?>

            <div class="alert alert-<?= $tiposAviso[$tipo] ?? 'info' ?>" role="alert">
                <?= e($msg) ?>
            </div>

        <?php endforeach; ?>
