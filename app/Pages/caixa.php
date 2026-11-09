<?php
// Caixa: abrir com troco, sangria/reforço, fechar contando o dinheiro; histórico e resumo de cada caixa (?ver=ID)

$podeOperar = temPerfil(['admin', 'vendedor']);
$veEsperado = temPerfil(['admin', 'financeiro']); // o vendedor conta a gaveta sem ver quanto deveria ter

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  if (!$podeOperar) paginaErro(403, 'Seu perfil só consulta o caixa.');

  $acao = (string) ($_POST['acao'] ?? '');
  $volta = ($_POST['voltar'] ?? '') === 'vendas' ? 'vendas' : 'caixa';
  // de Vendas com serviços da agenda: volta com eles (só ids numéricos)
  $param = $volta === 'vendas' && preg_match('/^\d+(,\d+){0,' . (Venda::MAX_SERVICOS - 1) . '}\z/', (string) ($_POST['agendamento'] ?? '')) ? ['agendamento' => $_POST['agendamento']] : [];

  try {

    if ($acao === 'abrir') {
      Caixa::abrir((string) ($_POST['troco'] ?? ''));
      flash('Caixa aberto. Boas vendas!');
    } elseif ($acao === 'movimentar') {
      Caixa::movimentar((string) ($_POST['tipo'] ?? ''), (string) ($_POST['valor'] ?? ''), (string) ($_POST['motivo'] ?? ''));
      flash(($_POST['tipo'] === 'sangria' ? 'Sangria registrada.' : 'Reforço registrado.'));
    } elseif ($acao === 'fechar') {
      $c = Caixa::fechar((string) ($_POST['contado'] ?? ''), (string) ($_POST['observacao'] ?? ''));
      flash('Caixa fechado.' . ($c['diferenca'] == 0 ? ' O dinheiro bateu certinho.' : ''));
      $param = ['ver' => $c['id']];
      $volta = 'caixa';
    }
  } catch (DomainException $ex) {

    flash($ex->getMessage(), 'erro');
    $_SESSION['form_caixa'] = ['acao' => $acao] + array_intersect_key($_POST, array_flip(['troco', 'tipo', 'valor', 'motivo', 'contado', 'observacao']));
  }

  redirect($volta, $param);
}

$antigo = $_SESSION['form_caixa'] ?? [];
unset($_SESSION['form_caixa']);

$rotuloForma = Venda::FORMAS;
$dif = function (?float $d): string {
  if ($d === null) return '—';
  if (abs($d) < 0.005) return '<span class="selo selo-ok">Bateu</span>';
  return '<span class="selo ' . ($d > 0 ? 'selo-alerta' : 'selo-erro') . '">' . ($d > 0 ? 'Sobra ' : 'Falta ') . moeda(abs($d)) . '</span>';
};

