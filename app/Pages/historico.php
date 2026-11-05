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

// Visual comum à lista e ao detalhe
$seloSituacao = fn(array $v) => match (Venda::situacao($v)) {
  'Cancelada' => 'selo-erro', 'Devolvida', 'Devolução parcial' => 'selo-alerta', default => 'selo-ok',
};
$iconePagamento = ['dinheiro' => 'cash-coin', 'pix' => 'qr-code', 'debito' => 'credit-card', 'credito' => 'credit-card-2-front'];


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

  $tituloPagina = 'Venda #' . $venda['id'];
  $subtituloTopo = dataBr($venda['criado_em']) . ' · ' . ($venda['vendedor'] ?? '—');
  $acoesTopo = '<a class="btn btn-light" href="index.php?page=historico"><i class="bi bi-arrow-left" aria-hidden="true"></i> Histórico</a>'
    . '<a class="btn btn-light" href="index.php?page=comprovante&amp;id=' . $venda['id'] . '&amp;via=2"><i class="bi bi-printer" aria-hidden="true"></i> 2ª via do comprovante</a>';
?>

  <div class="detalhe-grade">

    <section class="card card-body">

      <div class="card-titulo">
        <h2>Itens</h2>
        <span class="selo <?= $seloSituacao($venda) ?>"><?= e(Venda::situacao($venda)) ?></span>
      </div>

      <form autocomplete="off" method="post">

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

          <div class="zona-acao">

            <h3>Devolver ou cancelar</h3>

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
                  data-exige-quantidade='input[name^="devolver["]'
                  data-confirmar-botao="Registrar a devolução dos itens marcados? Eles voltam ao estoque e a devolução não pode ser desfeita.">
                  <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Registrar devolução
                </button>

              <?php endif; ?>

              <button
                class="btn btn-outline-danger"
                name="acao"
                value="cancelar"
                data-confirmar-botao="Cancelar a venda #<?= $venda['id'] ?> inteira? Os itens voltam ao estoque.">
                <i class="bi bi-x-circle" aria-hidden="true"></i> Cancelar venda
              </button>

            </div>

            <?php if ($restam > 0): ?>
              <p class="nota"><i class="bi bi-info-circle" aria-hidden="true"></i> Para devolver só alguns itens, preencha "Devolver agora" na tabela acima.</p>
            <?php endif; ?>

          </div>

        <?php endif; ?>

        <?php if ($venda['status'] !== 'cancelada' && $soProprias): ?>

          <p class="nota">
            <i class="bi bi-info-circle" aria-hidden="true"></i> Cancelamentos e devoluções são feitos só pelo administrador. Se o cliente precisar, chame um administrador.
          </p>

        <?php endif; ?>

      </form>

    </section>

    <aside class="card card-body">

      <h2>Resumo</h2>

      <dl class="detalhes detalhes-resumo">

        <dt>Data</dt>
        <dd><?= dataBr($venda['criado_em']) ?></dd>

        <dt>Vendedor</dt>
        <dd><?= e($venda['vendedor'] ?? '—') ?></dd>

        <dt>Pagamento</dt>
        <dd><i class="bi bi-<?= $iconePagamento[$venda['forma_pagamento']] ?? 'wallet2' ?>" aria-hidden="true"></i> <?= e(Venda::FORMAS[$venda['forma_pagamento']] ?? '—') ?></dd>

        <dt>Subtotal</dt>
        <dd><?= moeda($venda['subtotal']) ?></dd>

        <dt>Desconto</dt>
        <dd><?= $venda['desconto'] > 0 ? '− ' . moeda($venda['desconto']) : moeda(0) ?></dd>

        <dt class="detalhes-total">Total</dt>
        <dd class="detalhes-total"><?= moeda($venda['total']) ?></dd>

        <?php if ($venda['valor_recebido'] !== null): ?>
          <dt>Recebido</dt>
          <dd><?= moeda($venda['valor_recebido']) ?></dd>
          <dt>Troco</dt>
          <dd><?= moeda($venda['valor_recebido'] - $venda['total']) ?></dd>
        <?php endif; ?>

        <?php // com devolução, o que ficou para a loja é o líquido: sem esta linha o Total parecia não bater ?>
        <?php if ($venda['valor_devolvido'] > 0 && $venda['status'] !== 'cancelada'): ?>
          <dt>Devolvido</dt>
          <dd class="negativo">− <?= moeda($venda['valor_devolvido']) ?></dd>
          <dt><strong>Líquido</strong></dt>
          <dd><strong><?= moeda($venda['total'] - $venda['valor_devolvido']) ?></strong></dd>
        <?php endif; ?>

        <?php if (temPerfil(['admin', 'financeiro'])): ?>
          <dt>Lucro bruto</dt>
          <dd class="positivo"><?= moeda(Venda::lucro($venda)) ?></dd>
        <?php endif; ?>

      </dl>

      <?php if ($venda['status'] === 'cancelada'): ?>
        <div class="alert alert-danger mb-0">
          Cancelada em <?= dataBr($venda['cancelado_em']) ?>
          por <?= e($venda['cancelado_por_nome'] ?? '—') ?>: <?= e($venda['motivo_cancelamento']) ?>
        </div>
      <?php endif; ?>

    </aside>

  </div>

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

