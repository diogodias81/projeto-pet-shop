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
  }

  redirect('minha_senha');
}

$subtituloTopo = 'Troque a senha que você usa para entrar no sistema.';

?>

<section class="card card-body cartao-estreito">

  <div class="cartao-cabeca">
    <span class="icone-redondo" aria-hidden="true"><i class="bi bi-shield-lock"></i></span>
    <div>
      <h2>Alterar senha</h2>
      <p class="card-sub" id="senha-ajuda">Pelo menos <?= Usuario::SENHA_MIN ?> caracteres (uma frase curta funciona bem). São recusadas: senhas comuns (senha123), só números, sequências (123456, abcdef, qwerty), caracteres repetidos e seu nome ou e-mail.</p>
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
        maxlength="1024"
        autocomplete="current-password"
        data-revelar
        required
        autofocus>
    </label>

    <label>
      Nova senha

      <input
        type="password"
        name="senha"
        aria-describedby="senha-ajuda"
        minlength="<?= Usuario::SENHA_MIN ?>"
        maxlength="1024"
        autocomplete="new-password"
        data-revelar
        required>
    </label>

    <label>
      Confirme a nova senha

      <input
        type="password"
        name="confirmacao"
        minlength="<?= Usuario::SENHA_MIN ?>"
        maxlength="1024"
        autocomplete="new-password"
        data-revelar
        required>
    </label>

    <button class="btn btn-primary btn-lg">
      Salvar nova senha
    </button>

  </form>

</section>
