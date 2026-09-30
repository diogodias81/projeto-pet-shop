  <?php $rotas = require BASE_PATH . '/config/routes.php'; ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($titulo) ?> · <?= e(config('app_nome')) ?></title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<header class="topo">
  <a class="marca" href="index.php"><?= e(config('app_nome')) ?></a>
  <nav>
    <?php foreach ($rotas as $chave => [$rotulo, $perfis]): if (!temPerfil($perfis)) continue; ?>
      <a href="index.php?page=<?= $chave ?>" class="<?= ($_GET['page'] ?? '') === $chave ? 'ativo' : '' ?>"><?= e($rotulo) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="usuario"><?= e(usuario()['nome']) ?> · <a href="index.php?page=logout">Sair</a></div>
</header>
<main>
  <h1><?= e($titulo) ?></h1>
  <?php foreach (flash() as [$msg, $tipo]): ?>
    <div class="aviso aviso-<?= e($tipo) ?>"><?= e($msg) ?></div>
  <?php endforeach; ?>
