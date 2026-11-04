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

    // Volta para a tela onde estava (ex.: sessão expirada no meio do trabalho), se este perfil pode abri-la.
    // Só telas do menu: as que dependem de um id (comprovante) vão para o início.
    $voltar = $_SESSION['voltar'] ?? null;
    unset($_SESSION['voltar']);

    redirect(is_string($voltar) && isset($rotas[$voltar]) && $rotas[$voltar][0] !== null && temPerfil($rotas[$voltar][1]) ? $voltar : 'inicio');
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

  <link rel="icon" href="favicon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">

  <link rel="stylesheet" href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css">

  <link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">

</head>

<body class="login">

  <main class="login-lado-form">

  <form method="post" class="login-caixa">

    <div class="login-cabeca">
      <span class="marca-icone" aria-hidden="true">🐾</span>
      <span class="login-nome"><?= e(config('app_nome')) ?></span>
    </div>

    <div>
      <h1>Bem-vindo de volta</h1>
      <p class="text-body-secondary mb-0">Entre com seu e-mail e senha para começar o dia.</p>
    </div>

    <?php foreach (flash() as [$msg]): ?>
      <div class="alert alert-warning" role="alert"><?= e($msg) ?></div>
    <?php endforeach; ?>

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
        autocomplete="username"
        required
        <?= $erro ? '' : 'autofocus' ?>>
    </label>

    <label>
      Senha

      <?php // errou: o e-mail continua preenchido, então o cursor já vai para a senha ?>
      <input
        type="password"
        name="senha"
        autocomplete="current-password"
        data-revelar
        required
        <?= $erro ? 'autofocus' : '' ?>>
    </label>

    <button class="btn btn-primary btn-lg">
      Entrar <i class="bi bi-arrow-right" aria-hidden="true"></i>
    </button>

    <p class="login-rodape">Esqueceu a senha? Peça ao administrador para redefinir.</p>

  </form>

  </main>

  <aside class="login-vitrine" aria-hidden="true">
    <div class="login-vitrine-conteudo">
      <span class="login-pata">🐾</span>
      <h2>Sua loja inteira<br>num só lugar.</h2>
      <p>Vendas, estoque com validade, despesas e resultados — tudo organizado para o dia a dia do pet shop.</p>
      <ul>
        <li><i class="bi bi-upc-scan"></i> Caixa rápido com leitor de código de barras</li>
        <li><i class="bi bi-hourglass-split"></i> Alerta de lotes vencendo e estoque baixo</li>
        <li><i class="bi bi-graph-up-arrow"></i> Faturamento, lucro e ticket médio na hora</li>
      </ul>
    </div>
  </aside>

  <script src="<?= asset('assets/js/app.js') ?>"></script>

</body>

</html>
