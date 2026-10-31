<?php
// Painel de alertas de estoque (Dashboard e Estoque): validade por prazo e estoque baixo.
$alertasValidade = Estoque::alertasValidade();
$alertasBaixo = Produto::baixoEstoque();
$prazos = Estoque::prazos();
?>

<section class="card card-body mb-3" id="alertas">

    <h2>Alertas de estoque</h2>

    <?php if (!$alertasValidade && !$alertasBaixo): ?>

        <p class="vazio">Nenhum alerta: nada vencido, nada vencendo nos próximos <?= $prazos[0] ?> dias e nenhum produto abaixo do mínimo.</p>

    <?php else: ?>

        <div class="kpis">
            <?php $vencidos = count(array_filter($alertasValidade, fn($l) => $l['dias'] < 0)); ?>
            <div class="kpi <?= $vencidos ? 'kpi-erro' : '' ?>"><span>Lotes vencidos</span><strong><?= $vencidos ?></strong></div>
            <?php foreach (array_reverse($prazos) as $p): ?>
                <?php $n = count(array_filter($alertasValidade, fn($l) => $l['dias'] >= 0 && $l['dias'] <= $p)); ?>
                <div class="kpi <?= $n ? 'kpi-alerta' : '' ?>"><span>Vencem em até <?= $p ?> dias</span><strong><?= $n ?></strong></div>
            <?php endforeach; ?>
            <div class="kpi <?= $alertasBaixo ? 'kpi-alerta' : '' ?>"><span>Estoque baixo</span><strong><?= count($alertasBaixo) ?></strong></div>
        </div>

        <?php if ($alertasValidade): ?>

            <h3>Validade</h3>

            <div class="table-responsive"><table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Produto</th>
                        <th>Lote</th>
                        <th>Validade</th>
                        <th class="num">Qtd. restante</th>
                        <th>Situação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($alertasValidade as $l): ?>
                        <tr>
                            <td><?= e($l['produto']) ?></td>
                            <td><?= e($l['codigo']) ?></td>
                            <td><?= date('d/m/Y', strtotime($l['validade'])) ?></td>
                            <td class="num"><?= $l['quantidade'] ?></td>
                            <td>
                                <?php if ($l['dias'] < 0): ?>
                                    <span class="selo selo-erro">Vencido há <?= -$l['dias'] ?> dia(s): retirar</span>
                                <?php elseif ($l['dias'] === 0): ?>
                                    <span class="selo selo-erro">Vence hoje</span>
                                <?php else: ?>
                                    <span class="selo <?= Estoque::faixa($l['dias']) === min($prazos) ? 'selo-erro' : 'selo-alerta' ?>">Vence em <?= $l['dias'] ?> dia(s)</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>

        <?php endif; ?>

        <?php if ($alertasBaixo): ?>

            <h3>Reposição necessária</h3>

            <div class="table-responsive"><table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Produto</th>
                        <th>Categoria</th>
                        <th class="num">Estoque</th>
                        <th class="num">Mínimo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($alertasBaixo as $p): ?>
                        <tr>
                            <td><?= e($p['nome']) ?></td>
                            <td><?= e($p['categoria'] ?? '—') ?></td>
                            <td class="num"><span class="selo selo-alerta"><?= $p['estoque'] ?></span></td>
                            <td class="num"><?= $p['estoque_minimo'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>

        <?php endif; ?>

    <?php endif; ?>

</section>
