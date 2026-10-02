<?php
$r = Relatorio::resumo();
$top = Relatorio::maisVendidos(5, date('Y-m-01'), date('Y-m-d'));
$baixo = Produto::baixoEstoque();
$dias = Relatorio::faturamentoPorDia(7);
$maximo = max($dias) ?: 1;
?>
<section class="cards">
    <div class="card"><span>Faturamento hoje</span><strong><?= moeda($r['faturamento_hoje']) ?></strong></div>
    <div class="card"><span>Vendas hoje</span><strong><?= $r['vendas_hoje'] ?></strong></div>
    <div class="card"><span>Faturamento no mês</span><strong><?= moeda($r['faturamento_mes']) ?></strong></div>
    <div class="card <?= $r['baixo_estoque'] ? 'card-alerta' : '' ?>"><span>Produtos com estoque baixo</span><strong><?= $r['baixo_estoque'] ?></strong></div>
</section>

<section class="grade">
    <div class="painel">
        <h2>Faturamento dos últimos 7 dias</h2>
        <div class="barras">```php
            <?php

            $r = Relatorio::resumo();

            $top = Relatorio::maisVendidos(
                5,
                date('Y-m-01'),
                date('Y-m-d')
            );

            $baixo = Produto::baixoEstoque();

            $dias = Relatorio::faturamentoPorDia(7);

            $maximo = max($dias) ?: 1;

            ?>

            <section class="cards">

                <div class="card">
                    <span>Faturamento hoje</span>
                    <strong>
                        <?= moeda($r['faturamento_hoje']) ?>
                    </strong>
                </div>

                <div class="card">
                    <span>Vendas hoje</span>
                    <strong>
                        <?= $r['vendas_hoje'] ?>
                    </strong>
                </div>

                <div class="card">
                    <span>Faturamento no mês</span>
                    <strong>
                        <?= moeda($r['faturamento_mes']) ?>
                    </strong>
                </div>

                <div class="card <?= $r['baixo_estoque'] ? 'card-alerta' : '' ?>">
                    <span>Produtos com estoque baixo</span>
                    <strong>
                        <?= $r['baixo_estoque'] ?>
                    </strong>
                </div>

            </section>

            <section class="grade">

                <div class="painel">

                    <h2>Faturamento dos últimos 7 dias</h2>

                    <div class="barras">

                        <?php foreach ($dias as $dia => $total): ?>

                            <div
                                class="barra"
                                title="<?= moeda($total) ?>">

                                <div
                                    class="barra-valor"
                                    style="height: <?= round($total / $maximo * 100) ?>%"></div>

                                <small>
                                    <?= date('d/m', strtotime($dia)) ?>
                                </small>

                            </div>

                        <?php endforeach; ?>

                    </div>

                </div>

                <div class="painel">

                    <h2>Mais vendidos no mês</h2>

                    <?php if (!$top): ?>

                        <p class="vazio">
                            Ainda não há vendas neste mês.
                        </p>

                    <?php else: ?>

                        <table>

                            <thead>

                                <tr>
                                    <th>Produto</th>
                                    <th class="num">Qtd.</th>
                                    <th class="num">Receita</th>
                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach ($top as $t): ?>

                                    <tr>

                                        <td>
                                            <?= e($t['nome']) ?>
                                        </td>

                                        <td class="num">
                                            <?= $t['qtd'] ?>
                                        </td>

                                        <td class="num">
                                            <?= moeda($t['receita']) ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    <?php endif; ?>

                </div>

            </section>

            <section class="painel">

                <h2>Reposição necessária</h2>

                <?php if (!$baixo): ?>

                    <p class="vazio">
                        Nenhum produto abaixo do estoque mínimo.
                    </p>

                <?php else: ?>

                    <table>

                        <thead>

                            <tr>
                                <th>Produto</th>
                                <th>Categoria</th>
                                <th class="num">Estoque</th>
                                <th class="num">Mínimo</th>
                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach ($baixo as $p): ?>

                                <tr>

                                    <td>
                                        <?= e($p['nome']) ?>
                                    </td>

                                    <td>
                                        <?= e($p['categoria']) ?>
                                    </td>

                                    <td class="num">

                                        <span class="selo selo-alerta">
                                            <?= $p['estoque'] ?>
                                        </span>

                                    </td>

                                    <td class="num">
                                        <?= $p['estoque_minimo'] ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                <?php endif; ?>

            </section>
         

            <?php foreach ($dias as $dia => $total): ?>
                <div class="barra" title="<?= moeda($total) ?>">
                    <div class="barra-valor" style="height: <?= round($total / $maximo * 100) ?>%"></div>
                    <small><?= date('d/m', strtotime($dia)) ?></small>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="painel">
        <h2>Mais vendidos no mês</h2>
        <?php if (!$top): ?><p class="vazio">Ainda não há vendas neste mês.</p><?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Produto</th>
                        <th class="num">Qtd.</th>
                        <th class="num">Receita</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($top as $t): ?>
                        <tr>
                            <td><?= e($t['nome']) ?></td>
                            <td class="num"><?= $t['qtd'] ?></td>
                            <td class="num"><?= moeda($t['receita']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table><?php endif; ?>
    </div>
</section>

<section class="painel">
    <h2>Reposição necessária</h2>
    <?php if (!$baixo): ?><p class="vazio">Nenhum produto abaixo do estoque mínimo.</p><?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Produto</th>
                    <th>Categoria</th>
                    <th class="num">Estoque</th>
                    <th class="num">Mínimo</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($baixo as $p): ?>
                    <tr>
                        <td><?= e($p['nome']) ?></td>
                        <td><?= e($p['categoria']) ?></td>
                        <td class="num"><span class="selo selo-alerta"><?= $p['estoque'] ?></span></td>
                        <td class="num"><?= $p['estoque_minimo'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table><?php endif; ?>
</section>