<?php

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  try {

    if (($_POST['acao'] ?? '') === 'excluir') {

      Despesa::excluir((int) ($_POST['id'] ?? 0));

      flash('Despesa excluída.');
    } else {

      Despesa::criar($_POST, usuario()['id']);

      flash('Despesa registrada.');
    }
  } catch (DomainException $ex) {

    flash($ex->getMessage(), 'erro');

    if (($_POST['acao'] ?? '') !== 'excluir') {
      $_SESSION['form_despesa'] = $_POST;
    }
  }

  redirect('despesas');
}

$antigo = $_SESSION['form_despesa'] ?? [];
unset($_SESSION['form_despesa']);

[$de, $ate] = Relatorio::datas($_GET);

$categoria = (string) ($_GET['categoria'] ?? '');

$despesas = Despesa::listar($de, $ate, $categoria);

$total = array_sum(array_column($despesas, 'valor'));

// Quanto cada categoria pesa no período (independe do filtro de categoria)
$porCategoria = Despesa::porCategoria($de, $ate);
$totalPeriodo = array_sum($porCategoria) ?: 1;

$subtituloTopo = date('d/m/Y', strtotime($de)) . ' a ' . date('d/m/Y', strtotime($ate));
$acoesTopo = '<button class="btn btn-primary" type="button" data-bs-toggle="offcanvas" data-bs-target="#gaveta-despesa" aria-controls="gaveta-despesa"><i class="bi bi-plus-lg" aria-hidden="true"></i> Nova despesa</button>';

$linkCat = fn(string $cat) => 'index.php?' . e(http_build_query(['page' => 'despesas', 'de' => $de, 'ate' => $ate] + ($cat !== '' ? ['categoria' => $cat] : [])));
$icone = fn(string $cat) => Despesa::ICONES[$cat] ?? 'receipt';

?>

<div
  class="offcanvas offcanvas-end gaveta"
  tabindex="-1"
  id="gaveta-despesa"
  aria-labelledby="gaveta-despesa-titulo"
  <?= $antigo ? 'data-abrir' : '' ?>>

  <div class="offcanvas-header">
    <h2 class="offcanvas-title" id="gaveta-despesa-titulo">Nova despesa</h2>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Fechar"></button>
  </div>

  <form method="post" class="offcanvas-body gaveta-form">

    <?= csrf_campo() ?>

    <label>
      Descrição

      <input
        name="descricao"
        required
        maxlength="120"
        placeholder="Ex.: Conta de luz de outubro"
        value="<?= e($antigo['descricao'] ?? '') ?>">
    </label>

    <fieldset>
      <legend>Categoria</legend>
      <div class="opcoes-icone">
        <?php foreach (Despesa::CATEGORIAS as $chave => $rotulo): ?>
          <label class="opcao-icone">
            <input type="radio" name="categoria" value="<?= $chave ?>" required <?= ($antigo['categoria'] ?? '') === $chave ? 'checked' : '' ?>>
            <i class="bi bi-<?= $icone($chave) ?>" aria-hidden="true"></i>
            <span><?= e($rotulo) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <div class="campos-2">
      <label>
        Valor (R$)

        <input
          name="valor"
          required
          inputmode="decimal"
          placeholder="0,00"
          value="<?= e($antigo['valor'] ?? '') ?>">
      </label>

      <label>
        Data

        <input
          name="data"
          type="date"
          required
          value="<?= e($antigo['data'] ?? date('Y-m-d')) ?>"
          max="<?= date('Y-m-d') ?>">
      </label>
    </div>

    <div class="gaveta-rodape">
      <button type="button" class="btn btn-light" data-bs-dismiss="offcanvas">Cancelar</button>
      <button class="btn btn-primary">Registrar despesa</button>
    </div>

  </form>

</div>

<div class="painel-topo painel-despesas">

  <section class="kpi kpi-destaque">
    <span>Total no período</span>
    <strong><?= moeda(array_sum($porCategoria)) ?></strong>
    <small><?= count($porCategoria) ?> <?= count($porCategoria) === 1 ? 'categoria' : 'categorias' ?> com gastos</small>
  </section>

  <section class="card card-body">
    <h2>Para onde foi o dinheiro</h2>
    <?php if (!$porCategoria): ?>
      <p class="vazio">Nenhuma despesa no período.</p>
    <?php else: ?>
      <div class="hbarras">
        <?php foreach ($porCategoria as $cat => $valor): ?>
          <div class="hbarra">
            <span><i class="bi bi-<?= $icone($cat) ?> icone-suave" aria-hidden="true"></i><?= e(Despesa::CATEGORIAS[$cat] ?? $cat) ?></span>
            <span class="hbarra-trilho"><span class="hbarra-valor hbarra-pessego" style="width: <?= round($valor / $totalPeriodo * 100) ?>%"></span></span>
            <strong><?= moeda($valor) ?></strong>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

