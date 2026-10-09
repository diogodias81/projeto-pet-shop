<?php

$gerencia = podeGerirEstoque();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  exigirPerfil(['admin', 'estoque']);

  try {

    Estoque::entrada(
      (int) ($_POST['produto_id'] ?? 0),
      (int) ($_POST['quantidade'] ?? 0),
      $_POST['fornecedor'] ?? ''
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

?>

<?php if ($gerencia): ?>

  <section class="painel">

    <h2>Registrar entrada</h2>

    <form method="post" class="linha">

      <?= csrf_campo() ?>

      <label>
        Produto

        <select name="produto_id" required>

          <?php foreach ($todos as $p): ?>

            <option value="<?= $p['id'] ?>">
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
        value="<?= e($busca) ?>">
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


<?php if ($gerencia): ?>

  <section class="painel">

    <h2>Últimas entradas</h2>

    <table>

      <thead>

        <tr>
          <th>Data</th>
          <th>Produto</th>
          <th class="num">Qtd.</th>
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
              <?= e($h['fornecedor'] ?: '—') ?>
            </td>

          </tr>

        <?php endforeach; ?>

      </tbody>

    </table>

  </section>

<?php endif; ?>
