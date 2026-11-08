<?php
// Agenda de banho e tosa de um dia (?dia=AAAA-MM-DD) e, para o admin, os serviços oferecidos

$ehAdmin = temPerfil(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  $acao = (string) ($_POST['acao'] ?? '');
  $diaVolta = Relatorio::dataValida($_POST['dia'] ?? '') ? $_POST['dia'] : date('Y-m-d');
  $volta = ['dia' => $diaVolta];

  try {

    if ($acao === 'agendar') {
      Agenda::agendar($_POST);
      $volta['dia'] = (string) $_POST['data']; // validada em agendar()
      flash('Agendamento marcado.');
    } elseif ($acao === 'status') {
      Agenda::mudarStatus((int) ($_POST['id'] ?? 0), (string) ($_POST['status'] ?? ''));
      // pago adiantado: "pronto" é gravado como "pago"
      $gravado = Agenda::buscar((int) $_POST['id'])['status'] ?? '';
      flash('Situação atualizada: ' . ($gravado === 'pago' && $_POST['status'] === 'pronto' ? 'pronto e pago' : mb_strtolower(Agenda::STATUS[$_POST['status']])) . '.');
    } elseif ($ehAdmin && $acao === 'salvar_servico') {
      Agenda::salvarServico($_POST);
      flash('Serviço salvo.');
      $volta['servicos'] = 1;
    } elseif ($ehAdmin && $acao === 'ativar_servico') {
      Agenda::ativarServico((int) ($_POST['id'] ?? 0), (bool) ($_POST['ativo'] ?? 0));
      flash(($_POST['ativo'] ?? 0) ? 'Serviço reativado.' : 'Serviço desativado: não aparece mais para agendar.');
      $volta['servicos'] = 1;
    } else {
      paginaErro(403, 'Ação não permitida.');
    }
  } catch (DomainException $ex) {

    flash($ex->getMessage(), 'erro');

    if ($acao === 'agendar') {
      $_SESSION['form_agenda'] = array_intersect_key($_POST, array_flip(['pet_id', 'servico_id', 'data', 'hora', 'observacao']));
    } elseif (in_array($acao, ['salvar_servico', 'ativar_servico'], true)) {
      // volta para a tabela de serviços com o que foi digitado
      $volta['servicos'] = 1;
      if ($acao === 'salvar_servico') $_SESSION['form_servico'] = array_intersect_key($_POST, array_flip(['id', 'nome', 'preco', 'duracao_min']));
    }
  }

  redirect('agenda', $volta);
}

$antigo = $_SESSION['form_agenda'] ?? [];
$antigoServico = $_SESSION['form_servico'] ?? [];
unset($_SESSION['form_agenda'], $_SESSION['form_servico']);

$dia = Relatorio::dataValida($_GET['dia'] ?? '') ? $_GET['dia'] : date('Y-m-d');
$hoje = date('Y-m-d');
$agendamentos = Agenda::dia($dia);
$servicosAtivos = Agenda::servicos();
$pets = Cliente::petsAtivos();

// Semana do dia escolhido (segunda a sábado) com quantos atendimentos há em cada dia
$segunda = date('Y-m-d', strtotime('monday this week', strtotime($dia)));
$semana = array_map(fn($i) => date('Y-m-d', strtotime("$segunda +$i days")), range(0, 6));
$porDia = Agenda::contarPorDia($semana[0], $semana[6]);
$diasSemana = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
$link = fn(array $q) => 'index.php?' . e(http_build_query(['page' => 'agenda'] + $q));

$ativos = array_filter($agendamentos, fn($a) => in_array($a['status'], Agenda::OCUPA, true));
$aReceber = array_sum(array_map(fn($a) => Agenda::aCobrar($a) ? $a['preco'] : 0, $agendamentos));
$subtituloTopo = date('d/m/Y', strtotime($dia)) . ' · ' . count($ativos) . (count($ativos) === 1 ? ' atendimento' : ' atendimentos') . ($aReceber > 0 ? ' · ' . moeda($aReceber) . ' a receber' : '');

