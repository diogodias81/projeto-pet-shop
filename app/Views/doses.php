<?php
// Quadro das próximas doses (Dashboard e Clientes). Espera $doses = Cliente::dosesAVencer(...) e $avisoDoses
if (!$doses) return;
$primeiros = 5; // o resto fica atrás de "Mostrar todas": o quadro não empurra a busca nem os números da tela
$linha = function (array $d): void { ?>
                <tr>
                    <td><?= Cliente::ICONES_ESPECIE[$d['especie']] ?? '' ?> <strong><?= e($d['pet']) ?></strong><br><small><a href="index.php?page=clientes&amp;id=<?= (int) $d['cliente_id'] ?>#carteirinha"><?= e($d['cliente']) ?></a></small></td>
                    <td><?= e(Cliente::TIPOS_DOSE[$d['tipo']]) ?>: <?= e($d['nome']) ?></td>
                    <td class="text-nowrap"><?= date('d/m', strtotime($d['proxima_em'])) ?>
                        <span class="selo <?= $d['dias'] < 0 ? 'selo-erro' : 'selo-alerta' ?>"><?= $d['dias'] < 0 ? 'vencida' : ($d['dias'] == 0 ? 'hoje' : ($d['dias'] == 1 ? 'amanhã' : "em {$d['dias']} dias")) ?></span></td>
                    <td class="acoes">
                        <?php if (Cliente::celular($d['telefone'])): ?>
                            <a class="btn btn-light btn-sm" href="<?= e(Cliente::whatsapp($d['telefone'], Cliente::mensagemDose($d))) ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-whatsapp" aria-hidden="true"></i> Lembrar<span class="visually-hidden"> o dono de <?= e($d['pet']) ?> (abre o WhatsApp)</span></a>
                        <?php else: ?>
                            <a class="btn btn-light btn-sm" href="tel:<?= e($d['telefone']) ?>" title="Telefone fixo: sem WhatsApp"><i class="bi bi-telephone" aria-hidden="true"></i> Ligar<span class="visually-hidden"> para o dono de <?= e($d['pet']) ?></span></a>
                        <?php endif; ?>
                    </td>
                </tr>
<?php }; ?>
<section class="card card-body mb-3 quadro-aviso" id="doses" data-recolher>
    <div class="card-titulo">
        <div>
            <h2>Vacinas, vermífugos e antipulgas</h2>
            <p class="card-sub">Próximas doses até <?= $avisoDoses ?> dias e as vencidas há até <?= Cliente::DOSE_VENCIDA_AVISO_DIAS ?> dias</p>
        </div>
    </div>
    <div class="table-responsive"><table class="table align-middle">
        <thead><tr><th>Pet</th><th>Dose</th><th>Próxima</th><th><span class="visually-hidden">Lembrar</span></th></tr></thead>
        <tbody><?php foreach (array_slice($doses, 0, $primeiros) as $d) $linha($d); ?></tbody>
        <?php if (count($doses) > $primeiros): ?>
            <tbody id="doses-resto" hidden><?php foreach (array_slice($doses, $primeiros) as $d) $linha($d); ?></tbody>
        <?php endif; ?>
    </table></div>
    <?php if (count($doses) > $primeiros): ?>
        <button type="button" class="btn btn-light btn-sm align-self-start" data-mostrar-mais="doses-resto">Mostrar todas (<?= count($doses) ?>)</button>
    <?php endif; ?>
</section>
