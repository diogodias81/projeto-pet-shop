<?php
// Quadro dos pets atrasados para voltar (Dashboard e Clientes). Espera $retornos = Agenda::retornos() e, se houver, $doses (Cliente::dosesAVencer)
if (!$retornos) return;
$dosePorPet = array_column($doses ?? [], null, 'pet_id'); // pet que também está no quadro de doses: a mesma mensagem lembra as duas coisas
$primeiros = 5; // o resto fica atrás de "Mostrar todos": o quadro não empurra a busca nem os números da tela
$linha = function (array $r) use ($dosePorPet): void { ?>
                <tr>
                    <td><?= Cliente::ICONES_ESPECIE[$r['especie']] ?? '' ?> <strong><?= e($r['pet']) ?></strong><br><small><a href="index.php?page=clientes&amp;id=<?= (int) $r['cliente_id'] ?>"><?= e($r['cliente']) ?></a></small></td>
                    <td class="text-nowrap"><?= date('d/m', strtotime($r['ultimo'])) ?> <span class="selo selo-alerta">há <?= $r['faz_dias'] ?> dias</span><br><small><?= e($r['servico']) ?></small></td>
                    <td class="text-nowrap">a cada <?= $r['intervalo'] ?> dias<?php if (isset($dosePorPet[$r['pet_id']])): ?><br><small class="selo selo-neutro">+ dose da carteirinha</small><?php endif; ?></td>
                    <td class="acoes text-nowrap">
                        <?php if (Cliente::celular($r['telefone'])): ?>
                            <a class="btn btn-light btn-sm" href="<?= e(Cliente::whatsapp($r['telefone'], Agenda::mensagemRetorno($r, $dosePorPet[$r['pet_id']] ?? null))) ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-whatsapp" aria-hidden="true"></i> Lembrar<span class="visually-hidden"> o dono de <?= e($r['pet']) ?> (abre o WhatsApp)</span></a>
                        <?php else: ?>
                            <a class="btn btn-light btn-sm" href="tel:<?= e($r['telefone']) ?>" title="Telefone fixo: sem WhatsApp"><i class="bi bi-telephone" aria-hidden="true"></i> Ligar<span class="visually-hidden"> para o dono de <?= e($r['pet']) ?></span></a>
                        <?php endif; ?>
                        <a class="btn btn-light btn-sm" href="index.php?page=agenda&amp;pet=<?= (int) $r['pet_id'] ?>"><i class="bi bi-calendar-plus" aria-hidden="true"></i> Agendar<span class="visually-hidden"> <?= e($r['pet']) ?></span></a>
                    </td>
                </tr>
<?php }; ?>
<section class="card card-body mb-3 quadro-aviso" id="retornos" data-recolher>
    <div class="card-titulo">
        <div>
            <h2>Retorno do banho e tosa</h2>
            <p class="card-sub">Pets que passaram do intervalo de costume e não têm horário marcado (até <?= Agenda::RETORNO_LIMITE_DIAS ?> dias sem vir)</p>
        </div>
    </div>
    <div class="table-responsive"><table class="table align-middle">
        <thead><tr><th>Pet</th><th>Última visita</th><th>Costuma vir</th><th><span class="visually-hidden">Ações</span></th></tr></thead>
        <tbody><?php foreach (array_slice($retornos, 0, $primeiros) as $r) $linha($r); ?></tbody>
        <?php if (count($retornos) > $primeiros): ?>
            <tbody id="retornos-resto" hidden><?php foreach (array_slice($retornos, $primeiros) as $r) $linha($r); ?></tbody>
        <?php endif; ?>
    </table></div>
    <?php if (count($retornos) > $primeiros): ?>
        <button type="button" class="btn btn-light btn-sm align-self-start" data-mostrar-mais="retornos-resto">Mostrar todos (<?= count($retornos) ?>)</button>
    <?php endif; ?>
</section>
