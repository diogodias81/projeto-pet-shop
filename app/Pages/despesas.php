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

?>

<section class="painel">

  <h2>Registrar despesa</h2>

  <form method="post" class="linha">

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

    <label>
      Categoria

      <select name="categoria" required>
        <option value="">Escolha…</option>
        <?php foreach (Despesa::CATEGORIAS as $chave => $rotulo): ?>
          <option value="<?= $chave ?>" <?= ($antigo['categoria'] ?? '') === $chave ? 'selected' : '' ?>><?= e($rotulo) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

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
        value="<?= e($antigo['data'] ?? date('Y-m-d')) ?>">
    </label>

    <button class="btn">
      Registrar despesa
    </button>

  </form>

</section>

<section class="painel">

  <h2>Despesas do período</h2>

  <form method="get" class="linha">

    <input type="hidden" name="page" value="despesas">

    <label>
      De
      <input type="date" name="de" value="<?= e($de) ?>">
    </label>

    <label>
      Até
      <input type="date" name="ate" value="<?= e($ate) ?>">
    </label>

    <label>
      Categoria

      <select name="categoria">
        <option value="">Todas</option>
        <?php foreach (Despesa::CATEGORIAS as $chave => $rotulo): ?>
          <option value="<?= $chave ?>" <?= $categoria === $chave ? 'selected' : '' ?>><?= e($rotulo) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <button class="btn btn-claro">
      Filtrar
    </button>

  </form>

  <?php if (!$despesas): ?>

    <p class="vazio">Nenhuma despesa no período.</p>

  <?php else: ?>

    <table>

      <thead>
        <tr>
          <th>Data</th>
          <th>Descrição</th>
          <th>Categoria</th>
          <th class="num">Valor</th>
          <th>Registrada por</th>
          <th></th>
        </tr>
      </thead>

      <tbody>

        <?php foreach ($despesas as $d): ?>

          <tr>
            <td><?= date('d/m/Y', strtotime($d['data'])) ?></td>
            <td><?= e($d['descricao']) ?></td>
            <td><?= e(Despesa::CATEGORIAS[$d['categoria']] ?? $d['categoria']) ?></td>
            <td class="num"><?= moeda($d['valor']) ?></td>
            <td><?= e($d['usuario'] ?? '—') ?></td>
            <td>
              <form method="post" data-confirmar="Excluir a despesa &quot;<?= e($d['descricao']) ?>&quot;?">
                <?= csrf_campo() ?>
                <input type="hidden" name="acao" value="excluir">
                <input type="hidden" name="id" value="<?= $d['id'] ?>">
                <button class="btn btn-perigo btn-pequeno">Excluir</button>
              </form>
            </td>
          </tr>

        <?php endforeach; ?>

      </tbody>

      <tfoot>
        <tr>
          <th colspan="3">Total</th>
          <th class="num"><?= moeda($total) ?></th>
          <th colspan="2"></th>
        </tr>
      </tfoot>

    </table>

  <?php endif; ?>

</section>