// Resumo de um caixa (aberto ou fechado), usado na tela e no papel
$mostrarResumo = function (array $c, array $r, bool $esperado) use ($rotuloForma) {
  $totalVendas = array_sum(array_column($r['formas'], 'total'));
  $totalEstornado = array_sum(array_column($r['formas'], 'estornado'));
  $qtdVendas = array_sum(array_column($r['formas'], 'qtd'));
  // vendedor com o caixa aberto: sem somas nem vendas por forma (somando as parcelas ele chegaria ao esperado)
  $cego = !$esperado && !$c['fechado_em'];
  ?>
  <?php if ($cego): ?>
    <section class="card card-body mb-3"><p class="nota"><i class="bi bi-eye-slash" aria-hidden="true"></i> Os valores do caixa aparecem depois do fechamento: conte a gaveta sem olhar.</p></section>
  <?php else: ?>
  <div class="painel-topo">
    <section class="card card-body">
      <h2>Vendas por forma de pagamento</h2>
      <?php if (!$r['formas']): ?>
        <p class="vazio">Nenhuma venda neste caixa.</p>
      <?php else: ?>
        <div class="table-responsive"><table class="table align-middle">
          <thead><tr><th>Forma</th><th class="num">Vendas</th><th class="num">Cancelado/devolvido</th><th class="num">Líquido</th></tr></thead>
          <tbody>
            <?php foreach ($rotuloForma as $f => $rot): if (!isset($r['formas'][$f])) continue; ?>
              <?php $fm = $r['formas'][$f]; ?>
              <tr><td><?= e($rot) ?></td><td class="num"><?= $fm['qtd'] ?> <?= (int) $fm['qtd'] === 1 ? 'venda' : 'vendas' ?></td><td class="num"><?= $fm['estornado'] > 0 ? '− ' . moeda($fm['estornado']) : '—' ?></td><td class="num"><strong><?= moeda($fm['total'] - $fm['estornado']) ?></strong></td></tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot><tr><th>Total</th><th class="num"><?= $qtdVendas ?> <?= (int) $qtdVendas === 1 ? 'venda' : 'vendas' ?></th><th class="num"><?= $totalEstornado > 0 ? '− ' . moeda($totalEstornado) : '—' ?></th><th class="num"><?= moeda($totalVendas - $totalEstornado) ?></th></tr></tfoot>
        </table></div>
        <p class="nota"><i class="bi bi-info-circle" aria-hidden="true"></i> PIX e cartões ficam só para conferir com a maquininha e o banco. Cancelado/devolvido: das vendas deste caixa, mesmo que o estorno tenha sido depois.</p>
      <?php endif; ?>
    </section>

    <section class="card card-body">
      <h2>Dinheiro na gaveta</h2>
      <dl class="dados-cliente resumo-gaveta">
        <dt>Troco inicial</dt><dd><?= moeda($r['troco']) ?></dd>
        <dt>+ Vendas em dinheiro</dt><dd><?= moeda($r['vendas_dinheiro']) ?></dd>
        <?php if ($r['reforcos'] > 0): ?><dt>+ Reforços</dt><dd><?= moeda($r['reforcos']) ?></dd><?php endif; ?>
        <?php if ($r['sangrias'] > 0): ?><dt>− Sangrias</dt><dd><?= moeda($r['sangrias']) ?></dd><?php endif; ?>
        <?php if ($r['devolvido_dinheiro'] > 0): ?><dt>− Devolvido a clientes</dt><dd><?= moeda($r['devolvido_dinheiro']) ?></dd><?php endif; ?>
        <?php if ($esperado): ?>
          <dt><strong>= Esperado</strong></dt><dd><strong><?= moeda($c['esperado'] ?? $r['esperado']) ?></strong></dd>
        <?php endif; ?>
        <?php if ($esperado && ($c['esperado'] ?? $r['esperado']) < 0): ?>
          <dd class="alerta-negativo"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Esperado negativo: foi lançada sangria ou devolução maior que o dinheiro da gaveta. Confira as sangrias abaixo.</dd>
        <?php endif; ?>
        <?php if ($c['fechado_em']): ?>
          <dt>Contado</dt><dd><strong><?= moeda($c['contado']) ?></strong></dd>
        <?php endif; ?>
      </dl>
    </section>
  </div>
  <?php endif; ?>

  <?php if ($r['movimentos']): ?>
    <section class="card card-body mb-3">
      <h2>Sangrias e reforços</h2>
      <div class="table-responsive"><table class="table align-middle">
        <thead><tr><th>Hora</th><th>Tipo</th><th>Motivo</th><th>Quem</th><?php if (!$cego): ?><th class="num">Valor</th><?php endif; ?></tr></thead>
        <tbody>
          <?php foreach ($r['movimentos'] as $m): ?>
            <tr>
              <td class="text-nowrap"><?= date('d/m H:i', strtotime($m['criado_em'])) ?></td>
              <td><span class="selo <?= $m['tipo'] === 'sangria' ? 'selo-alerta' : 'selo-ok' ?>"><?= e(Caixa::TIPOS[$m['tipo']]) ?></span></td>
              <td><?= e($m['motivo']) ?></td>
              <td><?= e($m['usuario'] ?? '—') ?></td>
              <?php if (!$cego): ?><td class="num"><?= ($m['tipo'] === 'sangria' ? '− ' : '+ ') . moeda($m['valor']) ?></td><?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    </section>
  <?php endif; ?>
  <?php
};

