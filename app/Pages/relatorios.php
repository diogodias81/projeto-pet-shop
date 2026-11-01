<?php

[$de, $ate] = Relatorio::datas($_GET);

// Canceladas e devolvidas por inteiro ficam de fora; devoluções parciais já vêm descontadas em "liquido".
$vendas = array_filter(
    Venda::listar(['de' => $de, 'ate' => $ate, 'status' => 'concluida'], 5000),
    fn($v) => !($v['total'] > 0 && $v['valor_devolvido'] >= $v['total'])
);

$r = Relatorio::periodo($de, $ate);

$produtos = Relatorio::maisVendidos(1000, $de, $ate);

$top = array_slice($produtos, 0, 10);

$despesasCat = Despesa::porCategoria($de, $ate);

$movs = Relatorio::movimentacoes($de, $ate);

$validade = Estoque::alertasValidade();

// lucro, margem (sobre o preço de venda) e acréscimo (sobre o custo) de cada produto
foreach ($produtos as &$p) {
    $p['lucro'] = $p['receita'] - $p['custo'];
    $p['margem'] = $p['receita'] > 0 ? $p['lucro'] / $p['receita'] * 100 : null;
    $p['acrescimo'] = $p['custo'] > 0 ? $p['lucro'] / $p['custo'] * 100 : null;
}
unset($p);

$n2 = fn($v) => number_format((float) $v, 2, ',', '');
$pct = fn($v) => $v === null ? '—' : number_format($v, 1, ',', '.') . '%';

if (isset($_GET['exportar'])) {

    $sufixo = "_{$de}_a_{$ate}.csv";

    match ($_GET['exportar']) {
        'vendas' => exportarCsv('vendas' . $sufixo, ['Venda', 'Data', 'Vendedor', 'Pagamento', 'Itens', 'Subtotal', 'Desconto', 'Devolvido', 'Total líquido'],
            array_map(fn($v) => [$v['id'], dataBr($v['criado_em']), $v['vendedor'], Venda::FORMAS[$v['forma_pagamento']] ?? $v['forma_pagamento'], $v['itens'], $n2($v['subtotal']), $n2($v['desconto']), $n2($v['valor_devolvido']), $n2($v['liquido'])], array_values($vendas))),
        'produtos' => exportarCsv('rentabilidade' . $sufixo, ['Produto', 'Unidades', 'Receita', 'Custo', 'Lucro bruto', 'Margem %', 'Acréscimo sobre o custo %'],
            array_map(fn($p) => [$p['nome'], $p['qtd'], $n2($p['receita']), $n2($p['custo']), $n2($p['lucro']), $p['margem'] === null ? '' : $n2($p['margem']), $p['acrescimo'] === null ? '' : $n2($p['acrescimo'])], $produtos)),
        'despesas' => exportarCsv('despesas' . $sufixo, ['Data', 'Descrição', 'Categoria', 'Valor', 'Registrada por'],
            array_map(fn($d) => [date('d/m/Y', strtotime($d['data'])), $d['descricao'], Despesa::CATEGORIAS[$d['categoria']] ?? $d['categoria'], $n2($d['valor']), $d['usuario']], Despesa::listar($de, $ate))),
        'movimentacoes' => exportarCsv('movimentacoes' . $sufixo, ['Data', 'Tipo', 'Produto', 'Lote', 'Quantidade', 'Venda', 'Usuário', 'Observação'],
            array_map(fn($m) => [dataBr($m['criado_em']), Estoque::TIPOS[$m['tipo']], $m['produto'], $m['lote'], $m['quantidade'], $m['venda_id'], $m['usuario'], $m['observacao']], $movs)),
        'validade' => exportarCsv('validade_' . date('Y-m-d') . '.csv', ['Produto', 'Lote', 'Validade', 'Quantidade', 'Dias para vencer'],
            array_map(fn($l) => [$l['produto'], $l['codigo'], date('d/m/Y', strtotime($l['validade'])), $l['quantidade'], $l['dias']], $validade)),
        default => paginaErro(404, 'Relatório não encontrado.'),
    };
}

