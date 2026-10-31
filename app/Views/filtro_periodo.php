<?php
// Filtro de período (atalhos + De/Até). Espera $pagina, $de, $ate.
$atual = $_GET['periodo'] ?? '';
?>
<form method="get" class="linha">

    <input type="hidden" name="page" value="<?= e($pagina) ?>">

    <div class="atalhos">
        <?php foreach (['hoje' => 'Hoje', 'semana' => 'Últimos 7 dias', 'mes' => 'Este mês'] as $chave => $rotulo): ?>
            <a class="btn <?= $atual === $chave ? 'btn-primary' : 'btn-light' ?> btn-sm" href="index.php?page=<?= e($pagina) ?>&amp;periodo=<?= $chave ?>"><?= $rotulo ?></a>
        <?php endforeach; ?>
    </div>

    <label>
        De
        <input type="date" name="de" value="<?= e($de) ?>">
    </label>

    <label>
        Até
        <input type="date" name="ate" value="<?= e($ate) ?>">
    </label>

    <button class="btn btn-light">Filtrar</button>

</form>

<?php if ($de > $ate): ?>
    <div class="alert alert-danger">A data "De" é depois da data "Até": troque as datas para ver o período.</div>
<?php endif; ?>