// ----------------------------- resumo de um caixa fechado -----------------------------
if (isset($_GET['ver'])) {
  $c = Caixa::buscar((int) $_GET['ver']);
  if (!$c || !$c['fechado_em']) { flash('Caixa não encontrado.', 'erro'); redirect('caixa'); }
  $r = Caixa::resumo($c);
  $tituloPagina = 'Caixa #' . $c['id'];
  $subtituloTopo = date('d/m/Y H:i', strtotime($c['aberto_em'])) . ' a ' . date('d/m/Y H:i', strtotime($c['fechado_em']));
  $acoesTopo = '<a class="btn btn-light" href="index.php?page=caixa"><i class="bi bi-arrow-left" aria-hidden="true"></i> Caixa</a> <button class="btn btn-light" type="button" data-imprimir><i class="bi bi-printer" aria-hidden="true"></i> Imprimir</button>';
  ?>
  <div class="kpis kpis-periodo">
    <section class="kpi"><span><i class="bi bi-person" aria-hidden="true"></i> Aberto por</span><strong><?= e($c['aberto_por_nome'] ?? '—') ?></strong><small>fechado por <?= e($c['fechado_por_nome'] ?? '—') ?></small></section>
    <section class="kpi kpi-principal"><span><i class="bi bi-cash-stack" aria-hidden="true"></i> Contado na gaveta</span><strong><?= moeda($c['contado']) ?></strong><small><?= $veEsperado || $podeOperar ? 'esperado ' . moeda($c['esperado']) : '' ?></small></section>
    <section class="kpi"><span><i class="bi bi-check2-circle" aria-hidden="true"></i> Conferência</span><strong><?= $dif((float) $c['diferenca']) ?></strong><small><?= e($c['observacao'] ?? '') ?></small></section>
  </div>
  <?php
  $mostrarResumo($c, $r, true);
  return;
}

// ----------------------------- caixa de hoje -----------------------------
$caixa = Caixa::aberto();
// só os últimos 10 por padrão: a lista inteira deixava a tela enorme no celular
$verTodos = isset($_GET['todos']);
$historico = Caixa::listar($verTodos ? 60 : 11);
$temMais = !$verTodos && count($historico) > 10;
if ($temMais) array_pop($historico);

if ($caixa) {
  $r = Caixa::resumo($caixa);
  $subtituloTopo = 'Aberto desde ' . date('d/m H:i', strtotime($caixa['aberto_em'])) . ' por ' . ($caixa['aberto_por_nome'] ?? '—');
  if ($podeOperar) {
    $acoesTopo = '<button class="btn btn-light" type="button" data-bs-toggle="offcanvas" data-bs-target="#gaveta-mov" aria-controls="gaveta-mov"><i class="bi bi-arrow-left-right" aria-hidden="true"></i> Sangria / reforço</button>'
      . ' <button class="btn btn-primary" type="button" data-bs-toggle="offcanvas" data-bs-target="#gaveta-fechar" aria-controls="gaveta-fechar"><i class="bi bi-lock" aria-hidden="true"></i> Fechar caixa</button>';
  }
} else {
  $subtituloTopo = 'Caixa fechado';
}
?>

<?php if ($caixa && $podeOperar): ?>

  <div class="offcanvas offcanvas-end gaveta" tabindex="-1" id="gaveta-mov" aria-labelledby="gaveta-mov-titulo" <?= ($antigo['acao'] ?? '') === 'movimentar' ? 'data-abrir' : '' ?>>
    <div class="offcanvas-header">
      <h2 class="offcanvas-title" id="gaveta-mov-titulo">Sangria ou reforço</h2>
      <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Fechar"></button>
    </div>
    <form autocomplete="off" method="post" class="offcanvas-body gaveta-form">
      <?= csrf_campo() ?>
      <input type="hidden" name="acao" value="movimentar">
      <fieldset>
        <legend>Tipo</legend>
        <div class="opcoes-icone opcoes-2">
          <label class="opcao-icone"><input type="radio" name="tipo" value="sangria" required <?= ($antigo['tipo'] ?? 'sangria') === 'sangria' ? 'checked' : '' ?>><i class="bi bi-box-arrow-up" aria-hidden="true"></i><span>Sangria (tirar)</span></label>
          <label class="opcao-icone"><input type="radio" name="tipo" value="reforco" <?= ($antigo['tipo'] ?? '') === 'reforco' ? 'checked' : '' ?>><i class="bi bi-box-arrow-in-down" aria-hidden="true"></i><span>Reforço (colocar)</span></label>
        </div>
      </fieldset>
      <label>Valor (R$)<input name="valor" required inputmode="decimal" placeholder="0,00" value="<?= e($antigo['valor'] ?? '') ?>"></label>
      <label>Motivo<input name="motivo" required maxlength="200" placeholder="Ex.: depósito no banco; troco extra" value="<?= e($antigo['motivo'] ?? '') ?>"></label>
      <div class="gaveta-rodape">
        <button type="button" class="btn btn-light" data-bs-dismiss="offcanvas">Cancelar</button>
        <button class="btn btn-primary">Registrar</button>
      </div>
    </form>
  </div>

  <div class="offcanvas offcanvas-end gaveta" tabindex="-1" id="gaveta-fechar" aria-labelledby="gaveta-fechar-titulo" <?= ($antigo['acao'] ?? '') === 'fechar' ? 'data-abrir' : '' ?>>
    <div class="offcanvas-header">
      <div>
        <h2 class="offcanvas-title" id="gaveta-fechar-titulo">Fechar caixa</h2>
        <p class="card-sub">Conte todo o dinheiro da gaveta (notas e moedas).</p>
      </div>
      <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Fechar"></button>
    </div>
    <form autocomplete="off" method="post" class="offcanvas-body gaveta-form">
      <?= csrf_campo() ?>
      <input type="hidden" name="acao" value="fechar">
      <label>Dinheiro contado (R$)<input name="contado" required inputmode="decimal" placeholder="0,00" value="<?= e($antigo['contado'] ?? '') ?>"></label>
      <label>Observação <small><?= $veEsperado ? '(obrigatória se não bater)' : '(opcional)' ?></small><input name="observacao" maxlength="200" placeholder="Ex.: faltou troco de R$ 2,00 na venda #120" value="<?= e($antigo['observacao'] ?? '') ?>"></label>
      <p class="nota"><i class="bi bi-info-circle" aria-hidden="true"></i> Depois de fechado, as vendas ficam bloqueadas até abrir o caixa de novo.</p>
      <div class="gaveta-rodape">
        <button type="button" class="btn btn-light" data-bs-dismiss="offcanvas">Cancelar</button>
        <button class="btn btn-primary">Fechar caixa</button>
      </div>
    </form>
  </div>

