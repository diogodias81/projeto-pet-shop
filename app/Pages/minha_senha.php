<?php

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  try {

    Usuario::alterarSenha(
      usuario()['id'],
      $_POST['atual'] ?? '',
      $_POST['senha'] ?? '',
      $_POST['confirmacao'] ?? ''
    );

    session_regenerate_id(true);

    flash('Senha alterada com sucesso.');

    redirect('inicio');
  } catch (DomainException $ex) {

    flash($ex->getMessage(), 'erro');
    // o cursor volta para o campo a corrigir (as senhas nunca voltam preenchidas)
    $_SESSION['foco_senha'] = stripos($ex->getMessage(), 'atual') !== false ? 'atual' : 'senha';
  }

  redirect('minha_senha');
}

$foco = $_SESSION['foco_senha'] ?? 'atual';
unset($_SESSION['foco_senha']);

$subtituloTopo = 'Troque a senha que você usa para entrar no sistema.';

?>

<section class="card card-body cartao-estreito">

  <div class="cartao-cabeca">
    <span class="icone-redondo" aria-hidden="true"><i class="bi bi-shield-lock"></i></span>
    <div>
      <h2>Alterar senha</h2>
      <p class="card-sub" id="senha-ajuda">Pelo menos <?= Usuario::SENHA_MIN ?> caracteres (uma frase curta funciona bem). São recusadas: senhas comuns (senha123, também com 0 no lugar de o ou @ no lugar de a), só números, sequências (123456, abcdef, qwerty), caracteres repetidos, datas e seu nome ou e-mail. Máximo de 72 caracteres (letra com acento conta como 2).</p>
    </div>
  </div>

  <form method="post" class="form-pilha">

    <?= csrf_campo() ?>
    <?php // o gerenciador de senhas do navegador associa a senha nova a esta conta ?>
    <input type="text" name="usuario_email" value="<?= e(usuario()['email'] ?? '') ?>" autocomplete="username" hidden>

    <label>
      Senha atual

      <input
        type="password"
        name="atual"
        maxlength="72"
        autocomplete="current-password"
        data-revelar
        required<?= $foco === 'atual' ? ' autofocus' : '' ?>>
    </label>

    <label>
      Nova senha

      <input
        type="password"
        name="senha"
        aria-describedby="senha-ajuda"
        minlength="<?= Usuario::SENHA_MIN ?>"
        maxlength="72"
        autocomplete="new-password"
        data-revelar
        required<?= $foco === 'senha' ? ' autofocus' : '' ?>>
    </label>

    <label>
      Confirme a nova senha

      <input
        type="password"
        name="confirmacao"
        minlength="<?= Usuario::SENHA_MIN ?>"
        maxlength="72"
        autocomplete="new-password"
        data-revelar
        required>
    </label>

    <button class="btn btn-primary btn-lg">
      Salvar nova senha
    </button>

  </form>

</section>
