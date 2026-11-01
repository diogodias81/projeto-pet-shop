<?php
// Colunas de faturamento por dia/mês. Espera $serie = [rótulo => total].
$maximo = max($serie ?: [0]) ?: 1;
$ultimo = count($serie) - 1;
$i = 0;
// Com no máximo $cabem rótulos: um a cada $passo e sempre o último dia; esconde o que ficaria colado no último
$mostrar = function (int $i, int $cabem) use ($ultimo): bool {
    $passo = (int) ceil(($ultimo + 1) / $cabem);
    return $i === $ultimo || ($i % $passo === 0 && $ultimo - $i >= $passo);
};
// Computador: até 12 rótulos; celular: até 6 (cada um calculado por inteiro, para nunca encostarem)
$classe = fn(int $i) => trim(($mostrar($i, 12) ? '' : 'oculto') . ' ' . ($mostrar($i, 6) ? 'celular' : 'sem-celular'));
?>
<?php if (!$serie): ?>
    <p class="vazio">Período inválido.</p>
<?php else: ?>
    <div class="barras" role="img" aria-label="Faturamento no período">
        <?php foreach ($serie as $rotulo => $total): ?>
            <div class="barra" title="<?= e($rotulo) ?>: <?= moeda($total) ?>">
                <div class="barra-valor" style="height: <?= round($total / $maximo * 100) ?>%"></div>
                <small class="<?= $classe($i) ?>"><?= e($rotulo) ?></small>
                <?php $i++; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
