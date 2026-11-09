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
      <p class="card-sub">Use pelo menos 6 caracteres. Senhas óbvias (123456, senha123, seu e-mail) são recusadas; evite também datas e nomes.</p>
    </div>
  </div>

  <form method="post" class="form-pilha">

    <?= csrf_campo() ?>

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
        minlength="6"
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
        minlength="6"
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
