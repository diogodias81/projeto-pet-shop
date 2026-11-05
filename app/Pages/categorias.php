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

$subtituloTopo = count($categorias) . (count($categorias) === 1 ? ' categoria' : ' categorias') . ' · organize os produtos para filtrar e achar mais rápido';

?>

<section class="card card-body mb-3">

  <form autocomplete="off" method="post" class="nova-categoria">

    <?= csrf_campo() ?>

    <label>
      <span class="visually-hidden">Nova categoria</span>
      <i class="bi bi-tag" aria-hidden="true"></i>

      <input
        name="nome"
        required
        maxlength="60"
        aria-label="Nova categoria"
        placeholder="Nome da nova categoria (ex.: Rações)">
    </label>

    <button class="btn btn-primary">
      <i class="bi bi-plus-lg" aria-hidden="true"></i> Cadastrar
    </button>

  </form>

</section>

<?php if (!$categorias): ?>

  <section class="card card-body">
    <div class="estado-vazio">
      <span class="estado-vazio-icone"><i class="bi bi-tags" aria-hidden="true"></i></span>
      <strong>Nenhuma categoria ainda</strong>
      <p>Crie categorias como Rações, Petiscos e Higiene para organizar os produtos.</p>
    </div>
  </section>

<?php else: ?>

  <div class="tiles">

    <?php foreach ($categorias as $c): ?>

      <article class="tile">

        <a class="tile-link" href="index.php?page=produtos&amp;cat=<?= $c['id'] ?>">
          <span class="icone-redondo" aria-hidden="true"><i class="bi bi-tag"></i></span>
          <span class="tile-texto">
            <strong><?= e($c['nome']) ?></strong>
            <small><?= $c['qtd_produtos'] ?> <?= (int) $c['qtd_produtos'] === 1 ? 'produto' : 'produtos' ?></small>
          </span>
        </a>

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

          <button class="btn btn-outline-danger btn-sm btn-quadrado" title="Excluir" aria-label="Excluir a categoria <?= e($c['nome']) ?>">
            <i class="bi bi-trash3" aria-hidden="true"></i>
          </button>

        </form>

      </article>

    <?php endforeach; ?>

  </div>

<?php endif; ?>
