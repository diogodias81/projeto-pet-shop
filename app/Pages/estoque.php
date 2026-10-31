<?php

$gerencia = podeGerirEstoque();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  exigirPerfil(['admin', 'estoque']);

  try {

    if (($_POST['acao'] ?? '') === 'baixa') {

      Estoque::baixa(
        (int) ($_POST['lote_id'] ?? 0),
        (int) ($_POST['quantidade'] ?? 0),
        $_POST['motivo'] ?? '',
        $_POST['observacao'] ?? ''
      );

      flash('Baixa registrada e estoque atualizado.');
      redirect('estoque');
    }

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

  <section class="card card-body mb-3">

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

      <button class="btn btn-primary">
        Registrar entrada
      </button>

    </form>

  </section>

<?php endif; ?>

<?php require BASE_PATH . '/app/Views/alertas.php'; ?>

<?php if ($gerencia): ?>

  <section class="card card-body mb-3">

    <h2>Dar baixa (vencido, avaria, perda)</h2>

    <form method="post" class="linha" data-confirmar="Confirmar a baixa? As unidades saem do estoque.">

      <?= csrf_campo() ?>

      <input type="hidden" name="acao" value="baixa">

      <label>
        Lote

        <select name="lote_id" required>
          <option value="">Escolha…</option>
          <?php foreach (Estoque::lotes() as $l): ?>
            <option value="<?= $l['id'] ?>">
              <?= e($l['produto']) ?> · lote <?= e($l['codigo']) ?> · <?= $l['quantidade'] ?> un.<?= $l['validade'] ? ' · val. ' . date('d/m/Y', strtotime($l['validade'])) : '' ?><?= Estoque::situacao($l['validade']) === 'vencido' ? ' (VENCIDO)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>
        Quantidade

        <input name="quantidade" type="number" min="1" required>
      </label>

      <label>
        Motivo

        <select name="motivo" required>
          <?php foreach (Estoque::MOTIVOS_BAIXA as $chave => $rotulo): ?>
            <option value="<?= $chave ?>"><?= e($rotulo) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>
        Observação

        <input name="observacao" maxlength="200" placeholder="Opcional">
      </label>

      <button class="btn btn-primary">
        Dar baixa
      </button>

    </form>

  </section>

<?php endif; ?>


<section class="card card-body mb-3">

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

    <button class="btn btn-light">
      Buscar
    </button>

  </form>

  <div class="table-responsive"><table class="table table-hover align-middle">

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

  </table></div>

</section>


<section class="card card-body mb-3">

  <h2>Lotes e validade</h2>

  <?php if (!$lotes): ?>

    <p class="vazio">
      Nenhum lote com saldo.
    </p>

  <?php else: ?>

    <div class="table-responsive"><table class="table table-hover align-middle">

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
          // Mesmo texto da tela de alertas
          if (Estoque::diasParaVencer($l['validade']) === 0) [$rotulo, $classe] = ['Vence hoje', 'selo-erro'];
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

    </table></div>

  <?php endif; ?>

</section>


<?php if ($gerencia): ?>

  <section class="card card-body mb-3">

    <h2>Movimentações de estoque<?= $busca !== '' ? ' · ' . e($busca) : '' ?></h2>

    <?php $movs = Estoque::movimentacoes($busca); ?>

    <?php if (!$movs): ?>

      <p class="vazio">Nenhuma movimentação<?= $busca !== '' ? ' para esta busca' : '' ?>.</p>

    <?php else: ?>

      <div class="table-responsive"><table class="table table-hover align-middle">

        <thead>
          <tr>
            <th>Data</th>
            <th>Tipo</th>
            <th>Produto</th>
            <th>Lote</th>
            <th class="num">Qtd.</th>
            <th>Venda</th>
            <th>Usuário</th>
            <th>Observação</th>
          </tr>
        </thead>

        <tbody>

          <?php foreach ($movs as $m): ?>

            <tr>
              <td><?= dataBr($m['criado_em']) ?></td>
              <td><span class="selo <?= $m['quantidade'] > 0 ? 'selo-ok' : 'selo-neutro' ?>"><?= e(Estoque::TIPOS[$m['tipo']]) ?></span></td>
              <td><?= e($m['produto']) ?></td>
              <td><?= e($m['lote'] ?? '—') ?></td>
              <td class="num"><?= $m['quantidade'] > 0 ? '+' . $m['quantidade'] : $m['quantidade'] ?></td>
              <td><?= $m['venda_id'] ? '#' . $m['venda_id'] : '—' ?></td>
              <td><?= e($m['usuario'] ?? '—') ?></td>
              <td><?= e($m['observacao'] ?? '—') ?></td>
            </tr>

          <?php endforeach; ?>

        </tbody>

      </table></div>

    <?php endif; ?>

  </section>

<?php endif; ?>