$abrirNovo = $antigo || isset($_GET['novo']) || isset($_GET['pet']);
$petEscolhido = (int) ($antigo['pet_id'] ?? ($_GET['pet'] ?? 0));
$acoesTopo = ($ehAdmin ? '<a class="btn btn-light" href="' . $link(['dia' => $dia, 'servicos' => 1]) . '#servicos"><i class="bi bi-scissors" aria-hidden="true"></i> Serviços</a> ' : '')
  . '<button class="btn btn-primary" type="button" data-bs-toggle="offcanvas" data-bs-target="#gaveta-agenda" aria-controls="gaveta-agenda"><i class="bi bi-plus-lg" aria-hidden="true"></i> Agendar</button>';

$seloStatus = ['agendado' => 'selo-neutro', 'em_atendimento' => 'selo-alerta', 'pronto' => 'selo-ok', 'pago' => 'selo-ok', 'faltou' => 'selo-erro', 'cancelado' => 'selo-erro'];
$rotuloPasso = ['em_atendimento' => ['Iniciar', 'play-fill', 'btn-primary'], 'pronto' => ['Pronto', 'check2', 'btn-primary'], 'faltou' => ['Faltou', 'person-x', 'btn-light'], 'cancelado' => ['Cancelar', 'x-lg', 'btn-light'], 'agendado' => ['Reabrir', 'arrow-counterclockwise', 'btn-light'], 'voltar' => ['Voltar ao atendimento', 'arrow-counterclockwise', 'btn-light']];

?>