<?php endif; ?>

<?php if ($caixa): ?>

  <?php $mostrarResumo($caixa, $r, $veEsperado); ?>

<?php elseif ($podeOperar): ?>

  <section class="card card-body mb-3 abrir-caixa">
    <div class="estado-vazio">
      <span class="estado-vazio-icone"><i class="bi bi-cash-coin" aria-hidden="true"></i></span>
      <strong>O caixa está fechado</strong>
      <p>Conte o troco que está na gaveta e abra o caixa para começar a vender.</p>
    </div>
    <form autocomplete="off" method="post" class="nova-categoria">
      <?= csrf_campo() ?>
      <input type="hidden" name="acao" value="abrir">
      <label>
        <span class="visually-hidden">Troco inicial</span>
        <i class="bi bi-cash" aria-hidden="true"></i>
        <input name="troco" required inputmode="decimal" placeholder="Troco inicial, ex.: 100,00" aria-label="Troco inicial (R$)" value="<?= e($antigo['troco'] ?? '') ?>">
      </label>
      <button class="btn btn-primary"><i class="bi bi-unlock" aria-hidden="true"></i> Abrir caixa</button>
    </form>
  </section>

<?php else: ?>

  <section class="card card-body mb-3"><p class="vazio">O caixa está fechado.</p></section>

<?php endif; ?>

<section class="card card-body mb-3" id="caixas-fechados">
  <h2>Caixas fechados</h2>
  <?php if (!$historico): ?>
    <p class="vazio">Nenhum caixa fechado ainda.</p>
  <?php else: ?>
    <div class="table-responsive"><table class="table table-hover align-middle">
      <thead>
        <tr><th>Caixa</th><th>Abertura</th><th>Fechamento</th><th>Fechado por</th><th class="num">Esperado</th><th class="num">Contado</th><th>Conferência</th></tr>
      </thead>
      <tbody>
        <?php foreach ($historico as $h): ?>
          <tr>
            <td><a class="venda-num" href="index.php?page=caixa&amp;ver=<?= $h['id'] ?>">#<?= $h['id'] ?></a></td>
            <td class="text-nowrap"><?= date('d/m/Y H:i', strtotime($h['aberto_em'])) ?></td>
            <td class="text-nowrap"><?= date('d/m/Y H:i', strtotime($h['fechado_em'])) ?></td>
            <td><?= e($h['fechado_por_nome'] ?? '—') ?></td>
            <td class="num"><?= moeda($h['esperado']) ?></td>
            <td class="num"><?= moeda($h['contado']) ?></td>
            <td><?= $dif((float) $h['diferenca']) ?><?php if ($h['observacao']): ?> <i class="bi bi-chat-left-text icone-suave" title="<?= e($h['observacao']) ?>" aria-label="Observação: <?= e($h['observacao']) ?>"></i><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if ($temMais): ?>
      <a class="btn btn-light btn-sm align-self-start" href="index.php?page=caixa&amp;todos=1#caixas-fechados">Ver mais caixas fechados</a>
    <?php elseif ($verTodos): ?>
      <p class="nota">Mostrando os últimos <?= count($historico) ?> caixas fechados.</p>
    <?php endif; ?>
  <?php endif; ?>
</section>
