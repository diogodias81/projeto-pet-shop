<?php

$erro = '';

// Só o formulário desta tela: POST de outra página (sessão antiga) apenas mostra a troca
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['page'] ?? '') === 'trocar_senha') {

  csrf_validar();

  try {

    Usuario::trocarSenha(
      usuario()['id'],
      $_POST['senha'] ?? '',
      $_POST['confirmacao'] ?? ''
    );

    session_regenerate_id(true);

    redirect('inicio');
  } catch (DomainException $ex) {

    $erro = $ex->getMessage();
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
    Trocar senha · <?= e(config('app_nome')) ?>
  </title>

  <link
    rel="stylesheet"
    href="assets/css/style.css">

</head>

<body class="login">

  <form method="post" action="index.php?page=trocar_senha" class="login-caixa">

    <h1>
      Escolha sua senha
    </h1>

    <p>
      Olá, <?= e(usuario()['nome']) ?>. Você entrou com a senha inicial: troque-a para continuar.
    </p>

    <?php if ($erro): ?>

      <div class="aviso aviso-erro">
        <?= e($erro) ?>
      </div>

    <?php endif; ?>

    <?= csrf_campo() ?>

    <label>
      Nova senha

      <input
        type="password"
        name="senha"
        minlength="6"
        maxlength="1024"
        autocomplete="new-password"
        required
        autofocus>
    </label>

    <label>
      Confirme a nova senha

      <input
        type="password"
        name="confirmacao"
        minlength="6"
        maxlength="1024"
        autocomplete="new-password"
        required>
    </label>

    <button class="btn">
      Salvar e entrar
    </button>

    <!-- Envia o formulário de saída abaixo (formulários não podem ficar um dentro do outro) -->
    <button class="btn btn-claro" form="form-sair">
      Sair
    </button>

  </form>

  <form method="post" action="index.php?page=logout" id="form-sair" hidden>
    <?= csrf_campo() ?>
  </form>

</body>

</html>
