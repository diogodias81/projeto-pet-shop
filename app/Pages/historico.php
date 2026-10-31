<?php

$eu = usuario();

$soProprias = $eu['perfil'] === 'vendedor';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  exigirPerfil(['admin']);

  $id = (int) ($_POST['id'] ?? 0);

  try {

    if (($_POST['acao'] ?? '') === 'cancelar') {

      Venda::cancelar($id, $eu, $_POST['motivo'] ?? '');

      flash("Venda #$id cancelada. Os itens voltaram ao estoque.");
    } else {

      $valor = Venda::devolver(
        $id,
        $eu,
        array_map('intval', (array) ($_POST['devolver'] ?? [])),
        $_POST['motivo'] ?? ''
      );

      flash('Devolução registrada: ' . moeda($valor) . ' a devolver ao cliente.');
    }
  } catch (DomainException $ex) {

    flash($ex->getMessage(), 'erro');
  }

  redirect('historico', ['ver' => $id]);
}


// ---- detalhe de uma venda ----

if (isset($_GET['ver'])) {

  $venda = Venda::buscar((int) $_GET['ver']);

  if (!$venda || ($soProprias && (int) $venda['usuario_id'] !== (int) $eu['id'])) {

    paginaErro(404, 'Venda não encontrada.');
  }

  $podeAlterar = Venda::podeAlterar($venda, $eu);

  $restam = array_sum(array_map(
    fn($it) => $it['quantidade'] - $it['quantidade_devolvida'],
    $venda['itens']
  ));
?>

  <p class="nao-imprimir">
    <a href="index.php?page=historico">← Voltar ao histórico</a>
  </p>

  <section class="card card-body mb-3">

    <h2>
      Venda #<?= $venda['id'] ?>

      <span class="selo <?= $venda['status'] === 'cancelada' ? 'selo-erro' : ($venda['valor_devolvido'] > 0 ? 'selo-alerta' : 'selo-ok') ?>">
        <?= e(Venda::situacao($venda)) ?>
      </span>
    </h2>

    <dl class="detalhes">

      <dt>Data</dt>
      <dd><?= dataBr($venda['criado_em']) ?></dd>

      <dt>Vendedor</dt>
      <dd><?= e($venda['vendedor'] ?? '—') ?></dd>

      <dt>Pagamento</dt>
      <dd><?= e(Venda::FORMAS[$venda['forma_pagamento']] ?? '—') ?></dd>

      <dt>Subtotal</dt>
      <dd><?= moeda($venda['subtotal']) ?></dd>

      <dt>Desconto</dt>
      <dd><?= moeda($venda['desconto']) ?></dd>

      <dt>Total</dt>
      <dd><strong><?= moeda($venda['total']) ?></strong></dd>

      <?php if ($venda['valor_devolvido'] > 0): ?>
        <dt>Devolvido</dt>
        <dd><?= moeda($venda['valor_devolvido']) ?></dd>
      <?php endif; ?>

      <?php if (temPerfil(['admin', 'financeiro'])): ?>
        <dt>Lucro bruto</dt>
        <dd><?= moeda(Venda::lucro($venda)) ?></dd>
      <?php endif; ?>

      <?php if ($venda['status'] === 'cancelada'): ?>
        <dt>Cancelada</dt>
        <dd>
          <?= dataBr($venda['cancelado_em']) ?>
          por <?= e($venda['cancelado_por_nome'] ?? '—') ?>
          — <?= e($venda['motivo_cancelamento']) ?>
        </dd>
      <?php endif; ?>

    </dl>

    <div class="linha">

      <a
        class="btn btn-light"
        href="index.php?page=comprovante&id=<?= $venda['id'] ?>&via=2">
        Emitir 2ª via do comprovante
      </a>

    </div>

    <form method="post">

      <?= csrf_campo() ?>

      <input type="hidden" name="id" value="<?= $venda['id'] ?>">

      <div class="table-responsive"><table class="table table-hover align-middle">

        <thead>

          <tr>
            <th>Produto</th>
            <th class="num">Vendido</th>
            <th class="num">Devolvido</th>
            <th class="num">Unitário</th>
            <th class="num">Subtotal</th>

            <?php if ($podeAlterar && $restam > 0): ?>
              <th class="num">Devolver agora</th>
            <?php endif; ?>
          </tr>

        </thead>

        <tbody>

          <?php foreach ($venda['itens'] as $it): ?>

            <?php $resta = $it['quantidade'] - $it['quantidade_devolvida']; ?>

            <tr>

              <td>
                <?= e($it['nome']) ?>
              </td>

              <td class="num">
                <?= $it['quantidade'] ?>
              </td>

              <td class="num">
                <?= $it['quantidade_devolvida'] ?: '—' ?>
              </td>

              <td class="num">
                <?= moeda($it['preco_unit']) ?>
              </td>

              <td class="num">
                <?= moeda($it['quantidade'] * $it['preco_unit']) ?>
              </td>

              <?php if ($podeAlterar && $restam > 0): ?>

                <td class="num">

                  <?php if ($resta > 0): ?>

                    <input
                      class="qtd"
                      type="number"
                      name="devolver[<?= $it['id'] ?>]"
                      min="0"
                      max="<?= $resta ?>"
                      value="0"
                      aria-label="Quantidade de <?= e($it['nome']) ?> a devolver">

                  <?php endif; ?>

                </td>

              <?php endif; ?>

            </tr>

          <?php endforeach; ?>

        </tbody>

      </table></div>

      <?php if ($podeAlterar): ?>

        <div class="linha acoes-venda">

          <label>
            Motivo

            <input
              name="motivo"
              required
              maxlength="200"
              placeholder="Ex.: produto com defeito">
          </label>

          <?php if ($restam > 0): ?>

            <button
              class="btn btn-light"
              name="acao"
              value="devolver"
              data-confirmar-botao="Registrar a devolução dos itens marcados? Eles voltam ao estoque e a devolução não pode ser desfeita.">
              Registrar devolução
            </button>

          <?php endif; ?>

          <button
            class="btn btn-outline-danger"
            name="acao"
            value="cancelar"
            data-confirmar-botao="Cancelar a venda #<?= $venda['id'] ?> inteira? Os itens voltam ao estoque.">
            Cancelar venda
          </button>

        </div>

      <?php endif; ?>

      <?php if ($venda['status'] !== 'cancelada' && $soProprias): ?>

        <p class="vazio">
          Cancelamentos e devoluções são feitos só pelo administrador. Se o cliente precisar, chame um administrador.
        </p>

      <?php endif; ?>

    </form>

  </section>

  <?php if ($venda['devolucoes']): ?>

    <section class="card card-body mb-3">

      <h2>Devoluções registradas</h2>

      <div class="table-responsive"><table class="table table-hover align-middle">

        <thead>

          <tr>
            <th>Data</th>
            <th>Produto</th>
            <th class="num">Qtd.</th>
            <th class="num">Valor</th>
            <th>Motivo</th>
            <th>Registrado por</th>
          </tr>

        </thead>

        <tbody>

          <?php foreach ($venda['devolucoes'] as $d): ?>

            <tr>
              <td><?= dataBr($d['criado_em']) ?></td>
              <td><?= e($d['produto']) ?></td>
              <td class="num"><?= $d['quantidade'] ?></td>
              <td class="num"><?= moeda($d['valor']) ?></td>
              <td><?= e($d['motivo']) ?></td>
              <td><?= e($d['usuario'] ?? '—') ?></td>
            </tr>

          <?php endforeach; ?>

        </tbody>

      </table></div>

    </section>

  <?php endif; ?>

<?php
  return;
}


