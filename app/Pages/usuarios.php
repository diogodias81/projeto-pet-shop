<?php

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  try {

    if (($_POST['acao'] ?? '') === 'excluir') {

      Usuario::excluir(
        (int) $_POST['id']
      );

      flash('Usuário excluído.');
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

<section class="painel">

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

    <button class="btn">
      Criar usuário
    </button>

  </form>

</section>


<section class="painel">

  <table>

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

              <button class="btn btn-perigo btn-pequeno">
                Excluir
              </button>

            </form>

          </td>

        </tr>

      <?php endforeach; ?>

    </tbody>

  </table>

</section>
