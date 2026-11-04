<?php

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  $salva = null; // despesa gravada: a lista volta mostrando ela

  try {

    if (($_POST['acao'] ?? '') === 'excluir') {

      Despesa::excluir((int) ($_POST['id'] ?? 0));

      flash('Despesa excluída.');
    } else {

      $mudou = Despesa::salvar($_POST, usuario()['id']);

      $salva = ['categoria' => (string) $_POST['categoria'], 'data' => trim((string) $_POST['data'])]; // a mesma limpeza da validação

      flash(!(int) ($_POST['id'] ?? 0) ? 'Despesa registrada.' : ($mudou ? 'Despesa alterada.' : 'Nada mudou na despesa.'));
    }
  } catch (DomainException $ex) {

    flash($ex->getMessage(), 'erro');

    if (($_POST['acao'] ?? '') !== 'excluir') {
      // Só os campos do formulário: o $_POST inteiro enchia a sessão
      $_SESSION['form_despesa'] = array_intersect_key($_POST, array_flip(['id', 'descricao', 'categoria', 'valor', 'data']));
    }
  }

  // Volta com os mesmos filtros (excluir uma despesa limpava o período e a categoria)
  [$deVolta, $ateVolta] = Relatorio::datas($_POST);
  $catVolta = (string) ($_POST['filtro_categoria'] ?? '');
  if ($salva) {
    // Salvou com outra categoria ou fora do período filtrado: a lista acompanha (antes a despesa "sumia")
    if ($catVolta !== '' && $catVolta !== $salva['categoria']) $catVolta = $salva['categoria'];
    $deVolta = min($deVolta, $salva['data']);
    $ateVolta = max($ateVolta, $salva['data']);
  }
  redirect('despesas', ['de' => $deVolta, 'ate' => $ateVolta] + (isset(Despesa::CATEGORIAS[$catVolta]) ? ['categoria' => $catVolta] : []));
}

$antigo = $_SESSION['form_despesa'] ?? [];
unset($_SESSION['form_despesa']);

[$de, $ate] = Relatorio::datas($_GET);

$categoria = (string) ($_GET['categoria'] ?? '');

// Edição: ?editar=ID, ou voltou com erro de uma edição
$idEditar = (int) ($antigo['id'] ?? ($_GET['editar'] ?? 0));
$editando = $idEditar ? Despesa::buscar($idEditar) : null;
// depois de um POST o erro do salvamento já foi mostrado: não repete o aviso
if ($idEditar && !$editando && !$antigo) flash('Despesa não encontrada: ela pode ter sido excluída.', 'erro');
$filtrosAtuais = ['de' => $de, 'ate' => $ate] + ($categoria !== '' ? ['categoria' => $categoria] : []);

$despesas = Despesa::listar($de, $ate, $categoria);

$total = array_sum(array_column($despesas, 'valor'));

// Quanto cada categoria pesa no período (independe do filtro de categoria)
$porCategoria = Despesa::porCategoria($de, $ate);
$totalPeriodo = array_sum($porCategoria) ?: 1;

$subtituloTopo = date('d/m/Y', strtotime($de)) . ' a ' . date('d/m/Y', strtotime($ate));
// Editando: "Nova despesa" recarrega a página com o formulário vazio (como em Produtos)
$acoesTopo = $editando
  ? '<a class="btn btn-primary" href="index.php?' . e(http_build_query(['page' => 'despesas', 'novo' => 1] + $filtrosAtuais)) . '"><i class="bi bi-plus-lg" aria-hidden="true"></i> Nova despesa</a>'
  : '<button class="btn btn-primary" type="button" data-bs-toggle="offcanvas" data-bs-target="#gaveta-despesa" aria-controls="gaveta-despesa"><i class="bi bi-plus-lg" aria-hidden="true"></i> Nova despesa</button>';
$valorAtual = $antigo['valor'] ?? ($editando ? number_format((float) $editando['valor'], 2, ',', '.') : '');

$linkCat = fn(string $cat) => 'index.php?' . e(http_build_query(['page' => 'despesas', 'de' => $de, 'ate' => $ate] + ($cat !== '' ? ['categoria' => $cat] : [])));
$icone = fn(string $cat) => Despesa::ICONES[$cat] ?? 'receipt';

?>

