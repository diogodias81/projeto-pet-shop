<?php
$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $u = Usuario::autenticar($_POST['email'] ?? '', $_POST['senha'] ?? '');
    if ($u) {
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => (int)$u['id'], 'nome' => $u['nome'], 'perfil' => $u['perfil']];
        redirect('inicio');
    }
    $erro = 'E-mail ou senha incorretos.';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Entrar · <?= e(config('app_nome')) ?></title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login">
  <form method="post" class="login-caixa">
    <h1><?= e(config('app_nome')) ?></h1>
    <?php if ($erro): ?><div class="aviso aviso-erro"><?= e($erro) ?></div><?php endif; ?>
    <?= csrf_campo() ?>
    <label>E-mail <input type="email" name="email" required autofocus></label>
    <label>Senha <input type="password" name="senha" required></label>
    <button class="btn">Entrar</button>
  </form>
</body>
</html>
