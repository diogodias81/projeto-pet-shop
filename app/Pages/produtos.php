
<?php

$gerencia = podeGerirEstoque();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  exigirPerfil(['admin', 'estoque']);

  try {

    if (($_POST['acao'] ?? '') === 'excluir') {

      Produto::excluir((int) $_POST['id']);

      flash('Produto excluído.');
    } else {

      Produto::salvar($_POST);

      flash('Produto salvo.');
    }
  } catch (DomainException $ex) {

    flash($ex->getMessage(), 'erro');
  }

  redirect('produtos');
}

$editando = null;

if ($gerencia && isset($_GET['editar'])) {

  $editando = Produto::buscar(
    (int) $_GET['editar']
  );
}

$busca = trim($_GET['q'] ?? '');

$catFiltro = (int) ($_GET['cat'] ?? 0);

$categorias = Categoria::listar();

$produtos = Produto::listar(
  $busca,
  $catFiltro
);

?>

<?php if ($gerencia): ?>

  <section class="painel">

    <h2>
      <?= $editando ? 'Editar produto' : 'Novo produto' ?>
    </h2>

    <form method="post" class="linha">

      <?= csrf_campo() ?>

      <input
        type="hidden"
        name="id"
        value="<?= $editando['id'] ?? '' ?>">

      <label>
        Nome

        <input
          name="nome"
          required
          maxlength="120"
          value="<?= e($editando['nome'] ?? '') ?>">
      </label>

      <label>
        Categoria

        <select name="categoria_id">

          <option value="">
            Sem categoria
          </option>

          <?php foreach ($categorias as $c): ?>

            <option
              value="<?= $c['id'] ?>"
              <?= ($editando['categoria_id'] ?? 0) == $c['id'] ? 'selected' : '' ?>>
              <?= e($c['nome']) ?>
            </option>

          <?php endforeach; ?>

        </select>

      </label>

      <label>
        Preço (R$)

        <input
          name="preco"
          required
          inputmode="decimal"
          value="<?= $editando ? number_format($editando['preco'], 2, ',', '') : '' ?>">
      </label>

      <?php if (!$editando): ?>

        <label>
          Estoque inicial

          <input
            name="estoque"
            type="number"
            min="0"
            value="0">
        </label>

      <?php endif; ?>

      <label>
        Estoque mínimo

        <input
          name="estoque_minimo"
          type="number"
          min="0"
          value="<?= $editando['estoque_minimo'] ?? 5 ?>">
      </label>

      <button class="btn">
        <?= $editando ? 'Salvar alterações' : 'Cadastrar produto' ?>
      </button>

      <?php if ($editando): ?>

        <a
          class="btn btn-claro"
          href="index.php?page=produtos">
          Cancelar
        </a>

      <?php endif; ?>

    </form>

  </section>

<?php endif; ?>


<section class="painel">

  <form method="get" class="linha">

    <input
      type="hidden"
      name="page"
      value="produtos">

    <label>
      Pesquisar

      <input
        type="search"
        name="q"
        value="<?= e($busca) ?>"
        placeholder="Nome do produto">
    </label>

    <label>
      Categoria

      <select name="cat">

        <option value="0">
          Todas
        </option>

        <?php foreach ($categorias as $c): ?>

          <option
            value="<?= $c['id'] ?>"
            <?= $catFiltro == $c['id'] ? 'selected' : '' ?>>
            <?= e($c['nome']) ?>
          </option>

        <?php endforeach; ?>

      </select>

    </label>

    <button class="btn btn-claro">
      Filtrar
    </button>

  </form>


  <?php if (!$produtos): ?>

    <p class="vazio">
      Nenhum produto encontrado.
    </p>

  <?php else: ?>

    <table>

      <thead>

        <tr>
          <th>Produto</th>
          <th>Categoria</th>
          <th class="num">Preço</th>
          <th class="num">Estoque</th>

          <?php if ($gerencia): ?>
            <th></th>
          <?php endif; ?>

        </tr>

      </thead>

      <tbody>

        <?php foreach ($produtos as $p): ?>

          <?php
          $baixo = $p['estoque'] <= $p['estoque_minimo'];
          ?>

          <tr>

            <td>
              <?= e($p['nome']) ?>
            </td>

            <td>
              <?= e($p['categoria'] ?? '—') ?>
            </td>

            <td class="num">
              <?= moeda($p['preco']) ?>
            </td>

            <td class="num">

              <span
                class="selo <?= $baixo ? 'selo-alerta' : 'selo-ok' ?>">
                <?= $p['estoque'] ?>
              </span>

            </td>

            <?php if ($gerencia): ?>

              <td class="acoes">

                <a
                  class="btn btn-claro btn-pequeno"
                  href="index.php?page=produtos&editar=<?= $p['id'] ?>">
                  Editar
                </a>

                <form
                  method="post"
                  data-confirmar="Excluir <?= e($p['nome']) ?>?">

                  <?= csrf_campo() ?>

                  <input
                    type="hidden"
                    name="acao"
                    value="excluir">

                  <input
                    type="hidden"
                    name="id"
                    value="<?= $p['id'] ?>">

                  <button class="btn btn-perigo btn-pequeno">
                    Excluir
                  </button>

                </form>

              </td>

            <?php endif; ?>

          </tr>

        <?php endforeach; ?>

      </tbody>

    </table>

  <?php endif; ?>

</section>
