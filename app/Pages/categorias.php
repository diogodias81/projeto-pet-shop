
<?php

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  try {

    if (($_POST['acao'] ?? '') === 'excluir') {

      Categoria::excluir((int) $_POST['id']);

      flash('Categoria excluída.');
    } else {

      Categoria::criar($_POST['nome'] ?? '');

      flash('Categoria cadastrada.');
    }
  } catch (DomainException $ex) {

    flash($ex->getMessage(), 'erro');
  }

  redirect('categorias');
}

$categorias = Categoria::listar();

?>

<section class="painel">

  <form method="post" class="linha">

    <?= csrf_campo() ?>

    <label>
      Nova categoria

      <input
        name="nome"
        required
        maxlength="60">
    </label>

    <button class="btn">
      Cadastrar
    </button>

  </form>

</section>

<section class="painel">

  <table>

    <thead>

      <tr>
        <th>Categoria</th>
        <th class="num">Produtos</th>
        <th></th>
      </tr>

    </thead>

    <tbody>

      <?php foreach ($categorias as $c): ?>

        <tr>

          <td>
            <?= e($c['nome']) ?>
          </td>

          <td class="num">
            <?= $c['qtd_produtos'] ?>
          </td>

          <td class="acoes">

            <form
              method="post"
              data-confirmar="Excluir a categoria <?= e($c['nome']) ?>?">

              <?= csrf_campo() ?>

              <input
                type="hidden"
                name="acao"
                value="excluir">

              <input
                type="hidden"
                name="id"
                value="<?= $c['id'] ?>">

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
