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

    // Diz o que entrou, para o estoquista conferir se lançou no produto certo
    $p = Produto::buscar((int) $_POST['produto_id']);
    flash('Entrada registrada: ' . (int) $_POST['quantidade'] . ' un. de ' . $p['nome'] . ' (estoque atual: ' . $p['estoque'] . ').');
  } catch (DomainException $ex) {

    flash($ex->getMessage(), 'erro');

    // Erro: o formulário volta com o que foi escolhido (antes o lote e a quantidade se perdiam)
    $_SESSION['form_estoque'] = array_intersect_key($_POST, array_flip(['acao', 'produto_id', 'quantidade', 'lote', 'validade', 'fornecedor', 'lote_id', 'motivo', 'observacao']));
  }

  redirect('estoque');
}

$antigo = $_SESSION['form_estoque'] ?? [];
unset($_SESSION['form_estoque']);
$antigoBaixa = ($antigo['acao'] ?? '') === 'baixa' ? $antigo : [];
$antigoEntrada = $antigo && !$antigoBaixa ? $antigo : [];
$sel = fn(array $a, string $campo, $valor) => (string) ($a[$campo] ?? '') === (string) $valor ? ' selected' : '';

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

// A página é longa: atalhos para as seções que este perfil vê
$secoes = ($gerencia ? ['est-entrada' => 'Entrada'] : []) + ['alertas' => 'Alertas']
  + ($gerencia ? ['est-baixa' => 'Baixa'] : []) + ['est-posicao' => 'Posição', 'est-lotes' => 'Lotes']
  + ($gerencia ? ['est-movimentacoes' => 'Movimentações'] : []);

?>

<nav class="abas-secao d-print-none" aria-label="Seções do estoque">
  <?php foreach ($secoes as $id => $rotulo): ?>
    <a href="#<?= $id ?>"><?= $rotulo ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($gerencia): ?>

  <section class="card card-body mb-3" id="est-entrada">

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
          data-seleciona="entrada-produto"
          data-camera>
      </label>

      <label>
        Produto

        <select name="produto_id" id="entrada-produto" required>

          <!-- Sem padrão: lançar no produto errado não tem como desfazer pela tela -->
          <option value="">Escolha…</option>

          <?php foreach ($todos as $p): ?>

            <option
              value="<?= $p['id'] ?>"<?= $sel($antigoEntrada, 'produto_id', $p['id']) ?>
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
          value="<?= e($antigoEntrada['quantidade'] ?? '') ?>"
          required>
      </label>

      <label>
        Lote

        <input
          name="lote"
          maxlength="40"
          value="<?= e($antigoEntrada['lote'] ?? '') ?>"
          placeholder="Opcional">
      </label>

      <label>
        Validade

        <input
          name="validade"
          type="date"
          min="<?= date('Y-m-d') ?>"
          value="<?= e($antigoEntrada['validade'] ?? '') ?>"
          title="Deixe vazio para produtos sem validade">
      </label>

      <label>
        Fornecedor

        <input
          name="fornecedor"
          value="<?= e($antigoEntrada['fornecedor'] ?? '') ?>"
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

  <section class="card card-body mb-3" id="est-baixa"<?= $busca === '' ? ' data-recolher' : '' ?>>

    <h2>Dar baixa (vencido, avaria, perda)</h2>

    <form method="post" class="linha" data-confirmar="Confirmar a baixa? As unidades saem do estoque.">

      <?= csrf_campo() ?>

      <input type="hidden" name="acao" value="baixa">

      <label>
        Lote

        <select name="lote_id" required>
          <option value="">Escolha…</option>
          <?php foreach (Estoque::lotes() as $l): ?>
            <option value="<?= $l['id'] ?>"<?= $sel($antigoBaixa, 'lote_id', $l['id']) ?>>
              <?= e($l['produto']) ?> · lote <?= e($l['codigo']) ?> · <?= $l['quantidade'] ?> un.<?= $l['validade'] ? ' · val. ' . date('d/m/Y', strtotime($l['validade'])) : '' ?><?= Estoque::situacao($l['validade']) === 'vencido' ? ' (VENCIDO)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>
        Quantidade

        <input name="quantidade" type="number" min="1" value="<?= e($antigoBaixa['quantidade'] ?? '') ?>" required>
      </label>

      <label>
        Motivo

        <select name="motivo" required>
          <?php foreach (Estoque::MOTIVOS_BAIXA as $chave => $rotulo): ?>
            <option value="<?= $chave ?>"<?= $sel($antigoBaixa, 'motivo', $chave) ?>><?= e($rotulo) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>
        Observação

        <input name="observacao" maxlength="200" value="<?= e($antigoBaixa['observacao'] ?? '') ?>" placeholder="Opcional">
      </label>

      <button class="btn btn-primary">
        Dar baixa
      </button>

    </form>

  </section>

<?php endif; ?>


<section class="card card-body mb-3" id="est-posicao">

  <h2>Posição do estoque</h2>

  <form method="get" class="busca-barra" role="search">

    <input
      type="hidden"
      name="page"
      value="estoque">

    <label>
      <span class="visually-hidden">Pesquisar</span>
      <i class="bi bi-search" aria-hidden="true"></i>

      <input
        type="search"
        name="q"
        value="<?= e($busca) ?>"
        aria-label="Pesquisar"
        placeholder="Buscar por nome, código de barras ou lote">
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
        $baixo = Produto::precisaRepor($p);
        ?>

        <tr>

          <td>
            <span class="produto-celula">
              <?php if ($url = Produto::urlFoto($p['foto'])): ?>
                <img class="foto" src="<?= e($url) ?>" alt="" loading="lazy">
              <?php else: ?>
                <span class="foto foto-vazia" aria-hidden="true"><i class="bi bi-image"></i></span>
              <?php endif; ?>
              <?= e($p['nome']) ?>
            </span>
          </td>

          <td class="num">
            <span class="nivel">
              <?= $p['estoque'] ?>
              <?php // barra até 2x o mínimo: cheia = folga confortável ?>
              <span class="nivel-trilho" aria-hidden="true"><span class="nivel-valor<?= $baixo ? ' baixo' : '' ?>" style="width: <?= min(100, round($p['estoque'] / max(1, $p['estoque_minimo'] * 2) * 100)) ?>%"></span></span>
            </span>
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


<section class="card card-body mb-3" id="est-lotes"<?= $busca === '' ? ' data-recolher' : '' ?>>

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

  <section class="card card-body mb-3" id="est-movimentacoes"<?= $busca === '' ? ' data-recolher' : '' ?>>

    <h2>Movimentações de estoque<?= $busca !== '' ? ' · ' . e($busca) : '' ?></h2>

    <?php $movs = Estoque::movimentacoes($busca, 31); $haMaisMovs = count($movs) > 30; $movs = array_slice($movs, 0, 30); // uma a mais: só avisa se existem anteriores ?>

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
              <td><?= e($m['observacao'] ?: '—') ?></td>
            </tr>

          <?php endforeach; ?>

        </tbody>

      </table></div>

      <?php if ($haMaisMovs): ?>
        <p class="nota">Mostrando as 30 movimentações mais recentes. Busque pelo produto ou lote para ver as anteriores.</p>
      <?php endif; ?>

    <?php endif; ?>

  </section>

<?php endif; ?>
