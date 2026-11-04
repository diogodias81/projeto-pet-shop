<?php
[$de, $ate] = Relatorio::datas($_GET);
$hoje = date('Y-m-d');
$r = Relatorio::periodo($de, $ate);
$top = Relatorio::maisVendidos(8, $de, $ate);
$serie = Relatorio::serie($de, $ate);
// Backup falhando em silêncio é o pior caso: o admin vê o aviso aqui (o agendamento é scripts/backup.ps1)
$backup = temPerfil(['admin']) ? Backup::ultimo() : null;
$horasSemBackup = $backup && $backup['quando'] ? (int) floor((time() - strtotime($backup['quando'])) / 3600) : null;
$ajuda = 'Se não resolver, chame o técnico: o detalhe fica na pasta do sistema, em storage\backups\backup.log.';

// Usados aqui no "Para fazer hoje" e reaproveitados por alertas.php
$alertasValidade = Estoque::alertasValidade();
$alertasBaixo = Produto::baixoEstoque();
$prazos = Estoque::prazos();

// Comparação com o período anterior de mesmo tamanho: [valor atual, variação em % ou null quando não há base]
$variacaoPct = fn(float $atual, float $antes): ?float => $antes > 0 ? ($atual - $antes) / $antes * 100 : null;
$comparar = function (string $de, string $ate, string $deAnt, string $ateAnt) use ($variacaoPct): array {
    $atual = Relatorio::faturamento($de, $ate);
    return [$atual, $variacaoPct($atual, Relatorio::faturamento($deAnt, $ateAnt))];
};
$dia = fn(string $rel) => date('Y-m-d', strtotime($rel));
// Mês passado do dia 1 até o mesmo dia de hoje (ou o último dia dele, se for mais curto).
// Não usar "first day of last month +N days": o PHP aplica o "first day of" por último e ignora o +N.
$mesPassado = new DateTime('first day of last month');
$fimMesPassado = (clone $mesPassado)->setDate((int) $mesPassado->format('Y'), (int) $mesPassado->format('n'), min((int) date('j'), (int) $mesPassado->format('t')));
$faturamentoHoje = Relatorio::faturamento($hoje, $hoje);
$resumo = [
    ['Hoje', 'vs. ontem até este horário', $faturamentoHoje, $variacaoPct($faturamentoHoje, Relatorio::faturamentoOntemAteAgora())],
    ['Últimos 7 dias', 'vs. 7 dias anteriores', ...$comparar($dia('-6 days'), $hoje, $dia('-13 days'), $dia('-7 days'))],
    ['Este mês', 'vs. mesmo período do mês passado', ...$comparar(date('Y-m-01'), $hoje, $mesPassado->format('Y-m-d'), $fimMesPassado->format('Y-m-d'))],
];

