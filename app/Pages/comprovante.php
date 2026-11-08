<?php

$venda = Venda::buscar((int) ($_GET['id'] ?? 0));

// Vendedor só vê comprovante das próprias vendas
if (
  !$venda ||
  (usuario()['perfil'] === 'vendedor' && (int) $venda['usuario_id'] !== (int) usuario()['id'])
) {

  paginaErro(404, 'Venda não encontrada.');
}

$loja = config('loja');

$segundaVia = isset($_GET['via']);

$situacao = Venda::situacao($venda);

?>

<?php
// Venda que a própria pessoa acabou de fazer (não é 2ª via): tela de sucesso do caixa
$sucesso = !$segundaVia && $venda['status'] === 'concluida' && (int) $venda['usuario_id'] === (int) usuario()['id']
  && time() - strtotime($venda['criado_em']) < 600;
if ($sucesso) {
  // a tela já diz "Venda concluída": o aviso flutuante de sucesso repetiria e cobria o ícone no celular (avisos de estoque continuam)
  foreach (flash() as [$msg, $tipo]) {
    if ($tipo !== 'ok') flash($msg, $tipo);
  }
}
?>
<?php if ($sucesso): ?>

  <div class="venda-sucesso nao-imprimir" data-limpar-carrinho="<?= (int) usuario()['id'] ?>">
    <span class="venda-sucesso-icone"><i class="bi bi-check-lg" aria-hidden="true"></i></span>
    <h1>Venda concluída</h1>
    <p><?= moeda($venda['total']) ?> em <?= e(Venda::FORMAS[$venda['forma_pagamento']] ?? '—') ?><?= $venda['valor_recebido'] !== null && $venda['valor_recebido'] > $venda['total'] ? ' · troco de <strong>' . moeda($venda['valor_recebido'] - $venda['total']) . '</strong>' : '' ?></p>
  </div>

<?php endif; ?>

<div class="comprovante-acoes nao-imprimir">

  <button
    type="button"
    class="btn btn-primary"
    data-imprimir>
    <i class="bi bi-printer" aria-hidden="true"></i> Imprimir / Salvar PDF
  </button>

  <?php $largura = (int) config('cupom_largura_mm') === 58 ? 58 : 80; ?>
  <button
    type="button"
    class="btn btn-light"
    data-imprimir-cupom="<?= $largura ?>"
    title="Para a impressora de cupom (papel de <?= $largura ?> mm): escolha ela na janela de impressão">
    <i class="bi bi-receipt-cutoff" aria-hidden="true"></i> Imprimir cupom (<?= $largura ?> mm)
  </button>

  <?php if (temPerfil(['admin', 'vendedor'])): ?>

    <a
      class="btn btn-light"
      href="index.php?page=vendas">
      <i class="bi bi-plus-lg" aria-hidden="true"></i> Nova venda
    </a>

  <?php endif; ?>

  <a
    class="btn btn-light"
    href="index.php?page=historico&amp;ver=<?= $venda['id'] ?>">
    Detalhes da venda
  </a>

</div>

<article class="comprovante">

  <header>

    <strong><?= e($loja['nome']) ?></strong>

    <span>CNPJ <?= e($loja['cnpj']) ?></span>

    <span><?= e($loja['endereco']) ?></span>

    <span>Tel. <?= e($loja['telefone']) ?></span>

  </header>

  <p class="comprovante-titulo">
    COMPROVANTE DE VENDA

    <?php if ($segundaVia): ?>
      — 2ª VIA
    <?php endif; ?>

    <br>
    <small>Não é documento fiscal</small>
  </p>

  <?php if ($situacao !== 'Concluída'): ?>

    <p class="comprovante-situacao">
      <?= e(mb_strtoupper($situacao)) ?>
    </p>

  <?php endif; ?>

  <dl class="comprovante-dados">

    <dt>Venda</dt>
    <dd>#<?= $venda['id'] ?></dd>

    <dt>Data</dt>
    <dd><?= date('d/m/Y H:i:s', strtotime($venda['criado_em'])) ?></dd>

    <dt>Vendedor</dt>
    <dd><?= e($venda['vendedor'] ?? '—') ?></dd>

    <?php if ($venda['cliente']): ?>
      <dt>Cliente</dt>
      <dd><?= e($venda['cliente']) ?></dd>
    <?php endif; ?>

  </dl>

  <div class="table-responsive"><table class="table table-hover align-middle">

    <thead>

      <tr>
        <th>Produto</th>
        <th class="num">Qtd.</th>
        <th class="num">Unit.</th>
        <th class="num">Subtotal</th>
      </tr>

    </thead>

    <tbody>

      <?php foreach ($venda['itens'] as $it): ?>

        <tr>

          <td>
            <?= e($it['nome']) ?>
          </td>

          <td class="num">
            <?= $it['quantidade'] ?>
          </td>

          <td class="num">
            <?= moeda($it['preco_unit']) ?>
          </td>

          <td class="num">
            <?= moeda($it['quantidade'] * $it['preco_unit']) ?>
          </td>

        </tr>

      <?php endforeach; ?>

      <?php foreach ($venda['servicos'] as $sv): ?>
        <tr>
          <td><?= e($sv['descricao']) ?></td>
          <td class="num">1</td>
          <td class="num"><?= moeda($sv['preco']) ?></td>
          <td class="num"><?= moeda($sv['preco']) ?></td>
        </tr>
      <?php endforeach; ?>

    </tbody>

  </table></div>

  <dl class="comprovante-totais">

    <dt>Subtotal</dt>
    <dd><?= moeda($venda['subtotal']) ?></dd>

    <?php if ($venda['desconto'] > 0): ?>

      <dt>Desconto</dt>
      <dd>− <?= moeda($venda['desconto']) ?></dd>

    <?php endif; ?>

    <dt class="resumo-total">Total</dt>
    <dd class="resumo-total"><?= moeda($venda['total']) ?></dd>

    <dt>Pagamento</dt>
    <dd><?= e(Venda::FORMAS[$venda['forma_pagamento']] ?? '—') ?></dd>

    <?php if ($venda['valor_recebido'] !== null): ?>

      <dt>Recebido</dt>
      <dd><?= moeda($venda['valor_recebido']) ?></dd>

      <dt>Troco</dt>
      <dd><?= moeda($venda['valor_recebido'] - $venda['total']) ?></dd>

    <?php endif; ?>

    <?php if ($venda['valor_devolvido'] > 0 && $venda['status'] !== 'cancelada'): ?>

      <dt>Devolvido</dt>
      <dd>− <?= moeda($venda['valor_devolvido']) ?></dd>

      <dt><strong>Líquido</strong></dt>
      <dd><strong><?= moeda($venda['total'] - $venda['valor_devolvido']) ?></strong></dd>

    <?php endif; ?>

  </dl>

  <?php if ($segundaVia): ?>

    <p class="comprovante-rodape">
      2ª via emitida em <?= date('d/m/Y H:i') ?> por <?= e(usuario()['nome']) ?>
    </p>

  <?php endif; ?>

  <p class="comprovante-rodape">
    Obrigado pela preferência!
  </p>

</article>