</div>

<section class="card card-body mb-3">

  <div class="card-titulo card-titulo-quebra">
    <h2>Lançamentos</h2>

    <form method="get" class="filtro-datas">
      <input type="hidden" name="page" value="despesas">
      <?php if ($categoria !== ''): ?><input type="hidden" name="categoria" value="<?= e($categoria) ?>"><?php endif; ?>
      <label><span class="visually-hidden">De</span><input type="date" name="de" value="<?= e($de) ?>" aria-label="De"></label>
      <span class="filtro-ate" aria-hidden="true">→</span>
      <label><span class="visually-hidden">Até</span><input type="date" name="ate" value="<?= e($ate) ?>" aria-label="Até"></label>
      <button class="btn btn-light"><i class="bi bi-funnel" aria-hidden="true"></i> Filtrar</button>
    </form>
  </div>

  <nav class="chips" aria-label="Filtrar por categoria">
    <a class="chip <?= $categoria === '' ? 'active' : '' ?>" href="<?= $linkCat('') ?>">Todas</a>
    <?php foreach (Despesa::CATEGORIAS as $chave => $rotulo): ?>
      <a class="chip <?= $categoria === $chave ? 'active' : '' ?>" href="<?= $linkCat($chave) ?>"<?= $categoria === $chave ? ' aria-current="true"' : '' ?>><?= e($rotulo) ?></a>
    <?php endforeach; ?>
  </nav>

  <?php if (!$despesas): ?>

    <div class="estado-vazio">
      <span class="estado-vazio-icone"><i class="bi bi-wallet2" aria-hidden="true"></i></span>
      <strong>Nenhuma despesa</strong>
      <p><?= $de > $ate ? 'A data "De" é depois da data "Até": troque as datas para ver o período.' : 'Nada lançado neste período. Use "Nova despesa" para registrar contas e gastos.' ?></p>
    </div>

  <?php else: ?>

    <div class="table-responsive"><table class="table table-hover align-middle">

      <thead>
        <tr>
          <th>Descrição</th>
          <th>Data</th>
          <th>Categoria</th>
          <th>Registrada por</th>
          <th class="num">Valor</th>
          <th><span class="visually-hidden">Ações</span></th>
        </tr>
      </thead>

      <tbody>

        <?php foreach ($despesas as $d): ?>

          <tr>
            <td>
              <span class="produto-celula">
                <span class="icone-redondo" aria-hidden="true"><i class="bi bi-<?= $icone($d['categoria']) ?>"></i></span>
                <?= e($d['descricao']) ?>
              </span>
            </td>
            <td class="text-nowrap"><?= date('d/m/Y', strtotime($d['data'])) ?></td>
            <td><span class="selo selo-neutro"><?= e(Despesa::CATEGORIAS[$d['categoria']] ?? $d['categoria']) ?></span></td>
            <td><?= e($d['usuario'] ?? '—') ?></td>
            <td class="num"><strong><?= moeda($d['valor']) ?></strong></td>
            <td class="acoes">
              <form method="post" data-confirmar="Excluir a despesa &quot;<?= e($d['descricao']) ?>&quot;?">
                <?= csrf_campo() ?>
                <input type="hidden" name="acao" value="excluir">
                <input type="hidden" name="id" value="<?= $d['id'] ?>">
                <button class="btn btn-outline-danger btn-sm btn-quadrado" title="Excluir" aria-label="Excluir a despesa <?= e($d['descricao']) ?>"><i class="bi bi-trash3" aria-hidden="true"></i></button>
              </form>
            </td>
          </tr>

        <?php endforeach; ?>

      </tbody>

      <tfoot>
        <tr>
          <th colspan="4">Total<?= $categoria !== '' ? ' de ' . e(Despesa::CATEGORIAS[$categoria] ?? $categoria) : '' ?></th>
          <th class="num"><?= moeda($total) ?></th>
          <th></th>
        </tr>
      </tfoot>

    </table></div>

  <?php endif; ?>

</section>