// Busca uma a mais só para saber se existem anteriores (a nota abaixo)
$vendas = Venda::listar($filtros, 201);
$haMaisVendas = count($vendas) > 200;
$vendas = array_slice($vendas, 0, 200);

$produtos = Produto::listar();

$vendedores = array_filter(
  Usuario::listar(),
  fn($u) => in_array($u['perfil'], ['admin', 'vendedor'], true)
);

// Resumo das vendas listadas (canceladas não somam)
// Mesma regra do Dashboard: venda que conta é a concluída e não devolvida por inteiro
$validas = array_filter($vendas, fn($v) => $v['status'] === 'concluida' && !($v['total'] > 0 && $v['valor_devolvido'] >= $v['total']));
$somaLiquido = array_sum(array_column($validas, 'liquido'));
$foraDaConta = count($vendas) - count($validas);

// as datas do filtro vêm do GET sem validação: só mostra no subtítulo se forem datas de verdade
$dataOk = fn($d) => is_string($d) && ($dt = DateTime::createFromFormat('!Y-m-d', $d)) && $dt->format('Y-m-d') === $d; // mesma regra de Relatorio::datas (30/02 e "\n" no fim não passam)
$subtituloTopo = ($soProprias ? 'Suas vendas' : 'Todas as vendas da loja')
  . ($dataOk($filtros['de']) && $dataOk($filtros['ate']) ? ' · ' . date('d/m/Y', strtotime($filtros['de'])) . ' a ' . date('d/m/Y', strtotime($filtros['ate'])) : '');
if (temPerfil(['admin', 'vendedor'])) {
  $acoesTopo = '<a class="btn btn-primary" href="index.php?page=vendas"><i class="bi bi-plus-lg" aria-hidden="true"></i> Nova venda</a>';
}

// Abas de situação: mantêm os outros filtros
$linkStatus = fn(string $s) => 'index.php?' . e(http_build_query(['page' => 'historico', 'status' => $s] + array_diff_key($_GET, ['page' => 1, 'status' => 1])));

?>