// Pendências: [gravidade, ícone, título, detalhe, link]
$tarefas = [];
if (temPerfil(['admin'])) {
    if (!$backup) {
        $tarefas[] = ['alerta', 'cloud-slash', 'Nenhum backup feito ainda', 'Peça ao técnico para agendar o backup diário (instruções no README do sistema, seção "Backup").', null];
    } elseif (!$backup['quando']) {
        $tarefas[] = ['erro', 'cloud-slash', 'Registro do backup ilegível', 'Confira se a data e a hora do computador estão certas. ' . $ajuda, null];
    } elseif (!$backup['ok'] && !empty($backup['aviso'])) {
        $tarefas[] = ['alerta', 'cloud-check', 'Backup salvo só neste computador', 'O backup de ' . dataBr($backup['quando']) . ' foi feito, mas ' . $backup['aviso'] . '. ' . $ajuda, null];
    } elseif (!$backup['ok']) {
        $tarefas[] = ['erro', 'cloud-slash', 'O último backup falhou', 'Falhou em ' . dataBr($backup['quando']) . '. Confira se o Docker Desktop está aberto; o próximo roda às 22:00. ' . $ajuda, null];
    } elseif ($horasSemBackup >= 26) { // o backup é diário às 22:00: uma noite perdida já aparece na manhã seguinte
        $tarefas[] = ['alerta', 'clock-history', 'Backup atrasado', 'O último foi em ' . dataBr($backup['quando']) . ' (há ' . ($horasSemBackup >= 48 ? intdiv($horasSemBackup, 24) . ' dias' : $horasSemBackup . ' horas') . '). Confira se o computador e o Docker Desktop ficam ligados às 22:00.', null];
    }
}
$vencidos = count(array_filter($alertasValidade, fn($l) => $l['dias'] < 0));
$vencendo = count(array_filter($alertasValidade, fn($l) => $l['dias'] >= 0 && $l['dias'] <= min($prazos)));
// Quem não mexe no estoque (financeiro) recebe a orientação de avisar quem mexe
$gereEstoque = podeGerirEstoque();
if ($vencidos) $tarefas[] = ['erro', 'exclamation-octagon', $vencidos . ($vencidos > 1 ? ' lotes vencidos' : ' lote vencido'), $gereEstoque ? 'Retire da prateleira e dê baixa no estoque.' : 'Avise o responsável pelo estoque para retirar da prateleira.', '#alertas'];
if ($vencendo) $tarefas[] = ['alerta', 'hourglass-split', $vencendo . ($vencendo > 1 ? ' lotes vencem' : ' lote vence') . ' em até ' . min($prazos) . ' dias', 'Priorize na venda ou faça uma promoção.', '#alertas'];
if ($alertasBaixo) $tarefas[] = ['alerta', 'box-seam', count($alertasBaixo) . (count($alertasBaixo) > 1 ? ' produtos precisam' : ' produto precisa') . ' de reposição', $gereEstoque ? 'Estoque no mínimo ou abaixo: hora de pedir ao fornecedor.' : 'Estoque no mínimo ou abaixo: avise quem faz as compras.', '#alertas'];

$hora = (int) date('G');
$saudacao = $hora < 12 ? 'Bom dia' : ($hora < 18 ? 'Boa tarde' : 'Boa noite');
$primeiroNome = explode(' ', trim(usuario()['nome']))[0];
$diasSemana = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
$meses = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

$variacao = function (?float $pct): string {
    if ($pct === null) return '<span class="tendencia">—</span>';
    $classe = $pct > 0.05 ? 'sobe' : ($pct < -0.05 ? 'desce' : '');
    $icone = $pct > 0.05 ? 'arrow-up-right' : ($pct < -0.05 ? 'arrow-down-right' : 'dash');
    return '<span class="tendencia ' . $classe . '"><i class="bi bi-' . $icone . '" aria-hidden="true"></i> ' . number_format(abs($pct), 1, ',', '.') . '%</span>';
};
?>
<header class="saudacao">
    <div>
        <p class="saudacao-data"><?= ucfirst($diasSemana[(int) date('w')]) ?>, <?= date('j') ?> de <?= $meses[(int) date('n')] ?></p>
        <h1><?= $saudacao ?>, <?= e($primeiroNome) ?> 👋</h1>
        <p class="saudacao-sub">Veja como está a loja e o que precisa da sua atenção.</p>
    </div>
    <?php if (temPerfil(['admin', 'vendedor'])): ?>
        <a class="btn btn-primary btn-lg d-print-none" href="index.php?page=vendas"><i class="bi bi-plus-lg" aria-hidden="true"></i> Nova venda</a>
    <?php endif; ?>
</header>