// ---- lista com filtros ----

$filtros = [
  'de' => $_GET['de'] ?? date('Y-m-01'),
  'ate' => $_GET['ate'] ?? date('Y-m-d'),
  'produto_id' => (int) ($_GET['produto_id'] ?? 0),
  'usuario_id' => $soProprias ? (int) $eu['id'] : (int) ($_GET['usuario_id'] ?? 0),
  'forma' => $_GET['forma'] ?? '',
  'status' => $_GET['status'] ?? '',
];

$vendas = Venda::listar($filtros, 200);

$produtos = Produto::listar();

$vendedores = array_filter(
  Usuario::listar(),
  fn($u) => in_array($u['perfil'], ['admin', 'vendedor'], true)
);

?>

<section class="card card-body mb-3">

  <h2>Histórico de vendas</h2>

  <form method="get" class="linha">

    <input type="hidden" name="page" value="historico">

    <label>
      De
      <input type="date" name="de" value="<?= e($filtros['de']) ?>">
    </label>

    <label>
      Até
      <input type="date" name="ate" value="<?= e($filtros['ate']) ?>">
    </label>

    <label>
      Produto

      <select name="produto_id">

        <option value="0">Todos</option>

        <?php foreach ($produtos as $p): ?>
          <option value="<?= $p['id'] ?>" <?= $filtros['produto_id'] === (int) $p['id'] ? 'selected' : '' ?>>
            <?= e($p['nome']) ?>
          </option>
        <?php endforeach; ?>

      </select>
    </label>

    <?php if (!$soProprias): ?>

      <label>
        Funcionário

        <select name="usuario_id">

          <option value="0">Todos</option>

          <?php foreach ($vendedores as $u): ?>
            <option value="<?= $u['id'] ?>" <?= $filtros['usuario_id'] === (int) $u['id'] ? 'selected' : '' ?>>
              <?= e($u['nome']) ?>
            </option>
          <?php endforeach; ?>

        </select>
      </label>

    <?php endif; ?>

    <label>
      Pagamento

      <select name="forma">

        <option value="">Todas</option>

        <?php foreach (Venda::FORMAS as $valor => $rotulo): ?>
          <option value="<?= $valor ?>" <?= $filtros['forma'] === $valor ? 'selected' : '' ?>>
            <?= e($rotulo) ?>
          </option>
        <?php endforeach; ?>

      </select>
    </label>

    <label>
      Situação

      <select name="status">
        <option value="">Todas</option>
        <option value="concluida" <?= $filtros['status'] === 'concluida' ? 'selected' : '' ?>>Concluídas</option>
        <option value="cancelada" <?= $filtros['status'] === 'cancelada' ? 'selected' : '' ?>>Canceladas</option>
      </select>
    </label>

    <button class="btn btn-light">
      Filtrar
    </button>

  </form>

  <?php if (!$vendas): ?>

    <p class="vazio">
      <?= $filtros['de'] > $filtros['ate']
        ? 'A data "De" é depois da data "Até": troque as datas para ver o período.'
        : 'Nenhuma venda encontrada.' ?>
    </p>

  <?php else: ?>

    <div class="table-responsive"><table class="table table-hover align-middle">

      <thead>

        <tr>
          <th>#</th>
          <th>Data</th>
          <th>Vendedor</th>
          <th>Itens</th>
          <th>Pagamento</th>
          <th>Situação</th>
          <th class="num">Total</th>
          <th></th>
        </tr>

      </thead>

      <tbody>

        <?php foreach ($vendas as $v): ?>

          <tr class="<?= $v['status'] === 'cancelada' ? 'cancelada' : '' ?>">

            <td><?= $v['id'] ?></td>

            <td><?= dataBr($v['criado_em']) ?></td>

            <td><?= e($v['vendedor'] ?? '—') ?></td>

            <td><?= e($v['itens']) ?></td>

            <td><?= e(Venda::FORMAS[$v['forma_pagamento']] ?? '—') ?></td>

            <td><?= e(Venda::situacao($v)) ?></td>

            <td class="num">
              <?= moeda($v['total']) ?>

              <?php if ($v['valor_devolvido'] > 0 && $v['status'] !== 'cancelada'): ?>
                <small class="codigo">líquido <?= moeda($v['liquido']) ?></small>
              <?php endif; ?>
            </td>

            <td class="acoes">

              <a
                class="btn btn-light btn-sm"
                href="index.php?page=historico&ver=<?= $v['id'] ?>">
                Detalhes
              </a>

            </td>

          </tr>

        <?php endforeach; ?>

      </tbody>

    </table></div>

    <?php if (count($vendas) === 200): ?>
      <p class="nota">Mostrando as 200 vendas mais recentes. Use os filtros para ver as anteriores.</p>
    <?php endif; ?>

  <?php endif; ?>

</section>
