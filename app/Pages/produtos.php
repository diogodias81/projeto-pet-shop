<?php

$gerencia = podeGerirEstoque();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  exigirPerfil(['admin', 'estoque']);

  try {

    if (($_POST['acao'] ?? '') === 'excluir') {

      Produto::excluir((int) $_POST['id']);

      flash('Produto excluído.');
    } else {

      Produto::salvar($_POST, $_FILES['foto'] ?? null);

      flash('Produto salvo.');
    }
  } catch (DomainException $ex) {

    flash($ex->getMessage(), 'erro');

    // devolve o que foi digitado ao formulário (a foto o navegador não deixa repor)
    if (($_POST['acao'] ?? '') !== 'excluir') {
      $_SESSION['form_produto'] = $_POST;
      redirect('produtos', (int) ($_POST['id'] ?? 0) ? ['editar' => (int) $_POST['id']] : []);
    }
  }

  redirect('produtos');
}

$antigo = $_SESSION['form_produto'] ?? [];
unset($_SESSION['form_produto']);

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

  <section class="card card-body mb-3">

    <h2>
      <?= $editando ? 'Editar produto' : 'Novo produto' ?>
    </h2>

    <form method="post" class="linha" enctype="multipart/form-data">

      <?= csrf_campo() ?>

      <input
        type="hidden"
        name="id"
        value="<?= $editando['id'] ?? '' ?>">

      <label>
        Código de barras

        <input
          name="codigo_barras"
          inputmode="numeric"
          pattern="\d{4,14}"
          maxlength="14"
          title="De 4 a 14 números"
          placeholder="Leia ou digite"
          data-sem-enter
          value="<?= e($antigo['codigo_barras'] ?? $editando['codigo_barras'] ?? '') ?>">
      </label>

      <label>
        Nome

        <input
          name="nome"
          required
          maxlength="120"
          value="<?= e($antigo['nome'] ?? $editando['nome'] ?? '') ?>">
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
              <?= ($antigo['categoria_id'] ?? $editando['categoria_id'] ?? 0) == $c['id'] ? 'selected' : '' ?>>
              <?= e($c['nome']) ?>
            </option>

          <?php endforeach; ?>

        </select>

      </label>

      <label>
        Preço de custo (R$)

        <input
          name="preco_custo"
          inputmode="decimal"
          placeholder="0,00"
          value="<?= e($antigo['preco_custo'] ?? ($editando && $editando['preco_custo'] > 0 ? number_format($editando['preco_custo'], 2, ',', '') : '')) ?>">
      </label>

      <label>
        Preço de venda (R$)

        <input
          name="preco"
          required
          inputmode="decimal"
          value="<?= e($antigo['preco'] ?? ($editando ? number_format($editando['preco'], 2, ',', '') : '')) ?>">
      </label>

      <?php if (!$editando): ?>

        <label>
          Estoque inicial

          <input
            name="estoque"
            type="number"
            min="0"
            value="<?= e($antigo['estoque'] ?? '0') ?>">
        </label>

        <label>
          Lote

          <input
            name="lote"
            maxlength="40"
            placeholder="Opcional"
            value="<?= e($antigo['lote'] ?? '') ?>">
        </label>

        <label>
          Validade

          <input
            name="validade"
            type="date"
            min="<?= date('Y-m-d') ?>"
            value="<?= e($antigo['validade'] ?? '') ?>">
        </label>

      <?php endif; ?>

      <label>
        Estoque mínimo

        <input
          name="estoque_minimo"
          type="number"
          min="0"
          value="<?= e($antigo['estoque_minimo'] ?? $editando['estoque_minimo'] ?? 5) ?>">
      </label>

      <label>
        Foto <?= !empty($editando['foto']) ? '(enviar outra substitui)' : '' ?>

        <input
          name="foto"
          type="file"
          accept="image/jpeg,image/png,image/webp"
          data-max-bytes="<?= 2 * 1024 * 1024 ?>">
      </label>

      <?php if (!empty($editando['foto'])): ?>

        <img
          class="foto"
          src="<?= e(Produto::urlFoto($editando['foto'])) ?>"
          alt="Foto atual de <?= e($editando['nome']) ?>">

      <?php endif; ?>

      <button class="btn btn-primary">
        <?= $editando ? 'Salvar alterações' : 'Cadastrar produto' ?>
      </button>

      <?php if ($editando): ?>

        <a
          class="btn btn-light"
          href="index.php?page=produtos">
          Cancelar
        </a>

      <?php endif; ?>

    </form>

  </section>

<?php endif; ?>


<section class="card card-body mb-3">

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
        placeholder="Nome ou código de barras">
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

    <button class="btn btn-light">
      Filtrar
    </button>

  </form>


  <?php if (!$produtos): ?>

    <p class="vazio">
      Nenhum produto encontrado.
    </p>

  <?php else: ?>

    <div class="table-responsive"><table class="table table-hover align-middle">

      <thead>

        <tr>
          <th></th>
          <th>Produto</th>
          <th>Categoria</th>

          <?php if ($gerencia): ?>
            <th class="num">Custo</th>
          <?php endif; ?>

          <th class="num">Preço</th>

          <?php if ($gerencia): ?>
            <th class="num">Lucro por unidade</th>
          <?php endif; ?>

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

              <?php if ($p['foto']): ?>

                <img
                  class="foto"
                  src="<?= e(Produto::urlFoto($p['foto'])) ?>"
                  alt=""
                  loading="lazy">

              <?php else: ?>

                <span class="foto foto-vazia" aria-hidden="true"></span>

              <?php endif; ?>

            </td>

            <td>
              <?= e($p['nome']) ?>

              <?php if ($p['codigo_barras']): ?>
                <small class="codigo"><?= e($p['codigo_barras']) ?></small>
              <?php endif; ?>
            </td>

            <td>
              <?= e($p['categoria'] ?? '—') ?>
            </td>

            <?php if ($gerencia): ?>

              <td class="num">
                <?= $p['preco_custo'] > 0 ? moeda($p['preco_custo']) : '—' ?>
              </td>

            <?php endif; ?>

            <td class="num">
              <?= moeda($p['preco']) ?>
            </td>

            <?php if ($gerencia): ?>

              <?php $margem = Produto::margem($p); ?>

              <td class="num <?= $margem !== null && $margem < 0 ? 'negativo' : '' ?>">
                <?php if ($margem === null): ?>
                  —
                <?php else: ?>
                  <?= moeda($p['preco'] - $p['preco_custo']) ?> · <?= number_format($margem, 1, ',', '.') ?>% de margem
                  <br><small class="codigo"><?= number_format(($p['preco'] - $p['preco_custo']) / $p['preco_custo'] * 100, 1, ',', '.') ?>% sobre o custo</small>
                <?php endif; ?>
              </td>

            <?php endif; ?>

            <td class="num">

              <span
                class="selo <?= $baixo ? 'selo-alerta' : 'selo-ok' ?>">
                <?= $p['estoque'] ?>
              </span>

              <?php if ($p['estoque'] > $p['vendavel']): ?>
                <small class="selo selo-erro" title="Unidades vencidas"><?= $p['estoque'] - $p['vendavel'] ?> venc.</small>
              <?php endif; ?>

            </td>

            <?php if ($gerencia): ?>

              <td class="acoes">

                <a
                  class="btn btn-light btn-sm"
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

                  <button class="btn btn-outline-danger btn-sm">
                    Excluir
                  </button>

                </form>

              </td>

            <?php endif; ?>

          </tr>

        <?php endforeach; ?>

      </tbody>

    </table></div>

  <?php endif; ?>

</section>
