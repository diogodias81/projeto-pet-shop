<?php

$de = $_GET['de'] ?? date('Y-m-01');

$ate = $_GET['ate'] ?? date('Y-m-d');

$vendas = Venda::listar($de, $ate, 1000);

$total = array_sum(array_column($vendas, 'total'));

$ticket = $vendas ? $total / count($vendas) : 0;

$top = Relatorio::maisVendidos(10, $de, $ate);

?>

<section class="painel">

    <form method="get" class="linha">

        <input
            type="hidden"
            name="page"
            value="relatorios"
        >

        <label>
            De

            <input
                type="date"
                name="de"
                value="<?= e($de) ?>"
            >
        </label>

        <label>
            Até

            <input
                type="date"
                name="ate"
                value="<?= e($ate) ?>"
            >
        </label>

        <button
            type="submit"
            class="btn"
        >
            Gerar relatório
        </button>

        <button
            type="button"
            class="btn btn-claro"
            onclick="window.print()"
        >
            Imprimir
        </button>

    </form>

</section>

<section class="cards">

    <div class="card">

        <span>Faturamento no período</span>

        <strong>
            <?= moeda($total) ?>
        </strong>

    </div>

    <div class="card">

        <span>Vendas realizadas</span>

        <strong>
            <?= count($vendas) ?>
        </strong>

    </div>

    <div class="card">

        <span>Ticket médio</span>

        <strong>
            <?= moeda($ticket) ?>
        </strong>

    </div>

</section>

<section class="grade">

    <div class="painel">

        <h2>Produtos mais vendidos</h2>

        <?php if (!$top): ?>

            <p class="vazio">
                Sem vendas no período.
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

    <div class="painel">

        <h2>Vendas do período</h2>

        <?php if (!$vendas): ?>

            <p class="vazio">
                Sem vendas no período.
            </p>

        <?php else: ?>

            <table>

                <thead>

                    <tr>
                        <th>#</th>
                        <th>Data</th>
                        <th>Vendedor</th>
                        <th class="num">Total</th>
                    </tr>

                </thead>

                <tbody>

                    <?php foreach ($vendas as $v): ?>

                        <tr>

                            <td>
                                <?= $v['id'] ?>
                            </td>

                            <td>
                                <?= dataBr($v['criado_em']) ?>
                            </td>

                            <td>
                                <?= e($v['vendedor']) ?>
                            </td>

                            <td class="num">
                                <?= moeda($v['total']) ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        <?php endif; ?>

    </div>

</section>

