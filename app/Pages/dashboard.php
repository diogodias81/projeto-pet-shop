<?php
[$de, $ate] = Relatorio::datas($_GET);
$hoje = date('Y-m-d');
$r = Relatorio::periodo($de, $ate);
$top = Relatorio::maisVendidos(8, $de, $ate);
$serie = Relatorio::serie($de, $ate);
?>
<section class="cards">
    <div class="card"><span>Faturamento hoje</span><strong><?= moeda(Relatorio::faturamento($hoje, $hoje)) ?></strong></div>
    <div class="card"><span>Faturamento nos últimos 7 dias</span><strong><?= moeda(Relatorio::faturamento(date('Y-m-d', strtotime('-6 days')), $hoje)) ?></strong></div>
    <div class="card"><span>Faturamento no mês</span><strong><?= moeda(Relatorio::faturamento(date('Y-m-01'), $hoje)) ?></strong></div>
</section>

<section class="painel">
    <h2>Período: <?= date('d/m/Y', strtotime($de)) ?> a <?= date('d/m/Y', strtotime($ate)) ?></h2>
    <?php require BASE_PATH . '/app/Views/filtro_periodo.php'; ?>

    <div class="cards">
        <div class="card"><span>Faturamento</span><strong><?= moeda($r['faturamento']) ?></strong></div>
        <div class="card"><span>Vendas realizadas</span><strong><?= $r['vendas'] ?></strong></div>
        <div class="card"><span>Ticket médio</span><strong><?= moeda($r['ticket']) ?></strong></div>
        <div class="card"><span>Lucro bruto estimado<?= $r['margem'] !== null ? ' (' . number_format($r['margem'], 1, ',', '.') . '% de margem)' : '' ?></span><strong><?= moeda($r['lucro']) ?></strong></div>
        <div class="card"><span>Despesas registradas</span><strong><?= moeda($r['despesas']) ?></strong></div>
        <div class="card <?= $r['resultado'] < 0 ? 'card-erro' : '' ?>"><span>Resultado estimado</span><strong class="<?= $r['resultado'] < 0 ? 'negativo' : 'positivo' ?>"><?= moeda($r['resultado']) ?></strong></div>
    </div>
    <p class="nota">Lucro bruto = faturamento − custo dos produtos vendidos. Resultado = lucro bruto − despesas. Produtos sem preço de custo cadastrado entram com custo zero.</p>
</section>

<section class="grade">
    <div class="painel">
        <h2>Faturamento no período</h2>
        <?php require BASE_PATH . '/app/Views/grafico_faturamento.php'; ?>
    </div>

    <div class="painel">
        <h2>Mais vendidos no período</h2>
        <?php require BASE_PATH . '/app/Views/grafico_mais_vendidos.php'; ?>
    </div>
</section>

<?php require BASE_PATH . '/app/Views/alertas.php'; ?>