<div
  class="offcanvas offcanvas-end gaveta"
  tabindex="-1"
  id="gaveta-despesa"
  aria-labelledby="gaveta-despesa-titulo"
  <?= $antigo || $editando || isset($_GET['novo']) ? 'data-abrir' : '' ?>>

  <div class="offcanvas-header">
    <div>
      <h2 class="offcanvas-title" id="gaveta-despesa-titulo"><?= $editando ? 'Editar despesa' : 'Nova despesa' ?></h2>
      <?php if ($editando): ?><p class="card-sub"><?= e($editando['descricao']) ?></p><?php endif; ?>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Fechar"></button>
  </div>

  <form method="post" class="offcanvas-body gaveta-form">

    <?= csrf_campo() ?>

    <?php // volta para a mesma lista depois de salvar ?>
    <input type="hidden" name="id" value="<?= $editando ? (int) $editando['id'] : '' ?>">
    <input type="hidden" name="de" value="<?= e($de) ?>">
    <input type="hidden" name="ate" value="<?= e($ate) ?>">
    <input type="hidden" name="filtro_categoria" value="<?= e($categoria) ?>">

    <label>
      Descrição

      <input
        name="descricao"
        required
        maxlength="120"
        placeholder="Ex.: Conta de luz de outubro"
        value="<?= e($antigo['descricao'] ?? $editando['descricao'] ?? '') ?>">
    </label>

    <fieldset>
      <legend>Categoria</legend>
      <div class="opcoes-icone">
        <?php foreach (Despesa::CATEGORIAS as $chave => $rotulo): ?>
          <label class="opcao-icone">
            <input type="radio" name="categoria" value="<?= $chave ?>" required <?= ($antigo['categoria'] ?? $editando['categoria'] ?? '') === $chave ? 'checked' : '' ?>>
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
          value="<?= e($valorAtual) ?>">
      </label>

      <label>
        Data

        <input
          name="data"
          type="date"
          required
          value="<?= e($antigo['data'] ?? $editando['data'] ?? date('Y-m-d')) ?>"
          min="<?= Despesa::DATA_MINIMA ?>"
          max="<?= date('Y-m-d') ?>">
      </label>
    </div>

    <div class="gaveta-rodape">
      <button type="button" class="btn btn-light" data-bs-dismiss="offcanvas">Cancelar</button>
      <button class="btn btn-primary"><?= $editando ? 'Salvar alterações' : 'Registrar despesa' ?></button>
    </div>

  </form>

</div>

<div class="painel-topo painel-despesas">

  <section class="kpi kpi-destaque">
    <?php if ($categoria !== '' && isset(Despesa::CATEGORIAS[$categoria])): // com chip ativo, o destaque segue o filtro ?>
      <span>Total de <?= e(Despesa::CATEGORIAS[$categoria]) ?> no período</span>
      <strong><?= moeda($total) ?></strong>
      <small>de <?= moeda(array_sum($porCategoria)) ?> em todas as categorias</small>
    <?php else: ?>
      <span>Total no período</span>
      <strong><?= moeda(array_sum($porCategoria)) ?></strong>
      <small><?= count($porCategoria) ?> <?= count($porCategoria) === 1 ? 'categoria' : 'categorias' ?> com gastos</small>
    <?php endif; ?>
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

    <div class="filtro-periodo">
    <?php // mesmos atalhos do Dashboard e dos Relatórios, mantendo a categoria escolhida ?>
    <nav class="segmentado" aria-label="Atalhos de período">
      <?php $aceso = false; ?>
      <?php foreach (['hoje' => 'Hoje', 'semana' => '7 dias', 'mes' => 'Este mês'] as $chave => $rotulo): ?>
        <?php $ativo = !$aceso && Relatorio::datas(['periodo' => $chave]) === [$de, $ate]; $aceso = $aceso || $ativo; // aceso pelas datas (vale depois de filtrar); no dia 1º Hoje = Este mês: acende só o primeiro ?>
        <a class="<?= $ativo ? 'active' : '' ?>" href="index.php?<?= e(http_build_query(['page' => 'despesas', 'periodo' => $chave] + ($categoria !== '' ? ['categoria' => $categoria] : []))) ?>"<?= $ativo ? ' aria-current="true"' : '' ?>><?= $rotulo ?></a>
      <?php endforeach; ?>
    </nav>

    <form method="get" class="filtro-datas">
      <input type="hidden" name="page" value="despesas">
      <?php if ($categoria !== ''): ?><input type="hidden" name="categoria" value="<?= e($categoria) ?>"><?php endif; ?>
      <label><span class="visually-hidden">De</span><input type="date" name="de" value="<?= e($de) ?>" aria-label="De"></label>
      <span class="filtro-ate" aria-hidden="true">→</span>
      <label><span class="visually-hidden">Até</span><input type="date" name="ate" value="<?= e($ate) ?>" aria-label="Até"></label>
      <button class="btn btn-light"><i class="bi bi-funnel" aria-hidden="true"></i> Filtrar</button>
    </form>
    </div>
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

          <tr<?= $editando && (int) $editando['id'] === (int) $d['id'] ? ' class="linha-ativa"' : '' ?>>
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
              <a class="btn btn-light btn-sm btn-quadrado" href="index.php?<?= e(http_build_query(['page' => 'despesas', 'editar' => (int) $d['id']] + $filtrosAtuais)) ?>" title="Editar" aria-label="Editar a despesa <?= e($d['descricao']) ?>"><i class="bi bi-pencil" aria-hidden="true"></i></a>
              <form method="post" data-confirmar="Excluir a despesa &quot;<?= e($d['descricao']) ?>&quot;?">
                <?= csrf_campo() ?>
                <input type="hidden" name="acao" value="excluir">
                <input type="hidden" name="id" value="<?= $d['id'] ?>">
                <input type="hidden" name="de" value="<?= e($de) ?>">
                <input type="hidden" name="ate" value="<?= e($ate) ?>">
                <input type="hidden" name="filtro_categoria" value="<?= e($categoria) ?>">
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
