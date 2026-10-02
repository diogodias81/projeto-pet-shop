
<?php

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  try {

    $itens = json_decode(
      $_POST['itens'] ?? '[]',
      true
    ) ?: [];

    $res = Venda::registrar(
      usuario()['id'],
      $itens
    );

    flash(
      'Venda #' . $res['id'] .
        ' registrada: ' .
        moeda($res['total']) .
        '.'
    );

    foreach ($res['alertas'] as $a) {

      flash(
        $a,
        'alerta'
      );
    }
  } catch (DomainException $ex) {

    flash(
      $ex->getMessage(),
      'erro'
    );
  }

  redirect('vendas');
}

$disponiveis = array_filter(
  Produto::listar(),
  fn($p) => $p['estoque'] > 0
);

$historico = Venda::listar(
  null,
  null,
  30
);

?>

<section class="painel">

  <h2>Nova venda</h2>

  <form
    method="post"
    id="form-venda">

    <?= csrf_campo() ?>

    <input
      type="hidden"
      name="itens"
      id="venda-itens">

    <div class="linha">

      <label>
        Produto

        <select id="v-produto">

          <?php foreach ($disponiveis as $p): ?>

            <option
              value="<?= $p['id'] ?>"
              data-nome="<?= e($p['nome']) ?>"
              data-preco="<?= $p['preco'] ?>"
              data-estoque="<?= $p['estoque'] ?>">
              <?= e($p['nome']) ?>
              —
              <?= moeda($p['preco']) ?>
              (estoque: <?= $p['estoque'] ?>)
            </option>

          <?php endforeach; ?>

        </select>

      </label>

      <label>
        Qtd.

        <input
          type="number"
          id="v-qtd"
          min="1"
          value="1">
      </label>

      <button
        type="button"
        class="btn btn-claro"
        id="v-add">
        Adicionar item
      </button>

    </div>


    <table id="v-tabela">

      <thead>

        <tr>
          <th>Produto</th>
          <th class="num">Qtd.</th>
          <th class="num">Unitário</th>
          <th class="num">Subtotal</th>
          <th></th>
        </tr>

      </thead>

      <tbody></tbody>

    </table>


    <p
      class="vazio"
      id="v-vazio">
      Nenhum item adicionado.
    </p>


    <div class="total-venda">

      Total:

      <strong id="v-total">
        R$ 0,00
      </strong>

      <button
        class="btn"
        id="v-finalizar"
        disabled>
        Finalizar venda
      </button>

    </div>

  </form>

</section>


<section class="painel">

  <h2>Histórico de vendas</h2>

  <?php if (!$historico): ?>

    <p class="vazio">
      Nenhuma venda registrada.
    </p>

  <?php else: ?>

    <table>

      <thead>

        <tr>
          <th>#</th>
          <th>Data</th>
          <th>Vendedor</th>
          <th>Itens</th>
          <th class="num">Total</th>
        </tr>

      </thead>

      <tbody>

        <?php foreach ($historico as $v): ?>

          <tr>

            <td>
              <?= $v['id'] ?>
            </td>

            <td>
              <?= dataBr($v['criado_em']) ?>
            </td>

            <td>
              <?= e($v['vendedor']) ?>
            </td>

            <td>
              <?= e($v['itens']) ?>
            </td>

            <td class="num">
              <?= moeda($v['total']) ?>
            </td>

          </tr>

        <?php endforeach; ?>

      </tbody>

    </table>

  <?php endif; ?>

</section>
