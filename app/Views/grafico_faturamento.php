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
<?php elseif (!array_filter($serie)): // tudo zero: barras vazias pareciam um gráfico quebrado ?>
    <p class="vazio">Sem vendas no período.</p>
<?php else: ?>
    <?php
    // Valor curto em cima da barra ("R$ 1,2 mil"): o title não aparece no celular nem no leitor de tela
    $curto = fn(float $v) => $v >= 1000 ? 'R$ ' . number_format($v / 1000, 1, ',', '.') . ' mil' : 'R$ ' . number_format($v, 0, ',', '.');
    $descricao = implode('; ', array_map(fn($r, $t) => "$r: " . moeda($t), array_keys($serie), $serie));
    ?>
    <div class="barras<?= count($serie) > 12 ? ' barras-muitas' : '' ?>" role="img" aria-label="Faturamento no período. <?= e($descricao) ?>">
        <?php foreach ($serie as $rotulo => $total): ?>
            <div class="barra" title="<?= e($rotulo) ?>: <?= moeda($total) ?>">
                <span class="barra-num"><?= $total > 0 ? e($curto($total)) : '' ?></span>
                <div class="barra-valor" style="height: <?= round($total / $maximo * 75) ?>%"></div>
                <small class="<?= $classe($i) ?>"><?= e($rotulo) ?></small>
                <?php $i++; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
