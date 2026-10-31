<?php

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  try {

    if (($_POST['acao'] ?? '') === 'excluir') {

      Usuario::excluir(
        (int) $_POST['id']
      );

      flash('Usuário excluído.');
    } elseif (($_POST['acao'] ?? '') === 'redefinir') {

      Usuario::redefinirSenha(
        (int) $_POST['id'],
        $_POST['senha'] ?? ''
      );

      flash('Senha redefinida. O usuário deverá trocá-la no próximo acesso.');
    } else {

      Usuario::criar($_POST);

      flash('Usuário criado.');
    }
  } catch (DomainException $ex) {

    flash(
      $ex->getMessage(),
      'erro'
    );
  }

  redirect('usuarios');
}

?>

<section class="card card-body mb-3">

  <h2>Novo usuário</h2>

  <form method="post" class="linha">

    <?= csrf_campo() ?>

    <label>
      Nome

      <input
        name="nome"
        required>
    </label>

    <label>
      E-mail

      <input
        type="email"
        name="email"
        required>
    </label>

    <label>
      Senha

      <input
        type="password"
        name="senha"
        minlength="6"
        maxlength="1024"
        required>
    </label>

    <label>
      Perfil

      <select name="perfil">

        <?php foreach (Usuario::PERFIS as $k => $v): ?>

          <option value="<?= $k ?>">
            <?= e($v) ?>
          </option>

        <?php endforeach; ?>

      </select>

    </label>

    <button class="btn btn-primary">
      Criar usuário
    </button>

  </form>

</section>


<section class="card card-body mb-3">

  <div class="table-responsive"><table class="table table-hover align-middle">

    <thead>

      <tr>
        <th>Nome</th>
        <th>E-mail</th>
        <th>Perfil</th>
        <th></th>
      </tr>

    </thead>

    <tbody>

      <?php foreach (Usuario::listar() as $u): ?>

        <tr>

          <td>
            <?= e($u['nome']) ?>
          </td>

          <td>
            <?= e($u['email']) ?>
          </td>

          <td>
            <?= e(Usuario::PERFIS[$u['perfil']]) ?>
          </td>

          <td class="acoes">

            <form
              method="post"
              data-confirmar="Redefinir a senha de <?= e($u['nome']) ?>?">

              <?= csrf_campo() ?>

              <input
                type="hidden"
                name="acao"
                value="redefinir">

              <input
                type="hidden"
                name="id"
                value="<?= $u['id'] ?>">

              <input
                type="password"
                name="senha"
                placeholder="Senha provisória"
                aria-label="Senha provisória para <?= e($u['nome']) ?>"
                minlength="6"
                maxlength="1024"
                autocomplete="new-password"
                required>

              <button class="btn btn-light btn-sm">
                Redefinir senha
              </button>

            </form>

            <form
              method="post"
              data-confirmar="Excluir o usuário <?= e($u['nome']) ?>?">

              <?= csrf_campo() ?>

              <input
                type="hidden"
                name="acao"
                value="excluir">

              <input
                type="hidden"
                name="id"
                value="<?= $u['id'] ?>">

              <button class="btn btn-outline-danger btn-sm">
                Excluir
              </button>

            </form>

          </td>

        </tr>

      <?php endforeach; ?>

    </tbody>

  </table></div>

</section>
