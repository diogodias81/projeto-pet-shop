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

?>

<section class="card card-body mb-3">

  <h2>Minha senha</h2>

  <form method="post" class="linha">

    <?= csrf_campo() ?>

    <label>
      Senha atual

      <input
        type="password"
        name="atual"
        maxlength="1024"
        autocomplete="current-password"
        required
        autofocus>
    </label>

    <label>
      Nova senha

      <input
        type="password"
        name="senha"
        minlength="6"
        maxlength="1024"
        autocomplete="new-password"
        required>
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

    <button class="btn btn-primary">
      Salvar nova senha
    </button>

  </form>

</section>
