<?php

$rotas = require BASE_PATH . '/config/routes.php';

?>

<!DOCTYPE html>

<html lang="pt-BR">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1">

    <?php $rotulo = $rotas[$_GET['page'] ?? ''][0] ?? null; ?>

    <title>
        <?= e($rotulo ? "$rotulo · " : '') ?><?= e(config('app_nome')) ?>
    </title>

    <link
        rel="stylesheet"
        href="assets/css/style.css">

</head>

<body>

    <header class="topo">

        <a
            class="marca"
            href="index.php">
            <?= e(config('app_nome')) ?>
        </a>

        <nav>

            <?php foreach ($rotas as $chave => [$rotulo, $perfis]): ?>

                <?php if ($rotulo === null || !temPerfil($perfis)) continue; ?>

                <a
                    href="index.php?page=<?= $chave ?>"
                    class="<?= ($_GET['page'] ?? '') === $chave ? 'ativo' : '' ?>">
                    <?= e($rotulo) ?>
                    <?php if ($chave === 'estoque' && temPerfil(['admin', 'estoque']) && ($nAlertas = Estoque::contarAlertas())): ?>
                        <span class="aviso-menu" title="Alertas de validade e estoque baixo"><?= $nAlertas ?></span>
                    <?php endif; ?>
                </a>

            <?php endforeach; ?>

        </nav>

        <div class="usuario">

            <?= e(usuario()['nome']) ?>

            ·

            <form method="post" action="index.php?page=logout" class="form-sair">
                <?= csrf_campo() ?>
                <button class="link-sair">Sair</button>
            </form>

        </div>

    </header>

    <main>

        <?php foreach (flash() as [$msg, $tipo]): ?>

            <div class="aviso aviso-<?= e($tipo) ?>">
                <?= e($msg) ?>
            </div>

        <?php endforeach; ?>