<div class="painel-topo">
    <section class="card tarefas">
        <div class="card-titulo">
            <h2>Para fazer hoje</h2>
            <?php if ($tarefas): ?><span class="selo selo-neutro"><?= count($tarefas) ?></span><?php endif; ?>
        </div>
        <?php if (!$tarefas): ?>
            <div class="estado-vazio">
                <span class="estado-vazio-icone"><i class="bi bi-check2-circle" aria-hidden="true"></i></span>
                <strong>Tudo em dia</strong>
                <p>Nada vencido, nada acabando e o backup está em ordem.</p>
            </div>
        <?php else: ?>
            <ul class="tarefas-lista">
                <?php foreach ($tarefas as [$grav, $icone, $titulo, $detalhe, $link]): ?>
                    <li>
                        <?= $link ? '<a class="tarefa" href="' . $link . '">' : '<div class="tarefa">' ?>
                            <span class="tarefa-icone tarefa-<?= $grav ?>"><i class="bi bi-<?= $icone ?>" aria-hidden="true"></i></span>
                            <span class="tarefa-texto"><strong><?= e($titulo) ?></strong><small><?= e($detalhe) ?></small></span>
                            <?php if ($link): ?><i class="bi bi-chevron-right tarefa-seta" aria-hidden="true"></i><?php endif; ?>
                        <?= $link ? '</a>' : '</div>' ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="resumo-faturamento" aria-label="Faturamento">
        <?php foreach ($resumo as $i => [$rotulo, $base, $valor, $pct]): ?>
            <div class="kpi <?= $i === 0 ? 'kpi-destaque' : '' ?>">
                <span><?= $rotulo ?></span>
                <strong><?= moeda($valor) ?></strong>
                <small><?= $variacao($pct) ?> <?= $base ?></small>
            </div>
        <?php endforeach; ?>
    </section>
</div>

<section class="card card-body mb-3">
    <div class="card-titulo card-titulo-quebra">
        <div>
            <h2>Desempenho</h2>
            <p class="card-sub"><?= date('d/m/Y', strtotime($de)) ?> a <?= date('d/m/Y', strtotime($ate)) ?></p>
        </div>
        <?php require BASE_PATH . '/app/Views/filtro_periodo.php'; ?>
    </div>

    <div class="kpis kpis-periodo">
        <div class="kpi kpi-principal"><span><i class="bi bi-cash-stack" aria-hidden="true"></i> Faturamento</span><strong><?= moeda($r['faturamento']) ?></strong></div>
        <div class="kpi"><span><i class="bi bi-bag-check" aria-hidden="true"></i> Vendas realizadas</span><strong><?= $r['vendas'] ?></strong></div>
        <div class="kpi"><span><i class="bi bi-receipt" aria-hidden="true"></i> Ticket médio</span><strong><?= moeda($r['ticket']) ?></strong></div>
        <div class="kpi"><span><i class="bi bi-graph-up" aria-hidden="true"></i> Lucro bruto estimado</span><strong><?= moeda($r['lucro']) ?></strong><?php if ($r['margem'] !== null): ?><small><?= number_format($r['margem'], 1, ',', '.') ?>% de margem</small><?php endif; ?></div>
        <div class="kpi"><span><i class="bi bi-wallet2" aria-hidden="true"></i> Despesas registradas</span><strong><?= moeda($r['despesas']) ?></strong></div>
        <div class="kpi <?= $r['resultado'] < 0 ? 'kpi-erro' : 'kpi-ok' ?>"><span><i class="bi bi-calculator" aria-hidden="true"></i> Resultado estimado</span><strong class="<?= $r['resultado'] < 0 ? 'negativo' : 'positivo' ?>"><?= moeda($r['resultado']) ?></strong></div>
    </div>
    <p class="nota"><i class="bi bi-info-circle" aria-hidden="true"></i> Lucro bruto = faturamento − custo dos produtos vendidos. Resultado = lucro bruto − despesas. Produtos sem preço de custo cadastrado entram com custo zero.</p>
</section>

<section class="grade">
    <div class="card card-body mb-3">
        <h2>Faturamento no período</h2>
        <?php require BASE_PATH . '/app/Views/grafico_faturamento.php'; ?>
    </div>

    <div class="card card-body mb-3">
        <h2>Mais vendidos no período</h2>
        <?php require BASE_PATH . '/app/Views/grafico_mais_vendidos.php'; ?>
    </div>
</section>

<?php require BASE_PATH . '/app/Views/alertas.php'; ?>
