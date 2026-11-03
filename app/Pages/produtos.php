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
      redirect('produtos', (int) ($_POST['id'] ?? 0) ? ['editar' => (int) $_POST['id']] : ['novo' => 1]);
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

// A gaveta do formulário já abre quando: editando, voltou com erro ou pediu "novo"
$abrirGaveta = $gerencia && ($editando || $antigo || isset($_GET['novo']));

$subtituloTopo = count($produtos) . (count($produtos) === 1 ? ' produto' : ' produtos') . ($busca !== '' || $catFiltro ? ' encontrados' : ' cadastrados');

if ($gerencia) {
  // Editando: "Novo produto" recarrega a página com o formulário vazio
  $acoesTopo = $editando
    ? '<a class="btn btn-primary" href="index.php?page=produtos&amp;novo=1"><i class="bi bi-plus-lg" aria-hidden="true"></i> Novo produto</a>'
    : '<button class="btn btn-primary" type="button" data-bs-toggle="offcanvas" data-bs-target="#gaveta-produto" aria-controls="gaveta-produto"><i class="bi bi-plus-lg" aria-hidden="true"></i> Novo produto</button>';
}

$linkCat = fn(int $cat) => 'index.php?page=produtos' . ($cat ? '&amp;cat=' . $cat : '') . ($busca !== '' ? '&amp;q=' . urlencode($busca) : '');

?>

<?php if ($gerencia): ?>

  <div
    class="offcanvas offcanvas-end gaveta"
    tabindex="-1"
    id="gaveta-produto"
    aria-labelledby="gaveta-produto-titulo"
    <?= $abrirGaveta ? 'data-abrir' : '' ?>>

    <div class="offcanvas-header">
      <div>
        <h2 class="offcanvas-title" id="gaveta-produto-titulo">
          <?= $editando ? 'Editar produto' : 'Novo produto' ?>
        </h2>
        <?php if ($editando): ?><p class="card-sub"><?= e($editando['nome']) ?></p><?php endif; ?>
      </div>
      <?php if ($editando): ?>
        <a class="btn-close" href="index.php?page=produtos" aria-label="Fechar"></a>
      <?php else: ?>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Fechar"></button>
      <?php endif; ?>
    </div>

    <form method="post" class="offcanvas-body gaveta-form" enctype="multipart/form-data">

      <?= csrf_campo() ?>

      <input
        type="hidden"
        name="id"
        value="<?= $editando['id'] ?? '' ?>">

      <fieldset>
        <legend>Identificação</legend>

        <div class="foto-campo">
          <?php if (!empty($editando['foto'])): ?>
            <img
              class="foto foto-g"
              src="<?= e(Produto::urlFoto($editando['foto'])) ?>"
              alt="Foto atual de <?= e($editando['nome']) ?>">
          <?php else: ?>
            <span class="foto foto-g foto-vazia" aria-hidden="true"><i class="bi bi-image"></i></span>
          <?php endif; ?>

          <label>
            Foto <?= !empty($editando['foto']) ? '<small>enviar outra substitui</small>' : '<small>JPG, PNG ou WEBP até 2 MB</small>' ?>

            <input
              name="foto"
              type="file"
              accept="image/jpeg,image/png,image/webp"
              data-max-bytes="<?= 2 * 1024 * 1024 ?>">
          </label>
        </div>

        <label>
          Nome

          <input
            name="nome"
            required
            maxlength="120"
            value="<?= e($antigo['nome'] ?? $editando['nome'] ?? '') ?>">
        </label>

        <div class="campos-2">
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
        </div>
      </fieldset>

      <fieldset>
        <legend>Preço</legend>

        <div class="campos-2">
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
        </div>
      </fieldset>

      <fieldset>
        <legend>Estoque</legend>

        <?php if (!$editando): ?>

          <div class="campos-2">
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
          </div>

        <?php endif; ?>

        <div class="campos-2">
          <?php if (!$editando): ?>

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
        </div>

        <?php if ($editando): ?>
          <p class="nota"><i class="bi bi-info-circle" aria-hidden="true"></i> Para mudar a quantidade, registre uma entrada ou baixa na tela de Estoque.</p>
        <?php endif; ?>
      </fieldset>

      <div class="gaveta-rodape">

        <?php if ($editando): ?>
          <a
            class="btn btn-light"
            href="index.php?page=produtos">
            Cancelar
          </a>
        <?php else: ?>
          <button type="button" class="btn btn-light" data-bs-dismiss="offcanvas">Cancelar</button>
        <?php endif; ?>

        <button class="btn btn-primary">
          <?= $editando ? 'Salvar alterações' : 'Cadastrar produto' ?>
        </button>

      </div>

    </form>

  </div>

<?php endif; ?>