<div
  class="offcanvas offcanvas-end gaveta"
  tabindex="-1"
  id="gaveta-agenda"
  aria-labelledby="gaveta-agenda-titulo"
  <?= $abrirNovo ? 'data-abrir' : '' ?>>

  <div class="offcanvas-header">
    <h2 class="offcanvas-title" id="gaveta-agenda-titulo">Novo agendamento</h2>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Fechar"></button>
  </div>

  <form autocomplete="off" method="post" class="offcanvas-body gaveta-form">

    <?= csrf_campo() ?>
    <input type="hidden" name="acao" value="agendar">
    <input type="hidden" name="dia" value="<?= e($dia) ?>">

    <?php if (!$pets || !$servicosAtivos): ?>
      <div class="alert alert-warning">
        <?= !$pets ? 'Cadastre o cliente e o pet dele em <a href="index.php?page=clientes">Clientes</a> antes de agendar.' : 'Nenhum serviço ativo: ' . ($ehAdmin ? 'cadastre em "Serviços".' : 'peça ao administrador para cadastrar.') ?>
      </div>
    <?php endif; ?>

    <label>
      Pet
      <?php if (count($pets) > 8): ?>
        <input type="search" data-filtrar-select="pet_id" placeholder="Buscar pet ou dono" aria-label="Buscar pet ou dono" autocomplete="off" class="mb-1">
      <?php endif; ?>
      <select name="pet_id" required>
        <option value="">Escolha…</option>
        <?php $dono = null; foreach ($pets as $p): ?>
          <?php if ($dono !== $p['cliente_id']): ?><?= $dono !== null ? '</optgroup>' : '' ?><optgroup label="<?= e($p['cliente']) ?>"><?php $dono = $p['cliente_id']; endif; ?>
          <option value="<?= $p['id'] ?>" <?= $petEscolhido === (int) $p['id'] ? 'selected' : '' ?>><?= Cliente::ICONES_ESPECIE[$p['especie']] ?? '' ?> <?= e($p['nome']) ?> (<?= e($p['cliente']) ?>)</option>
        <?php endforeach; ?>
        <?= $dono !== null ? '</optgroup>' : '' ?>
      </select>
      <small><a href="index.php?page=clientes&amp;novo=1">Cliente novo? Cadastre aqui</a></small>
    </label>

    <label>
      Serviço
      <select name="servico_id" required>
        <option value="">Escolha…</option>
        <?php foreach ($servicosAtivos as $s): ?>
          <option value="<?= $s['id'] ?>" data-duracao="<?= (int) $s['duracao_min'] ?>" <?= (int) ($antigo['servico_id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['nome']) ?> — <?= moeda($s['preco']) ?> (<?= $s['duracao_min'] ?> min)</option>
        <?php endforeach; ?>
      </select>
    </label>

    <div class="campos-2">
      <label>
        Data
        <input name="data" type="date" required min="<?= $hoje ?>" max="<?= date('Y-m-d', strtotime('+1 year')) ?>" value="<?= e($antigo['data'] ?? max($dia, $hoje)) ?>">
      </label>

      <label>
        Horário
        <script type="application/json" id="agenda-ocupados"><?= json_encode(Agenda::ocupados()) ?></script>
        <select name="hora" required data-hoje="<?= $hoje ?>" data-agora="<?= date('H:i') ?>" data-fecha="<?= e(config('agenda_fecha')) ?>" data-limite="<?= max(1, (int) config('banho_tosa_simultaneos')) ?>">
          <option value="">Escolha…</option>
          <?php foreach (Agenda::horarios() as $h): ?>
            <option value="<?= $h ?>" <?= ($antigo['hora'] ?? '') === $h ? 'selected' : '' ?>><?= $h ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>

    <label>
      Observação <small>(opcional)</small>
      <input name="observacao" maxlength="200" placeholder="Ex.: tosa bem curta; buscar às 16h" value="<?= e($antigo['observacao'] ?? '') ?>">
    </label>

    <p class="nota"><i class="bi bi-info-circle" aria-hidden="true"></i> Atendimentos ao mesmo tempo: <?= max(1, (int) config('banho_tosa_simultaneos')) ?>. Horário da agenda: <?= e(config('agenda_abre')) ?> às <?= e(config('agenda_fecha')) ?>.</p>

    <div class="gaveta-rodape">
      <button type="button" class="btn btn-light" data-bs-dismiss="offcanvas">Cancelar</button>
      <button class="btn btn-primary">Agendar</button>
    </div>

  </form>

</div>

<section class="card card-body mb-3">

  <div class="card-titulo card-titulo-quebra">
    <nav class="semana" aria-label="Dias da semana">
      <a class="btn btn-light btn-quadrado" href="<?= $link(['dia' => date('Y-m-d', strtotime("$dia -7 days"))]) ?>" title="Semana anterior" aria-label="Semana anterior"><i class="bi bi-chevron-left" aria-hidden="true"></i></a>
      <?php foreach ($semana as $d): ?>
        <a class="semana-dia<?= $d === $dia ? ' active' : '' ?><?= $d === $hoje ? ' hoje' : '' ?>" href="<?= $link(['dia' => $d]) ?>"<?= $d === $dia ? ' aria-current="date"' : '' ?>>
          <small><?= $diasSemana[(int) date('w', strtotime($d))] ?></small>
          <strong><?= date('d', strtotime($d)) ?></strong>
          <span class="semana-qtd"><?= ($porDia[$d] ?? 0) ?: '' ?></span>
        </a>
      <?php endforeach; ?>
      <a class="btn btn-light btn-quadrado" href="<?= $link(['dia' => date('Y-m-d', strtotime("$dia +7 days"))]) ?>" title="Próxima semana" aria-label="Próxima semana"><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
    </nav>

    <form method="get" class="filtro-datas">
      <input type="hidden" name="page" value="agenda">
      <label><span class="visually-hidden">Ir para o dia</span><input type="date" name="dia" value="<?= e($dia) ?>" aria-label="Ir para o dia"></label>
      <button class="btn btn-light">Ir</button>
      <?php if ($dia !== $hoje): ?><a class="btn btn-light" href="<?= $link([]) ?>">Hoje</a><?php endif; ?>
    </form>
  </div>

  <?php if (!$agendamentos): ?>

    <div class="estado-vazio">
      <span class="estado-vazio-icone"><i class="bi bi-calendar-week" aria-hidden="true"></i></span>
      <strong>Nenhum agendamento neste dia</strong>
      <p>Use "Agendar" para marcar banho ou tosa.</p>
    </div>

  <?php else: ?>

    <ul class="agenda-lista">
      <?php foreach ($agendamentos as $a): ?>
        <?php $apagado = in_array($a['status'], ['faltou', 'cancelado'], true); ?>
        <li class="agenda-item<?= $apagado ? ' apagado' : '' ?>">

          <div class="agenda-hora">
            <strong><?= substr($a['inicio'], 11, 5) ?></strong>
            <small>até <?= substr($a['fim'], 11, 5) ?></small>
          </div>

          <div class="agenda-info">
            <div class="agenda-linha">
              <span class="pet-emoji" aria-hidden="true"><?= Cliente::ICONES_ESPECIE[$a['especie']] ?? '🐾' ?></span>
              <span class="pet-texto">
                <strong><?= e($a['pet']) ?> <span class="selo <?= $seloStatus[$a['status']] ?>"><?= e(Agenda::STATUS[$a['status']]) ?></span><?php if ($a['venda_id'] && $a['status'] !== 'pago'): ?> <span class="selo selo-ok">Pago</span><?php endif; ?></strong>
                <small><?= e($a['servico']) ?> · <?= moeda($a['preco']) ?><?= $a['raca'] ? ' · ' . e($a['raca']) : '' ?></small>
                <small>
                  <a href="index.php?page=clientes&amp;id=<?= (int) $a['cliente_id'] ?>"><?= e($a['cliente']) ?></a>
                  · <a href="tel:+55<?= e($a['telefone']) ?>"><?= e(Cliente::formatarTelefone($a['telefone'])) ?></a>
                </small>
                <?php if ($a['pet_obs']): ?><small class="pet-obs"><i class="bi bi-exclamation-circle" aria-hidden="true"></i> <?= e($a['pet_obs']) ?></small><?php endif; ?>
                <?php if ($a['observacao']): ?><small><i class="bi bi-chat-left-text" aria-hidden="true"></i> <?= e($a['observacao']) ?></small><?php endif; ?>
              </span>
            </div>
          </div>

          <div class="agenda-acoes">
            <?php // pago adiantado: sem Faltou/Cancelar (o dinheiro volta cancelando a venda); de pet/cliente excluído: nada muda
            $passos = !$a['dono_ativo'] ? [] : array_diff(Agenda::PASSOS[$a['status']], $a['venda_id'] ? ['faltou', 'cancelado'] : [], $a['inicio'] > date('Y-m-d H:i') ? ['faltou'] : []); ?>
            <?php foreach ($passos as $passo): ?>
              <?php [$rotulo, $icone, $classe] = $rotuloPasso[$a['status'] === 'pronto' ? 'voltar' : $passo];
              if ($a['status'] === 'em_atendimento' && $passo === 'agendado') $rotulo = 'Voltar para agendado'; ?>
              <form method="post"<?= in_array($passo, ['faltou', 'cancelado'], true) ? ' data-confirmar="Marcar ' . e($a['pet']) . ' como ' . mb_strtolower(Agenda::STATUS[$passo]) . '?"' : '' ?>>
                <?= csrf_campo() ?>
                <input type="hidden" name="acao" value="status">
                <input type="hidden" name="id" value="<?= $a['id'] ?>">
                <input type="hidden" name="status" value="<?= $passo ?>">
                <input type="hidden" name="dia" value="<?= e($dia) ?>">
                <button class="btn <?= $classe ?> btn-sm"><i class="bi bi-<?= $icone ?>" aria-hidden="true"></i> <?= $rotulo ?><span class="visually-hidden"> — <?= e($a['pet']) ?>, <?= substr($a['inicio'], 11, 5) ?></span></button>
              </form>
            <?php endforeach; ?>
            <?php // WhatsApp com a mensagem pronta: lembrete enquanto o horário não passou, aviso quando ficou pronto ?>
            <?php // "pago" (pronto e pago) também avisa, mas só no dia do atendimento
            $prontoHoje = $a['status'] === 'pronto' || ($a['status'] === 'pago' && substr($a['inicio'], 0, 10) === date('Y-m-d'));
            $zap = $prontoHoje ? ['pronto', 'Avisar que está pronto'] : ($a['status'] === 'agendado' && $a['inicio'] >= date('Y-m-d H:i') ? ['lembrete', 'Lembrar'] : null); ?>
            <?php if ($zap && $a['telefone'] && $a['dono_ativo']): ?>
              <a class="btn btn-light btn-sm" href="<?= e(Cliente::whatsapp($a['telefone'], Agenda::mensagem($a, $zap[0]))) ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-whatsapp" aria-hidden="true"></i> <?= $zap[1] ?><span class="visually-hidden"> (abre o WhatsApp)</span></a>
            <?php endif; ?>
            <?php if (Agenda::aCobrar($a) && $a['dono_ativo']): ?>
              <a class="btn btn-sm <?= $a['status'] === 'pronto' ? 'btn-primary' : 'btn-light' ?>" href="index.php?page=vendas&amp;agendamento=<?= $a['id'] ?>"><i class="bi bi-cart3" aria-hidden="true"></i> Cobrar no caixa<span class="visually-hidden"> — <?= e($a['pet']) ?>, <?= substr($a['inicio'], 11, 5) ?></span></a>
            <?php elseif ($a['venda_id']): ?>
              <a class="btn btn-light btn-sm" href="index.php?page=comprovante&amp;id=<?= (int) $a['venda_id'] ?>"><i class="bi bi-receipt" aria-hidden="true"></i> Venda #<?= (int) $a['venda_id'] ?></a>
            <?php endif; ?>
          </div>

        </li>
      <?php endforeach; ?>
    </ul>

  <?php endif; ?>

</section>

<?php if ($ehAdmin && (isset($_GET['servicos']) || !$servicosAtivos)): ?>

  <section class="card card-body mb-3" id="servicos">
    <div class="card-titulo">
      <div>
        <h2>Serviços</h2>
        <p class="card-sub">O preço vale para os próximos agendamentos; os já marcados mantêm o preço do dia.</p>
      </div>
      <a class="btn btn-light btn-sm" href="<?= $link(['dia' => $dia]) ?>">Fechar</a>
    </div>

    <div class="table-responsive"><table class="table align-middle tabela-servicos">
      <thead>
        <tr><th>Serviço</th><th>Preço (R$)</th><th>Duração (min)</th><th><span class="visually-hidden">Ações</span></th></tr>
      </thead>
      <tbody>
        <?php foreach (array_merge(Agenda::servicos(false), [null]) as $s): // a última linha é a de cadastro ?>
          <?php $fid = 'serv-' . ($s['id'] ?? 'novo');
          // linha que deu erro: mostra o que foi digitado
          $digitado = $antigoServico && (string) ($antigoServico['id'] ?? '') === (string) ($s['id'] ?? '') ? $antigoServico : null;
          $vS = fn(string $campo, string $atual) => $digitado ? (string) ($digitado[$campo] ?? '') : $atual; ?>
          <tr<?= $s && !$s['ativo'] ? ' class="apagado"' : '' ?>>
            <td><input form="<?= $fid ?>" name="nome" required maxlength="80" value="<?= e($vS('nome', $s['nome'] ?? '')) ?>" placeholder="Ex.: Banho porte P" aria-label="Nome do serviço"></td>
            <td><input form="<?= $fid ?>" name="preco" required inputmode="decimal" value="<?= e($vS('preco', $s ? number_format((float) $s['preco'], 2, ',', '.') : '')) ?>" placeholder="0,00" aria-label="Preço"></td>
            <td><input form="<?= $fid ?>" name="duracao_min" required type="number" min="15" max="480" step="15" value="<?= e($vS('duracao_min', (string) ($s ? (int) $s['duracao_min'] : 60))) ?>" aria-label="Duração em minutos"></td>
            <td class="acoes">
              <form method="post" id="<?= $fid ?>">
                <?= csrf_campo() ?>
                <input type="hidden" name="acao" value="salvar_servico">
                <input type="hidden" name="id" value="<?= $s['id'] ?? '' ?>">
                <input type="hidden" name="dia" value="<?= e($dia) ?>">
                <button class="btn btn-sm <?= $s ? 'btn-light' : 'btn-primary' ?>"><?= $s ? 'Salvar' : '<i class="bi bi-plus-lg" aria-hidden="true"></i> Adicionar' ?></button>
              </form>
              <?php if ($s): ?>
                <form method="post">
                  <?= csrf_campo() ?>
                  <input type="hidden" name="acao" value="ativar_servico">
                  <input type="hidden" name="id" value="<?= $s['id'] ?>">
                  <input type="hidden" name="ativo" value="<?= $s['ativo'] ? 0 : 1 ?>">
                  <input type="hidden" name="dia" value="<?= e($dia) ?>">
                  <button class="btn btn-light btn-sm"><?= $s['ativo'] ? 'Desativar' : 'Reativar' ?></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>

<?php endif; ?>
