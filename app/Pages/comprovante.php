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

<div class="linha nao-imprimir">

  <button
    type="button"
    class="btn btn-primary"
    data-imprimir>
    Imprimir / Salvar PDF
  </button>

  <?php if (temPerfil(['admin', 'vendedor'])): ?>

    <a
      class="btn btn-light"
      href="index.php?page=vendas">
      Nova venda
    </a>

  <?php endif; ?>

  <a
    class="btn btn-light"
    href="index.php?page=historico&ver=<?= $venda['id'] ?>">
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