<section class="card card-body mb-3">

  <form method="get" class="busca-barra" role="search">

    <input
      type="hidden"
      name="page"
      value="produtos">

    <?php if ($catFiltro): ?>
      <input type="hidden" name="cat" value="<?= $catFiltro ?>">
    <?php endif; ?>

    <label>
      <span class="visually-hidden">Pesquisar</span>
      <i class="bi bi-search" aria-hidden="true"></i>

      <input
        type="search"
        name="q"
        value="<?= e($busca) ?>"
        aria-label="Pesquisar"
        placeholder="Buscar por nome ou código de barras">
    </label>

    <button class="btn btn-light">
      Buscar
    </button>

  </form>

  <?php if ($categorias): ?>
    <nav class="chips" aria-label="Filtrar por categoria">
      <a class="chip <?= !$catFiltro ? 'active' : '' ?>" href="<?= $linkCat(0) ?>"<?= !$catFiltro ? ' aria-current="true"' : '' ?>>Todas</a>
      <?php foreach ($categorias as $c): ?>
        <a class="chip <?= $catFiltro == $c['id'] ? 'active' : '' ?>" href="<?= $linkCat((int) $c['id']) ?>"<?= $catFiltro == $c['id'] ? ' aria-current="true"' : '' ?>><?= e($c['nome']) ?></a>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>


  <?php if (!$produtos): ?>

    <div class="estado-vazio">
      <span class="estado-vazio-icone"><i class="bi bi-box-seam" aria-hidden="true"></i></span>
      <strong>Nenhum produto encontrado</strong>
      <p><?= $busca !== '' || $catFiltro ? 'Tente outro nome, código ou categoria.' : 'Cadastre o primeiro produto para começar a vender.' ?></p>
    </div>

  <?php else: ?>

    <div class="table-responsive"><table class="table table-hover align-middle">

      <thead>

        <tr>
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
            <th><span class="visually-hidden">Ações</span></th>
          <?php endif; ?>

        </tr>

      </thead>

      <tbody>

        <?php foreach ($produtos as $p): ?>

          <?php
          $baixo = $p['estoque'] <= $p['estoque_minimo'];
          ?>

          <tr<?= $editando && (int) $editando['id'] === (int) $p['id'] ? ' class="linha-ativa"' : '' ?>>

            <td>
              <span class="produto-celula">

                <?php if ($p['foto']): ?>

                  <img
                    class="foto"
                    src="<?= e(Produto::urlFoto($p['foto'])) ?>"
                    alt=""
                    loading="lazy">

                <?php else: ?>

                  <span class="foto foto-vazia" aria-hidden="true"><i class="bi bi-image"></i></span>

                <?php endif; ?>

                <span>
                  <?= e($p['nome']) ?>

                  <?php if ($p['codigo_barras']): ?>
                    <small class="codigo"><?= e($p['codigo_barras']) ?></small>
                  <?php endif; ?>
                </span>

              </span>
            </td>

            <td>
              <?php if ($p['categoria']): ?>
                <span class="selo selo-neutro"><?= e($p['categoria']) ?></span>
              <?php else: ?>
                —
              <?php endif; ?>
            </td>

            <?php if ($gerencia): ?>

              <td class="num">
                <?= $p['preco_custo'] > 0 ? moeda($p['preco_custo']) : '—' ?>
              </td>

            <?php endif; ?>

            <td class="num">
              <strong><?= moeda($p['preco']) ?></strong>
            </td>

            <?php if ($gerencia): ?>

              <?php $margem = Produto::margem($p); ?>

              <td class="num <?= $margem !== null && $margem < 0 ? 'negativo' : '' ?>">
                <?php if ($margem === null): ?>
                  —
                <?php else: ?>
                  <?= moeda($p['preco'] - $p['preco_custo']) ?>
                  <small class="codigo" title="<?= number_format(($p['preco'] - $p['preco_custo']) / $p['preco_custo'] * 100, 1, ',', '.') ?>% sobre o custo"><?= number_format($margem, 1, ',', '.') ?>% de margem · <?= number_format(($p['preco'] - $p['preco_custo']) / $p['preco_custo'] * 100, 1, ',', '.') ?>% s/ custo</small>
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
                  class="btn btn-light btn-sm btn-quadrado"
                  href="index.php?page=produtos&amp;editar=<?= $p['id'] ?>"
                  title="Editar"
                  aria-label="Editar <?= e($p['nome']) ?>">
                  <i class="bi bi-pencil" aria-hidden="true"></i>
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

                  <button class="btn btn-outline-danger btn-sm btn-quadrado" title="Excluir" aria-label="Excluir <?= e($p['nome']) ?>">
                    <i class="bi bi-trash3" aria-hidden="true"></i>
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
