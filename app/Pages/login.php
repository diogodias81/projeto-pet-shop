<?php

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  csrf_validar();

  try {

    $u = Usuario::autenticar(
      $_POST['email'] ?? '',
      $_POST['senha'] ?? '',
      $_SERVER['REMOTE_ADDR'] ?? ''
    );

    $erro = 'E-mail ou senha incorretos.';
  } catch (DomainException $ex) {

    $u = null;
    $erro = $ex->getMessage();
  }

  if ($u) {

    session_regenerate_id(true);
    $_SESSION['csrf'] = bin2hex(random_bytes(16));

    $_SESSION['user'] = [
      'id' => (int) $u['id'],
      'nome' => $u['nome'],
      'perfil' => $u['perfil'],
      'sessao_versao' => (int) $u['sessao_versao']
    ];

    redirect('inicio');
  }
}

?>

<!DOCTYPE html>

<html lang="pt-BR">

<head>

  <meta charset="UTF-8">

  <meta
    name="viewport"
    content="width=device-width, initial-scale=1">

  <title>
    Entrar · <?= e(config('app_nome')) ?>
  </title>

  <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">

  <link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">

</head>

<body class="login">

  <form method="post" class="login-caixa card card-body">

    <div class="login-marca" aria-hidden="true">🐾</div>

    <h1>
      <?= e(config('app_nome')) ?>
    </h1>

    <p class="text-body-secondary mb-0">Entre para começar o dia.</p>

    <?php if ($erro): ?>

      <div class="alert alert-danger">
        <?= e($erro) ?>
      </div>

    <?php endif; ?>

    <?= csrf_campo() ?>

    <label>
      E-mail

      <input
        type="email"
        name="email"
        value="<?= e($_POST['email'] ?? '') ?>"
        required
        autofocus>
    </label>

    <label>
      Senha

      <input
        type="password"
        name="senha"
        required>
    </label>

    <button class="btn btn-primary">
      Entrar
    </button>

  </form>

</body>

</html>
