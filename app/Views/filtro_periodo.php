<?php
// Filtro de período (atalhos + De/Até). Espera $pagina, $de, $ate.

?>
<form method="get" class="filtro-periodo d-print-none">

    <input type="hidden" name="page" value="<?= e($pagina) ?>">

    <nav class="segmentado" aria-label="Atalhos de período">
        <?php $aceso = false; ?>
        <?php foreach (['hoje' => 'Hoje', 'semana' => '7 dias', 'mes' => 'Este mês'] as $chave => $rotulo): ?>
            <?php $ativo = !$aceso && Relatorio::datas(['periodo' => $chave]) === [$de, $ate]; $aceso = $aceso || $ativo; // aceso pelas datas (vale depois de filtrar); no dia 1º Hoje = Este mês: acende só o primeiro ?>
            <a class="<?= $ativo ? 'active' : '' ?>" href="index.php?page=<?= e($pagina) ?>&amp;periodo=<?= $chave ?>"<?= $ativo ? ' aria-current="true"' : '' ?>><?= $rotulo ?></a>
        <?php endforeach; ?>
    </nav>

    <div class="filtro-datas">
        <label>
            <span class="visually-hidden">De</span>
            <input type="date" name="de" value="<?= e($de) ?>" aria-label="De">
        </label>
        <span class="filtro-ate" aria-hidden="true">→</span>
        <label>
            <span class="visually-hidden">Até</span>
            <input type="date" name="ate" value="<?= e($ate) ?>" aria-label="Até">
        </label>
        <button class="btn btn-light" aria-label="Filtrar período"><i class="bi bi-funnel" aria-hidden="true"></i> Filtrar</button>
    </div>

</form>

<?php if ($de > $ate): ?>
    <div class="alert alert-danger">A data "De" é depois da data "Até": troque as datas para ver o período.</div>
<?php endif; ?>
