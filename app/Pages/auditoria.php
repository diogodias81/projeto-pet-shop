<?php

[$de, $ate] = Relatorio::datas($_GET);

$acao = (string) ($_GET['acao'] ?? '');

$usuarioId = (int) ($_GET['usuario_id'] ?? 0);

$registros = Auditoria::listar($de, $ate, $acao, $usuarioId, isset($_GET['exportar']) ? 100000 : 500);

if (isset($_GET['exportar'])) {
  exportarCsv("auditoria_{$de}_a_{$ate}.csv", ['Data', 'Usuário', 'Operação', 'Detalhes', 'IP'], array_map(
    fn($a) => [dataBr($a['criado_em']), $a['usuario_nome'] ?? '—', Auditoria::ACOES[$a['acao']] ?? $a['acao'], $a['detalhes'], $a['ip']],
    $registros
  ));
}

// Ícone e cor de cada tipo de operação (pelo começo da chave: venda_, produto_, usuario_...)
$visual = function (string $acao): array {
  return match (true) {
    in_array($acao, ['login_bloqueado', 'senha_bloqueada', 'venda_cancelada', 'produto_excluido', 'usuario_excluido', 'usuario_desativado', 'categoria_excluida', 'despesa_excluida'], true) => ['exclamation-triangle', 'erro'],
    $acao === 'devolucao' => ['arrow-counterclockwise', 'alerta'],
    str_starts_with($acao, 'login') => ['box-arrow-in-right', 'neutro'],
    str_starts_with($acao, 'venda') => ['cart-check', 'ok'],
    str_starts_with($acao, 'estoque') => ['boxes', 'primaria'],
    str_starts_with($acao, 'produto'), $acao === 'preco_alterado' => ['box-seam', 'primaria'],
    str_starts_with($acao, 'usuario'), str_starts_with($acao, 'senha') => ['person-gear', 'lavanda'],
    str_starts_with($acao, 'despesa') => ['wallet2', 'alerta'],
    default => ['dot', 'neutro'],
  };
};

$exportar = 'index.php?' . e(http_build_query(['page' => 'auditoria', 'de' => $de, 'ate' => $ate, 'acao' => $acao, 'usuario_id' => $usuarioId ?: '', 'exportar' => 1]));

$subtituloTopo = 'Quem fez o quê e quando · ' . date('d/m/Y', strtotime($de)) . ' a ' . date('d/m/Y', strtotime($ate));
$acoesTopo = '<a class="btn btn-light" href="' . $exportar . '"><i class="bi bi-download" aria-hidden="true"></i> Exportar CSV</a>';

// Agrupa por dia para a linha do tempo
$porDia = [];
foreach ($registros as $a) {
  $porDia[substr($a['criado_em'], 0, 10)][] = $a;
}
$hoje = date('Y-m-d');
$ontem = date('Y-m-d', strtotime('-1 day'));

$filtrando = $acao !== '' || $usuarioId || isset($_GET['de']) || isset($_GET['ate']);

?>

<section class="card card-body mb-3">

  <details class="filtros-caixa" data-aberto-desktop<?= $filtrando ? ' open' : '' ?>>
  <summary class="btn btn-light"><i class="bi bi-sliders" aria-hidden="true"></i> Filtros</summary>

  <form method="get" class="filtros">

    <input type="hidden" name="page" value="auditoria">

    <label>
      De
      <input type="date" name="de" value="<?= e($de) ?>">
    </label>

    <label>
      Até
      <input type="date" name="ate" value="<?= e($ate) ?>">
    </label>

    <label>
      Operação

      <select name="acao">
        <option value="">Todas</option>
        <?php foreach (Auditoria::ACOES as $chave => $rotulo): ?>
          <option value="<?= $chave ?>" <?= $acao === $chave ? 'selected' : '' ?>><?= e($rotulo) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <label>
      Usuário

      <select name="usuario_id">
        <option value="">Todos</option>
        <?php foreach (Usuario::listar() as $u): ?>
          <option value="<?= $u['id'] ?>" <?= $usuarioId === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['nome']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <button class="btn btn-light"><i class="bi bi-funnel" aria-hidden="true"></i> Filtrar</button>

  </form>
  </details>

  <?php if (!$registros): ?>

    <div class="estado-vazio">
      <span class="estado-vazio-icone"><i class="bi bi-shield-check" aria-hidden="true"></i></span>
      <strong>Nenhuma operação</strong>
      <p>Nada registrado com esses filtros.</p>
    </div>

  <?php else: ?>

    <?php foreach ($porDia as $dia => $itens): ?>

      <h3 class="linha-tempo-dia">
        <?= $dia === $hoje ? 'Hoje' : ($dia === $ontem ? 'Ontem' : date('d/m/Y', strtotime($dia))) ?>
        <small><?= count($itens) ?> <?= count($itens) === 1 ? 'operação' : 'operações' ?></small>
      </h3>

      <ol class="linha-tempo">
        <?php foreach ($itens as $a): ?>
          <?php [$icone, $cor] = $visual($a['acao']); ?>
          <li class="linha-tempo-item">
            <span class="linha-tempo-icone tom-<?= $cor ?>" aria-hidden="true"><i class="bi bi-<?= $icone ?>"></i></span>
            <div class="linha-tempo-texto">
              <div class="linha-tempo-topo">
                <strong><?= e(Auditoria::ACOES[$a['acao']] ?? $a['acao']) ?></strong>
                <time datetime="<?= e($a['criado_em']) ?>"><?= date('H:i', strtotime($a['criado_em'])) ?></time>
              </div>
              <?php if (!empty($a['detalhes'])): ?>
                <p><?= e($a['detalhes']) ?></p>
              <?php endif; ?>
              <small class="pessoa">
                <span class="avatar avatar-xs" aria-hidden="true"><?= e(iniciais($a['usuario_nome'])) ?></span>
                <?= e($a['usuario_nome'] ?? '—') ?>
                <?php if ($a['ip']): ?><span class="codigo">· IP <?= e($a['ip']) ?></span><?php endif; ?>
              </small>
            </div>
          </li>
        <?php endforeach; ?>
      </ol>

    <?php endforeach; ?>

    <?php if (count($registros) === 500): ?>
      <p class="nota">Mostrando as 500 operações mais recentes. Use os filtros para ver as anteriores.</p>
    <?php endif; ?>

  <?php endif; ?>

</section>