$exportar = fn(string $tipo) => 'index.php?' . e(http_build_query(['page' => 'relatorios', 'de' => $de, 'ate' => $ate, 'exportar' => $tipo]));

?>

<section class="card card-body mb-3">

    <h2>
        Relatório gerencial
        <span class="so-imprimir">· <?= date('d/m/Y', strtotime($de)) ?> a <?= date('d/m/Y', strtotime($ate)) ?></span>
    </h2>

    <?php require BASE_PATH . '/app/Views/filtro_periodo.php'; ?>

    <p class="nao-imprimir">
        <button type="button" class="btn btn-light" data-imprimir>Imprimir / Salvar PDF</button>
    </p>

</section>

<section class="kpis">
    <div class="kpi"><span>Faturamento</span><strong><?= moeda($r['faturamento']) ?></strong></div>
    <div class="kpi"><span>Vendas realizadas</span><strong><?= $r['vendas'] ?></strong></div>
    <div class="kpi"><span>Ticket médio</span><strong><?= moeda($r['ticket']) ?></strong></div>
    <div class="kpi"><span>Custo dos produtos vendidos</span><strong><?= moeda($r['custo']) ?></strong></div>
    <div class="kpi"><span>Lucro bruto (<?= $pct($r['margem']) ?>)</span><strong><?= moeda($r['lucro']) ?></strong></div>
    <div class="kpi"><span>Despesas</span><strong><?= moeda($r['despesas']) ?></strong></div>
    <div class="kpi <?= $r['resultado'] < 0 ? 'kpi-erro' : '' ?>"><span>Resultado estimado</span><strong class="<?= $r['resultado'] < 0 ? 'negativo' : 'positivo' ?>"><?= moeda($r['resultado']) ?></strong></div>
</section>

