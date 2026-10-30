<?php
// Barras horizontais dos mais vendidos (unidades). Espera $top de Relatorio::maisVendidos().
$maiorQtd = max(array_column($top, 'qtd') ?: [1]);
?>
<?php if (!$top): ?>
    <p class="vazio">Sem vendas no período.</p>
<?php else: ?>
    <div class="hbarras">
        <?php foreach ($top as $t): ?>
            <div class="hbarra" title="<?= e($t['nome']) ?>: <?= $t['qtd'] ?> un. · <?= moeda($t['receita']) ?>">
                <span><?= e($t['nome']) ?></span>
                <div class="hbarra-trilho"><div class="hbarra-valor" style="width: <?= round($t['qtd'] / $maiorQtd * 100) ?>%"></div></div>
                <strong><?= $t['qtd'] ?> un.</strong>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