<section class="card card-body mb-3">

  <div class="card-titulo card-titulo-quebra">
    <nav class="segmentado" aria-label="Situação">
      <?php foreach (['' => 'Todas', 'concluida' => 'Concluídas', 'cancelada' => 'Canceladas'] as $valor => $rotulo): ?>
        <a class="<?= $filtros['status'] === $valor ? 'active' : '' ?>" href="<?= $linkStatus($valor) ?>"<?= $filtros['status'] === $valor ? ' aria-current="true"' : '' ?>><?= $rotulo ?></a>
      <?php endforeach; ?>
    </nav>

    <?php if ($vendas): ?>
      <div class="resumo-lista">
        <?php if ($filtros['status'] === 'cancelada'): // na aba Canceladas, "0 vendas · R$ 0,00" não dizia nada ?>
          <span><strong><?= count($vendas) ?></strong> <?= count($vendas) === 1 ? 'venda cancelada' : 'vendas canceladas' ?></span>
        <?php else: ?>
        <span><strong><?= count($validas) ?></strong> <?= count($validas) === 1 ? 'venda' : 'vendas' ?></span>
        <span><strong><?= moeda($somaLiquido) ?></strong> líquido</span>
        <?php endif; ?>
        <?php if ($foraDaConta && $filtros['status'] !== 'cancelada'): ?>
          <span title="Canceladas e devolvidas por inteiro não entram na soma"><strong><?= $foraDaConta ?></strong> <?= $foraDaConta === 1 ? 'cancelada/devolvida' : 'canceladas/devolvidas' ?></span>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php // celular: filtros recolhidos, a não ser que algum esteja em uso; computador: sempre abertos (app.js) ?>
  <?php $filtrando = array_intersect_key($_GET, array_flip(['de', 'ate', 'produto_id', 'usuario_id', 'forma'])) !== []; ?>
  <details class="filtros-caixa" data-aberto-desktop<?= $filtrando ? ' open' : '' ?>>
  <summary class="btn btn-light"><i class="bi bi-sliders" aria-hidden="true"></i> Filtros<?= $filtrando ? ' <span class="selo selo-neutro">em uso</span>' : '' ?></summary>

  <form autocomplete="off" method="get" class="filtros">

    <input type="hidden" name="page" value="historico">

    <?php if ($filtros['status'] !== ''): ?>
      <input type="hidden" name="status" value="<?= e($filtros['status']) ?>">
    <?php endif; ?>

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
              <?= e($u['nome']) ?><?= $u['ativo'] ? '' : ' (desativado)' ?>
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

    <button class="btn btn-light">
      <i class="bi bi-funnel" aria-hidden="true"></i> Filtrar
    </button>

  </form>
  </details>

  <?php if (!$vendas): ?>

    <div class="estado-vazio">
      <span class="estado-vazio-icone"><i class="bi bi-receipt" aria-hidden="true"></i></span>
      <strong>Nenhuma venda encontrada</strong>
      <p>
        <?= $filtros['ate'] !== '' && $filtros['de'] > $filtros['ate']
          ? 'A data "De" é depois da data "Até": troque as datas para ver o período.'
          : 'Tente mudar o período ou os filtros.' ?>
      </p>
    </div>

  <?php else: ?>

    <div class="table-responsive"><table class="table table-hover align-middle tabela-vendas">

      <thead>

        <tr>
          <th>#</th>
          <th>Data</th>
          <th>Vendedor</th>
          <th>Itens</th>
          <th>Pagamento</th>
          <th>Situação</th>
          <th class="num">Total</th>
          <th><span class="visually-hidden">Ações</span></th>
        </tr>

      </thead>

      <tbody>

        <?php foreach ($vendas as $v): ?>

          <tr class="<?= $v['status'] === 'cancelada' ? 'cancelada' : '' ?>">

            <td><a class="venda-num" href="index.php?page=historico&amp;ver=<?= $v['id'] ?>">#<?= $v['id'] ?></a></td>

            <td class="text-nowrap"><?= dataBr($v['criado_em']) ?></td>

            <td>
              <span class="pessoa">
                <span class="avatar avatar-xs" aria-hidden="true"><?= e(iniciais($v['vendedor'])) ?></span>
                <?= e($v['vendedor'] ?? '—') ?>
              </span>
            </td>

            <td class="itens-venda" title="<?= e($v['itens']) ?>"><?= e($v['itens']) ?></td>

            <td class="text-nowrap">
              <i class="bi bi-<?= $iconePagamento[$v['forma_pagamento']] ?? 'wallet2' ?> icone-suave" aria-hidden="true"></i>
              <?= e(Venda::FORMAS[$v['forma_pagamento']] ?? '—') ?>
            </td>

            <td><span class="selo <?= $seloSituacao($v) ?>"><?= e(Venda::situacao($v)) ?></span></td>

            <td class="num">
              <strong><?= moeda($v['total']) ?></strong>

              <?php if ($v['valor_devolvido'] > 0 && $v['status'] !== 'cancelada'): ?>
                <small class="codigo">líquido <?= moeda($v['liquido']) ?></small>
              <?php endif; ?>
            </td>

            <td class="acoes">

              <a
                class="btn btn-light btn-sm btn-quadrado"
                href="index.php?page=historico&amp;ver=<?= $v['id'] ?>"
                title="Detalhes da venda #<?= $v['id'] ?>"
                aria-label="Detalhes da venda #<?= $v['id'] ?>">
                <i class="bi bi-chevron-right" aria-hidden="true"></i>
              </a>

            </td>

          </tr>

        <?php endforeach; ?>

      </tbody>

    </table></div>

    <?php if ($haMaisVendas): ?>
      <p class="nota">Mostrando as 200 vendas mais recentes. Use os filtros para ver as anteriores.</p>
    <?php endif; ?>

  <?php endif; ?>

</section>