<section class="card card-body mb-3" data-recolher>

    <h2>Rentabilidade por produto <a class="btn btn-light btn-sm nao-imprimir" href="<?= $exportar('produtos') ?>">Exportar planilha</a></h2>

    <?php if (!$produtos): ?>

        <p class="vazio">Sem vendas no período.</p>

    <?php else: ?>

        <div class="table-responsive"><table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Produto</th>
                    <th class="num">Unidades</th>
                    <th class="num">Receita</th>
                    <th class="num">Custo</th>
                    <th class="num">Lucro bruto</th>
                    <th class="num">Margem</th>
                    <th class="num">Acréscimo s/ custo</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($produtos as $p): ?>
                    <tr>
                        <td><?= e($p['nome']) ?><?= $p['sem_custo'] ? ' <small class="selo selo-alerta" title="Há vendas sem preço de custo cadastrado: o lucro fica superestimado">sem custo</small>' : '' ?></td>
                        <td class="num"><?= $p['qtd'] ?></td>
                        <td class="num"><?= moeda($p['receita']) ?></td>
                        <td class="num"><?= moeda($p['custo']) ?></td>
                        <td class="num <?= $p['lucro'] < 0 ? 'negativo' : '' ?>"><?= moeda($p['lucro']) ?></td>
                        <td class="num"><?= $pct($p['margem']) ?></td>
                        <td class="num"><?= $pct($p['acrescimo']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>

        <p class="nota">Margem = lucro ÷ preço de venda. Acréscimo sobre o custo = lucro ÷ custo. Ex.: custo R$ 30 e venda R$ 50 dão 40% de margem e 66,7% de acréscimo.</p>

    <?php endif; ?>

</section>

<section class="grade">

    <div class="card card-body mb-3">
        <h2>Mais vendidos (unidades)</h2>
        <?php require BASE_PATH . '/app/Views/grafico_mais_vendidos.php'; ?>
    </div>

    <div class="card card-body mb-3">

        <h2>Despesas por categoria <a class="btn btn-light btn-sm nao-imprimir" href="<?= $exportar('despesas') ?>">Exportar planilha</a></h2>

        <?php if (!$despesasCat): ?>

            <p class="vazio">Nenhuma despesa no período.</p>

        <?php else: ?>

            <div class="table-responsive"><table class="table table-hover align-middle">
                <tbody>
                    <?php foreach ($despesasCat as $cat => $valor): ?>
                        <tr>
                            <td><?= e(Despesa::CATEGORIAS[$cat] ?? $cat) ?></td>
                            <td class="num"><?= moeda($valor) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th>Total</th>
                        <th class="num"><?= moeda($r['despesas']) ?></th>
                    </tr>
                </tfoot>
            </table></div>

        <?php endif; ?>

    </div>

</section>

<section class="card card-body mb-3" data-recolher>

    <h2>Vendas do período <a class="btn btn-light btn-sm nao-imprimir" href="<?= $exportar('vendas') ?>">Exportar planilha</a></h2>

    <?php if (!$vendas): ?>

        <p class="vazio">Sem vendas no período.</p>

    <?php else: ?>

        <div class="table-responsive"><table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Data</th>
                    <th>Vendedor</th>
                    <th>Pagamento</th>
                    <th class="num">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($vendas as $v): ?>
                    <tr>
                        <td><?= $v['id'] ?></td>
                        <td><?= dataBr($v['criado_em']) ?></td>
                        <td><?= e($v['vendedor']) ?></td>
                        <td><?= e(Venda::FORMAS[$v['forma_pagamento']] ?? $v['forma_pagamento']) ?></td>
                        <td class="num"><?= moeda($v['liquido']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>

    <?php endif; ?>

</section>

<section class="card card-body mb-3" data-recolher>

    <h2>Movimentações de estoque <a class="btn btn-light btn-sm nao-imprimir" href="<?= $exportar('movimentacoes') ?>">Exportar planilha</a></h2>

    <?php if (!$movs): ?>

        <p class="vazio">Nenhuma movimentação no período.</p>

    <?php else: ?>

        <div class="table-responsive"><table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Tipo</th>
                    <th>Produto</th>
                    <th>Lote</th>
                    <th class="num">Qtd.</th>
                    <th>Usuário</th>
                    <th>Observação</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($movs as $m): ?>
                    <tr>
                        <td><?= dataBr($m['criado_em']) ?></td>
                        <td><?= e(Estoque::TIPOS[$m['tipo']]) ?><?= $m['venda_id'] ? ' #' . $m['venda_id'] : '' ?></td>
                        <td><?= e($m['produto']) ?></td>
                        <td><?= e($m['lote'] ?? '—') ?></td>
                        <td class="num"><?= $m['quantidade'] > 0 ? '+' . $m['quantidade'] : $m['quantidade'] ?></td>
                        <td><?= e($m['usuario'] ?? '—') ?></td>
                        <td><?= e($m['observacao'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>

    <?php endif; ?>

</section>

<section class="card card-body mb-3" data-recolher>

    <h2>Lotes vencidos ou vencendo em até <?= Estoque::prazos()[0] ?> dias <a class="btn btn-light btn-sm nao-imprimir" href="<?= $exportar('validade') ?>">Exportar planilha</a></h2>

    <?php if (!$validade): ?>

        <p class="vazio">Nada vencido nem vencendo nos próximos <?= Estoque::prazos()[0] ?> dias.</p>

    <?php else: ?>

        <div class="table-responsive"><table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Produto</th>
                    <th>Lote</th>
                    <th>Validade</th>
                    <th class="num">Qtd.</th>
                    <th class="num">Dias</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($validade as $l): ?>
                    <tr>
                        <td><?= e($l['produto']) ?></td>
                        <td><?= e($l['codigo']) ?></td>
                        <td><?= date('d/m/Y', strtotime($l['validade'])) ?></td>
                        <td class="num"><?= $l['quantidade'] ?></td>
                        <td class="num <?= $l['dias'] < 0 ? 'negativo' : '' ?>"><?= $l['dias'] < 0 ? 'vencido' : $l['dias'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>

    <?php endif; ?>

</section>
