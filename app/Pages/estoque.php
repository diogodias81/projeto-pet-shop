<?php

$gerencia = podeGerirEstoque();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  exigirPerfil(['admin', 'estoque']);

  try {

    Estoque::entrada(
      (int) ($_POST['produto_id'] ?? 0),
      (int) ($_POST['quantidade'] ?? 0),
      $_POST['fornecedor'] ?? '',
      $_POST['lote'] ?? '',
      $_POST['validade'] ?? ''
    );

    flash('Entrada registrada e estoque atualizado.');
  } catch (DomainException $ex) {

    flash($ex->getMessage(), 'erro');
  }

  redirect('estoque');
}

$busca = trim($_GET['q'] ?? '');

$produtos = Produto::listar($busca);

$todos = Produto::listar();

$lotes = Estoque::lotes($busca);

$rotuloSituacao = [
  'vencido' => ['Vencido — retirar', 'selo-erro'],
  'vencendo' => ['Vence em breve', 'selo-alerta'],
  'ok' => ['No prazo', 'selo-ok'],
  'sem' => ['Sem validade', 'selo-neutro'],
];

?>

<?php if ($gerencia): ?>

  <section class="painel">

    <h2>Registrar entrada</h2>

    <form method="post" class="linha">

      <?= csrf_campo() ?>

      <label>
        Código de barras

        <input
          id="entrada-codigo"
          inputmode="numeric"
          placeholder="Leia para selecionar"
          data-sem-enter
          data-seleciona="entrada-produto">
      </label>

      <label>
        Produto

        <select name="produto_id" id="entrada-produto" required>

          <?php foreach ($todos as $p): ?>

            <option
              value="<?= $p['id'] ?>"
              data-codigo="<?= e($p['codigo_barras'] ?? '') ?>">
              <?= e($p['nome']) ?>
              (atual: <?= $p['estoque'] ?>)
            </option>

          <?php endforeach; ?>

        </select>

      </label>

      <label>
        Quantidade

        <input
          type="number"
          name="quantidade"
          min="1"
          required>
      </label>

      <label>
        Lote

        <input
          name="lote"
          maxlength="40"
          placeholder="Opcional">
      </label>

      <label>
        Validade

        <input
          name="validade"
          type="date"
          min="<?= date('Y-m-d') ?>"
          title="Deixe vazio para produtos sem validade">
      </label>

      <label>
        Fornecedor

        <input
          name="fornecedor"
          maxlength="100">
      </label>

      <button class="btn">
        Registrar entrada
      </button>

    </form>

  </section>

<?php endif; ?>


<section class="painel">

  <h2>Posição do estoque</h2>

  <form method="get" class="linha">

    <input
      type="hidden"
      name="page"
      value="estoque">

    <label>
      Pesquisar

      <input
        type="search"
        name="q"
        value="<?= e($busca) ?>"
        placeholder="Nome, código de barras ou lote">
    </label>

    <button class="btn btn-claro">
      Buscar
    </button>

  </form>

  <table>

    <thead>

      <tr>
        <th>Produto</th>
        <th class="num">Estoque</th>
        <th class="num">Mínimo</th>
        <th>Situação</th>
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

          <td class="num">
            <?= $p['estoque'] ?>
          </td>

          <td class="num">
            <?= $p['estoque_minimo'] ?>
          </td>

          <td>

            <span
              class="selo <?= $baixo ? 'selo-alerta' : 'selo-ok' ?>">
              <?= $baixo ? 'Repor' : 'Normal' ?>
            </span>

          </td>

        </tr>

      <?php endforeach; ?>

    </tbody>

  </table>

</section>


<section class="painel">

  <h2>Lotes e validade</h2>

  <?php if (!$lotes): ?>

    <p class="vazio">
      Nenhum lote com saldo.
    </p>

  <?php else: ?>

    <table>

      <thead>

        <tr>
          <th>Produto</th>
          <th>Lote</th>
          <th>Validade</th>
          <th class="num">Qtd.</th>
          <th>Situação</th>
        </tr>

      </thead>

      <tbody>

        <?php foreach ($lotes as $l): ?>

          <?php
          [$rotulo, $classe] = $rotuloSituacao[Estoque::situacao($l['validade'])];
          ?>

          <tr>

            <td>
              <?= e($l['produto']) ?>
            </td>

            <td>
              <?= e($l['codigo']) ?>
            </td>

            <td>
              <?= $l['validade'] ? date('d/m/Y', strtotime($l['validade'])) : '—' ?>
            </td>

            <td class="num">
              <?= $l['quantidade'] ?>
            </td>

            <td>

              <span class="selo <?= $classe ?>">
                <?= $rotulo ?>
              </span>

            </td>

          </tr>

        <?php endforeach; ?>

      </tbody>

    </table>

  <?php endif; ?>

</section>


<?php if ($gerencia): ?>

  <section class="painel">

    <h2>Últimas entradas</h2>

    <table>

      <thead>

        <tr>
          <th>Data</th>
          <th>Produto</th>
          <th class="num">Qtd.</th>
          <th>Lote</th>
          <th>Validade</th>
          <th>Fornecedor</th>
        </tr>

      </thead>

      <tbody>

        <?php foreach (Estoque::historico() as $h): ?>

          <tr>

            <td>
              <?= dataBr($h['criado_em']) ?>
            </td>

            <td>
              <?= e($h['produto']) ?>
            </td>

            <td class="num">
              <?= $h['quantidade'] ?>
            </td>

            <td>
              <?= e($h['lote'] ?? '—') ?>
            </td>

            <td>
              <?= $h['validade'] ? date('d/m/Y', strtotime($h['validade'])) : '—' ?>
            </td>

            <td>
              <?= e($h['fornecedor'] ?: '—') ?>
            </td>

          </tr>

        <?php endforeach; ?>

      </tbody>

    </table>

  </section>

<?php endif; ?>
