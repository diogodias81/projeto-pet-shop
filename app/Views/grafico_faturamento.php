<?php
// Colunas de faturamento por dia/mês. Espera $serie = [rótulo => total].
$maximo = max($serie ?: [0]) ?: 1;
$passo = (int) ceil(count($serie) / 12); // no máximo ~12 rótulos para não embolar
$ultimo = count($serie) - 1;
$i = 0;
// Mostra um rótulo a cada $passo e sempre o último dia; esconde o anterior se ficar colado nele
$mostrar = fn(int $i) => $i === $ultimo || ($i % $passo === 0 && $ultimo - $i >= $passo);
?>
<?php if (!$serie): ?>
    <p class="vazio">Período inválido.</p>
<?php else: ?>
    <div class="barras" role="img" aria-label="Faturamento no período">
        <?php foreach ($serie as $rotulo => $total): ?>
            <div class="barra" title="<?= e($rotulo) ?>: <?= moeda($total) ?>">
                <div class="barra-valor" style="height: <?= round($total / $maximo * 100) ?>%"></div>
                <small class="<?= $mostrar($i++) ? '' : 'oculto' ?>"><?= e($rotulo) ?></small>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
