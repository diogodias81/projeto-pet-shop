<?php
// Lista de clientes e, com ?id=, a ficha do cliente (dados, pets e compras)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  $acao = (string) ($_POST['acao'] ?? '');
  $clienteId = (int) ($_POST['cliente_id'] ?? $_POST['id'] ?? 0);

  try {

    if ($acao === 'excluir_cliente') {
      Cliente::excluir($clienteId);
      flash('Cliente excluído. As compras dele continuam no histórico.');
      redirect('clientes');
    }

    if ($acao === 'salvar_pet') {
      Cliente::salvarPet($_POST);
      flash((int) ($_POST['id'] ?? 0) ? 'Pet alterado.' : 'Pet cadastrado.');
    } elseif ($acao === 'salvar_dose') {
      Cliente::salvarDose($_POST);
      flash('Registrado na carteirinha.');
    } elseif ($acao === 'excluir_dose') {
      Cliente::excluirDose((int) ($_POST['id'] ?? 0), $clienteId);
      flash('Registro excluído da carteirinha.');
    } elseif ($acao === 'excluir_pet') {
      Cliente::excluirPet((int) ($_POST['id'] ?? 0), $clienteId);
      flash('Pet excluído.');
    } else {
      $novo = !(int) ($_POST['id'] ?? 0);
      $clienteId = Cliente::salvar($_POST);
      flash($novo ? 'Cliente cadastrado.' : 'Cliente alterado.');
      // veio do caixa: volta para a venda já com o cliente (o carrinho fica guardado no navegador)
      if ($novo && ($_POST['voltar'] ?? '') === 'vendas') redirect('vendas', ['cliente' => $clienteId]);
    }
  } catch (DomainException $ex) {

    flash($ex->getMessage(), 'erro');

    // Só os campos do formulário (o $_POST inteiro enchia a sessão)
    if ($acao === 'salvar_pet') {
      $_SESSION['form_pet'] = array_intersect_key($_POST, array_flip(['id', 'cliente_id', 'nome', 'especie', 'raca', 'porte', 'nascimento', 'observacao']));
    } elseif ($acao === 'salvar_dose') {
      $_SESSION['form_dose'] = array_intersect_key($_POST, array_flip(['pet_id', 'tipo', 'nome', 'aplicada_em', 'proxima_em', 'observacao']));
    } elseif ($acao === 'salvar_cliente') {
      $_SESSION['form_cliente'] = array_intersect_key($_POST, array_flip(['id', 'nome', 'telefone', 'email', 'endereco', 'observacao', 'voltar']))
        + (str_starts_with($ex->getMessage(), 'Já existe cliente com este telefone') ? ['telefone_repetido' => 1] : []);
    }
  }

  redirect('clientes', $clienteId ? ['id' => $clienteId] : []);
}

$antigoCliente = $_SESSION['form_cliente'] ?? [];
$antigoPet = $_SESSION['form_pet'] ?? [];
$antigoDose = $_SESSION['form_dose'] ?? [];
unset($_SESSION['form_cliente'], $_SESSION['form_pet'], $_SESSION['form_dose']);

$id = (int) ($_GET['id'] ?? 0);
$cliente = $id ? Cliente::buscar($id) : null;

if ($id && !$cliente) {
  flash('Cliente não encontrado: ele pode ter sido excluído.', 'erro');
  redirect('clientes');
}

// Gaveta do cliente: novo (lista) ou editar (ficha)
$editandoCliente = $cliente && (isset($_GET['editar']) || $antigoCliente);
$abrirCliente = $antigoCliente || isset($_GET['novo']) || $editandoCliente;
$vCliente = fn(string $campo) => $antigoCliente[$campo] ?? ($editandoCliente ? ($campo === 'telefone' ? Cliente::formatarTelefone($cliente['telefone']) : $cliente[$campo]) : '') ?? '';

?>

<div
  class="offcanvas offcanvas-end gaveta"
  tabindex="-1"
  id="gaveta-cliente"
  aria-labelledby="gaveta-cliente-titulo"
  <?= $abrirCliente ? 'data-abrir' : '' ?>>

  <div class="offcanvas-header">
    <div>
      <h2 class="offcanvas-title" id="gaveta-cliente-titulo"><?= $editandoCliente ? 'Editar cliente' : 'Novo cliente' ?></h2>
      <?php if ($editandoCliente): ?><p class="card-sub"><?= e($cliente['nome']) ?></p><?php endif; ?>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Fechar"></button>
  </div>

  <form autocomplete="off" method="post" class="offcanvas-body gaveta-form">

    <?= csrf_campo() ?>
    <input type="hidden" name="acao" value="salvar_cliente">
    <?php if (($antigoCliente['voltar'] ?? ($_GET['voltar'] ?? '')) === 'vendas'): ?><input type="hidden" name="voltar" value="vendas"><?php endif; ?>
    <input type="hidden" name="id" value="<?= $editandoCliente ? (int) $cliente['id'] : '' ?>">

    <label>
      Nome
      <input name="nome" required maxlength="100" placeholder="Ex.: Maria Souza" value="<?= e($vCliente('nome')) ?>">
    </label>

    <div class="campos-2">
      <label>
        Telefone (com DDD)
        <input name="telefone" required type="tel" inputmode="tel" maxlength="20" placeholder="(91) 98888-7777" value="<?= e($vCliente('telefone')) ?>">
      </label>

      <label>
        E-mail <small>(opcional)</small>
        <input name="email" type="email" maxlength="120" placeholder="maria@email.com" value="<?= e($vCliente('email')) ?>">
      </label>
    </div>

    <?php if (!empty($antigoCliente['telefone_repetido'])): // só depois do aviso de telefone repetido ?>
      <label class="form-check">
        <input class="form-check-input" type="checkbox" name="mesmo_telefone" value="1">
        <span class="form-check-label">Mesmo telefone de outro cliente (outra pessoa da mesma casa)</span>
      </label>
    <?php endif; ?>

    <label>
      Endereço <small>(opcional)</small>
      <input name="endereco" maxlength="200" placeholder="Rua, número, bairro" value="<?= e($vCliente('endereco')) ?>">
    </label>

    <label>
      Observação <small>(opcional)</small>
      <input name="observacao" maxlength="200" placeholder="Ex.: prefere contato por WhatsApp" value="<?= e($vCliente('observacao')) ?>">
    </label>

    <div class="gaveta-rodape">
      <button type="button" class="btn btn-light" data-bs-dismiss="offcanvas">Cancelar</button>
      <button class="btn btn-primary"><?= $editandoCliente ? 'Salvar alterações' : 'Cadastrar cliente' ?></button>
    </div>

  </form>

</div>

<?php if (!$cliente): // ----------------------------- LISTA ----------------------------- ?>

<?php
$busca = trim((string) ($_GET['busca'] ?? ''));
$clientes = Cliente::listar($busca);
$um = count($clientes) === 1;
$subtituloTopo = count($clientes) . ($um ? ' cliente' : ' clientes') . ($busca !== '' ? ($um ? ' encontrado' : ' encontrados') : ($um ? ' cadastrado' : ' cadastrados'));
$acoesTopo = '<button class="btn btn-primary" type="button" data-bs-toggle="offcanvas" data-bs-target="#gaveta-cliente" aria-controls="gaveta-cliente"><i class="bi bi-plus-lg" aria-hidden="true"></i> Novo cliente</button>';
// o vendedor não tem Dashboard: as próximas doses aparecem aqui (fora da busca, para não empurrar o resultado)
$avisoDoses = (int) config('doses_aviso_dias');
$doses = $busca === '' ? Cliente::dosesAVencer($avisoDoses) : [];
?>

<?php require BASE_PATH . '/app/Views/doses.php'; ?>

<section class="card card-body mb-3">

  <form autocomplete="off" method="get" class="busca-barra" role="search">
    <input type="hidden" name="page" value="clientes">
    <label>
      <span class="visually-hidden">Pesquisar</span>
      <i class="bi bi-search" aria-hidden="true"></i>
      <input type="search" name="busca" value="<?= e($busca) ?>" aria-label="Pesquisar cliente" placeholder="Nome, telefone ou nome do pet">
    </label>
    <button class="btn btn-light">Buscar</button>
    <?php if ($busca !== ''): ?><a class="btn btn-light" href="index.php?page=clientes">Limpar</a><?php endif; ?>
  </form>

  <?php if (!$clientes): ?>

    <div class="estado-vazio">
      <span class="estado-vazio-icone"><i class="bi bi-person-heart" aria-hidden="true"></i></span>
      <strong><?= $busca !== '' ? 'Nenhum cliente encontrado' : 'Nenhum cliente ainda' ?></strong>
      <p><?= $busca !== '' ? 'Confira o nome ou o telefone, ou cadastre um cliente novo.' : 'Cadastre os clientes e os pets deles para ver o histórico de compras.' ?></p>
    </div>

  <?php else: ?>

    <div class="table-responsive"><table class="table table-hover align-middle">
      <thead>
        <tr>
          <th>Cliente</th>
          <th>Telefone</th>
          <th>Pets</th>
          <th>Última compra</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($clientes as $c): ?>
          <tr>
            <td>
              <a class="produto-celula" href="index.php?page=clientes&amp;id=<?= $c['id'] ?>">
                <span class="avatar avatar-sm" aria-hidden="true"><?= e(iniciais($c['nome'])) ?></span>
                <?= e($c['nome']) ?>
              </a>
            </td>
            <td class="text-nowrap"><?= e(Cliente::formatarTelefone($c['telefone'])) ?></td>
            <td><?= $c['qtd_pets'] ? e($c['nomes_pets']) : '<span class="vazio">—</span>' ?></td>
            <td class="text-nowrap"><?= $c['ultima_compra'] ? date('d/m/Y', strtotime($c['ultima_compra'])) : '<span class="vazio">—</span>' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>

  <?php endif; ?>

</section>

<?php else: // ----------------------------- FICHA ----------------------------- ?>

<?php
$eu = usuario();
// vendedor vê só as vendas que ele fez para este cliente (mesma regra da tela Histórico)
$soMinhas = $eu['perfil'] === 'vendedor' ? (int) $eu['id'] : null;
$resumo = Cliente::resumo($id, $soMinhas);
$compras = Venda::listar(['cliente_id' => $id, 'usuario_id' => $soMinhas], 100);
$tituloPagina = $cliente['nome'];
$subtituloTopo = Cliente::formatarTelefone($cliente['telefone']) . ($cliente['email'] ? ' · ' . $cliente['email'] : '');
$acoesTopo = '<a class="btn btn-light" href="index.php?page=clientes"><i class="bi bi-arrow-left" aria-hidden="true"></i> Clientes</a>'
  . ' <a class="btn btn-light" href="index.php?page=clientes&amp;id=' . $id . '&amp;editar=1"><i class="bi bi-pencil" aria-hidden="true"></i> Editar</a>'
  . ' <a class="btn btn-primary" href="index.php?page=vendas&amp;cliente=' . $id . '"><i class="bi bi-cart3" aria-hidden="true"></i> Nova venda</a>';

// Gaveta do pet: novo ou editar (?pet=ID)
$petId = (int) ($antigoPet['id'] ?? ($_GET['pet'] ?? 0));
$editandoPet = null;
foreach ($cliente['pets'] as $p) if ((int) $p['id'] === $petId) $editandoPet = $p;
$abrirPet = $antigoPet || isset($_GET['novo_pet']) || $editandoPet;
$vPet = fn(string $campo) => $antigoPet[$campo] ?? ($editandoPet[$campo] ?? '');
$seloSituacao = fn(array $v) => match (Venda::situacao($v)) {
  'Cancelada' => 'selo-erro', 'Devolvida', 'Devolução parcial' => 'selo-alerta', default => 'selo-ok',
};
$whats = '55' . $cliente['telefone'];

// Carteirinha: gaveta de nova dose (?dose=PET já escolhe o pet)
$doses = Cliente::doses($id);
$avisoDias = (int) config('doses_aviso_dias');
$abrirDose = $antigoDose || isset($_GET['dose']);
$petDose = (int) ($antigoDose['pet_id'] ?? ($_GET['dose'] ?? 0));
// ?dose= com pet que não é deste cliente: cai no padrão (o pet único já escolhido)
if (!in_array($petDose, array_map('intval', array_column($cliente['pets'], 'id')), true)) $petDose = count($cliente['pets']) === 1 ? (int) $cliente['pets'][0]['id'] : 0;
$vDose = fn(string $campo, string $padrao = '') => $antigoDose[$campo] ?? $padrao;
$hoje = date('Y-m-d');
?>

<div
  class="offcanvas offcanvas-end gaveta"
  tabindex="-1"
  id="gaveta-dose"
  aria-labelledby="gaveta-dose-titulo"
  <?= $abrirDose && $cliente['pets'] ? 'data-abrir' : '' ?>>

  <div class="offcanvas-header">
    <div>
      <h2 class="offcanvas-title" id="gaveta-dose-titulo">Registrar na carteirinha</h2>
      <p class="card-sub">vacina, vermífugo ou antipulgas</p>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Fechar"></button>
  </div>

  <form autocomplete="off" method="post" class="offcanvas-body gaveta-form">

    <?= csrf_campo() ?>
    <input type="hidden" name="acao" value="salvar_dose">
    <input type="hidden" name="cliente_id" value="<?= $id ?>">

    <label>
      Pet
      <select name="pet_id" required>
        <option value="">Escolha…</option>
        <?php foreach ($cliente['pets'] as $p): ?>
          <option value="<?= $p['id'] ?>" <?= $petDose === (int) $p['id'] ? 'selected' : '' ?>><?= Cliente::ICONES_ESPECIE[$p['especie']] ?? '' ?> <?= e($p['nome']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <fieldset>
      <legend>Tipo</legend>
      <div class="opcoes-icone opcoes-3">
        <?php foreach (Cliente::TIPOS_DOSE as $chave => $rotulo): ?>
          <label class="opcao-icone">
            <input type="radio" name="tipo" value="<?= $chave ?>" required <?= $vDose('tipo', 'vacina') === $chave ? 'checked' : '' ?>>
            <i class="bi bi-<?= Cliente::ICONES_DOSE[$chave] ?> opcao-emoji" aria-hidden="true"></i>
            <span><?= e($rotulo) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <label>
      Nome do produto
      <input name="nome" required maxlength="80" placeholder="Ex.: V10, antirrábica, Drontal, Bravecto" value="<?= e($vDose('nome')) ?>">
    </label>

    <div class="campos-2">
      <label>
        Aplicada em
        <input name="aplicada_em" type="date" required min="1990-01-01" max="<?= $hoje ?>" value="<?= e($vDose('aplicada_em', $hoje)) ?>">
      </label>
      <label>
        Próxima dose <small>(opcional)</small>
        <input name="proxima_em" type="date" value="<?= e($vDose('proxima_em')) ?>">
      </label>
    </div>

    <label>
      Observação <small>(opcional)</small>
      <input name="observacao" maxlength="200" placeholder="Ex.: aplicada pela clínica Vet Amigo; lote 123" value="<?= e($vDose('observacao')) ?>">
    </label>

    <p class="nota"><i class="bi bi-info-circle" aria-hidden="true"></i> Com a próxima dose preenchida, as telas Clientes e Dashboard avisam <?= $avisoDias ?> dias antes, com o botão do WhatsApp para lembrar o dono.</p>

    <div class="gaveta-rodape">
      <button type="button" class="btn btn-light" data-bs-dismiss="offcanvas">Cancelar</button>
      <button class="btn btn-primary">Registrar</button>
    </div>

  </form>

</div>


<div
  class="offcanvas offcanvas-end gaveta"
  tabindex="-1"
  id="gaveta-pet"
  aria-labelledby="gaveta-pet-titulo"
  <?= $abrirPet ? 'data-abrir' : '' ?>>

  <div class="offcanvas-header">
    <div>
      <h2 class="offcanvas-title" id="gaveta-pet-titulo"><?= $editandoPet ? 'Editar pet' : 'Novo pet' ?></h2>
      <p class="card-sub">de <?= e($cliente['nome']) ?></p>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Fechar"></button>
  </div>

  <form autocomplete="off" method="post" class="offcanvas-body gaveta-form">

    <?= csrf_campo() ?>
    <input type="hidden" name="acao" value="salvar_pet">
    <input type="hidden" name="cliente_id" value="<?= $id ?>">
    <input type="hidden" name="id" value="<?= $editandoPet ? (int) $editandoPet['id'] : '' ?>">

    <label>
      Nome do pet
      <input name="nome" required maxlength="60" placeholder="Ex.: Thor" value="<?= e($vPet('nome')) ?>">
    </label>

    <fieldset>
      <legend>Espécie</legend>
      <div class="opcoes-icone opcoes-3">
        <?php foreach (Cliente::ESPECIES as $chave => $rotulo): ?>
          <label class="opcao-icone">
            <input type="radio" name="especie" value="<?= $chave ?>" required <?= $vPet('especie') === $chave ? 'checked' : '' ?>>
            <span class="opcao-emoji" aria-hidden="true"><?= Cliente::ICONES_ESPECIE[$chave] ?></span>
            <span><?= e($rotulo) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <div class="campos-2">
      <label>
        Raça <small>(opcional)</small>
        <input name="raca" maxlength="60" placeholder="Ex.: Shih-tzu" value="<?= e($vPet('raca')) ?>">
      </label>

      <label>
        Porte <small>(opcional)</small>
        <select name="porte">
          <option value="">—</option>
          <?php foreach (Cliente::PORTES as $chave => $rotulo): ?>
            <option value="<?= $chave ?>" <?= $vPet('porte') === $chave ? 'selected' : '' ?>><?= e($rotulo) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>

    <label>
      Nascimento <small>(opcional, pode ser aproximado)</small>
      <input name="nascimento" type="date" min="1990-01-01" max="<?= date('Y-m-d') ?>" value="<?= e($vPet('nascimento')) ?>">
    </label>

    <label>
      Observação <small>(opcional)</small>
      <input name="observacao" maxlength="200" placeholder="Ex.: alérgico a shampoo com perfume; morde ao cortar unha" value="<?= e($vPet('observacao')) ?>">
    </label>

    <div class="gaveta-rodape">
      <button type="button" class="btn btn-light" data-bs-dismiss="offcanvas">Cancelar</button>
      <button class="btn btn-primary"><?= $editandoPet ? 'Salvar alterações' : 'Cadastrar pet' ?></button>
    </div>

  </form>

</div>

<div class="kpis kpis-periodo">
  <section class="kpi">
    <span><i class="bi bi-bag-check" aria-hidden="true"></i> Compras</span>
    <strong><?= (int) $resumo['compras'] ?></strong>
    <small><?= $soMinhas ? 'suas vendas, ' : '' ?>sem contar canceladas e devolvidas</small>
  </section>
  <section class="kpi kpi-principal">
    <span><i class="bi bi-cash-stack" aria-hidden="true"></i> Total gasto</span>
    <strong><?= moeda($resumo['gasto']) ?></strong>
    <small><?= $resumo['compras'] ? 'média de ' . moeda($resumo['gasto'] / $resumo['compras']) . ' por compra' : 'nenhuma compra ainda' ?></small>
  </section>
  <section class="kpi">
    <span><i class="bi bi-calendar-check" aria-hidden="true"></i> Última compra</span>
    <strong><?= $resumo['ultima'] ? date('d/m/Y', strtotime($resumo['ultima'])) : '—' ?></strong>
    <?php $dias = $resumo['ultima'] ? (int) (new DateTime(substr($resumo['ultima'], 0, 10)))->diff(new DateTime('today'))->days : null; ?>
    <small><?= $dias === null ? '' : ($dias === 0 ? 'hoje' : ($dias === 1 ? 'ontem' : "há $dias dias")) ?></small>
  </section>
</div>

<div class="painel-topo">

  <section class="card card-body">
    <div class="card-titulo">
      <h2>Pets</h2>
      <a class="btn btn-light btn-sm" href="index.php?page=clientes&amp;id=<?= $id ?>&amp;novo_pet=1"><i class="bi bi-plus-lg" aria-hidden="true"></i> Adicionar pet</a>
    </div>

    <?php if (!$cliente['pets']): ?>
      <p class="vazio">Nenhum pet cadastrado.</p>
    <?php else: ?>
      <ul class="lista-pets">
        <?php foreach ($cliente['pets'] as $p): ?>
          <li>
            <span class="pet-emoji" aria-hidden="true"><?= Cliente::ICONES_ESPECIE[$p['especie']] ?? '🐾' ?></span>
            <span class="pet-texto">
              <strong><?= e($p['nome']) ?></strong>
              <small><?= e(implode(' · ', array_filter([Cliente::ESPECIES[$p['especie']] ?? '', $p['raca'], $p['porte'] ? 'porte ' . mb_strtolower(Cliente::PORTES[$p['porte']] ?? '') : '', Cliente::idade($p['nascimento'])]))) ?></small>
              <?php if ($p['observacao']): ?><small class="pet-obs"><i class="bi bi-exclamation-circle" aria-hidden="true"></i> <?= e($p['observacao']) ?></small><?php endif; ?>
            </span>
            <span class="acoes">
              <a class="btn btn-light btn-sm" href="index.php?page=clientes&amp;id=<?= $id ?>&amp;dose=<?= $p['id'] ?>" aria-label="Registrar vacina ou vermífugo de <?= e($p['nome']) ?>"><i class="bi bi-shield-plus" aria-hidden="true"></i> Vacina</a>
              <a class="btn btn-light btn-sm" href="index.php?page=agenda&amp;pet=<?= $p['id'] ?>" aria-label="Agendar banho ou tosa para <?= e($p['nome']) ?>"><i class="bi bi-calendar-plus" aria-hidden="true"></i> Agendar</a>
              <a class="btn btn-light btn-sm" href="index.php?page=clientes&amp;id=<?= $id ?>&amp;pet=<?= $p['id'] ?>" aria-label="Editar o pet <?= e($p['nome']) ?>"><i class="bi bi-pencil" aria-hidden="true"></i> Editar</a>
              <form method="post" data-confirmar="Excluir o pet <?= e($p['nome']) ?>?">
                <?= csrf_campo() ?>
                <input type="hidden" name="acao" value="excluir_pet">
                <input type="hidden" name="cliente_id" value="<?= $id ?>">
                <input type="hidden" name="id" value="<?= $p['id'] ?>">
                <button class="btn btn-outline-danger btn-sm btn-quadrado" title="Excluir" aria-label="Excluir o pet <?= e($p['nome']) ?>"><i class="bi bi-trash3" aria-hidden="true"></i></button>
              </form>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="card card-body">
    <h2>Contato</h2>
    <dl class="dados-cliente">
      <dt>Telefone</dt>
      <dd>
        <a href="tel:+<?= e($whats) ?>"><?= e(Cliente::formatarTelefone($cliente['telefone'])) ?></a>
        · <a href="https://wa.me/<?= e($whats) ?>" target="_blank" rel="noopener noreferrer">WhatsApp <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a>
      </dd>
      <?php if ($cliente['email']): ?><dt>E-mail</dt><dd><a href="mailto:<?= e($cliente['email']) ?>"><?= e($cliente['email']) ?></a></dd><?php endif; ?>
      <?php if ($cliente['endereco']): ?><dt>Endereço</dt><dd><?= e($cliente['endereco']) ?></dd><?php endif; ?>
      <?php if ($cliente['observacao']): ?><dt>Observação</dt><dd><?= e($cliente['observacao']) ?></dd><?php endif; ?>
      <dt>Cliente desde</dt>
      <dd><?= date('d/m/Y', strtotime($cliente['criado_em'])) ?></dd>
    </dl>
    <form method="post" data-confirmar="Excluir o cliente <?= e($cliente['nome']) ?>? As compras dele continuam no histórico." class="mt-2">
      <?= csrf_campo() ?>
      <input type="hidden" name="acao" value="excluir_cliente">
      <input type="hidden" name="id" value="<?= $id ?>">
      <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash3" aria-hidden="true"></i> Excluir cliente</button>
    </form>
  </section>

</div>

<section class="card card-body mb-3" id="carteirinha">
  <div class="card-titulo">
    <div>
      <h2>Carteirinha</h2>
      <p class="card-sub">Vacinas, vermífugos e antipulgas dos pets</p>
    </div>
    <?php if ($cliente['pets']): ?>
      <button class="btn btn-light btn-sm" type="button" data-bs-toggle="offcanvas" data-bs-target="#gaveta-dose" aria-controls="gaveta-dose"><i class="bi bi-plus-lg" aria-hidden="true"></i> Registrar dose</button>
    <?php endif; ?>
  </div>

  <?php if (!$doses): ?>
    <p class="vazio"><?= $cliente['pets'] ? 'Nada registrado. Anote as vacinas e vermífugos para lembrar o dono da próxima dose.' : 'Cadastre um pet para usar a carteirinha.' ?></p>
  <?php else: ?>
    <div class="table-responsive"><table class="table align-middle">
      <thead><tr><th>Pet</th><th>Dose</th><th>Aplicada</th><th>Próxima</th><th><span class="visually-hidden">Ações</span></th></tr></thead>
      <tbody>
        <?php foreach ($doses as $d): ?>
          <?php $dias = $d['proxima_em'] ? (int) (new DateTime($hoje))->diff(new DateTime($d['proxima_em']))->format('%r%a') : null; ?>
          <tr<?= !$d['atual'] ? ' class="apagado"' : '' ?>>
            <td><?= e($d['pet']) ?></td>
            <td>
              <i class="bi bi-<?= Cliente::ICONES_DOSE[$d['tipo']] ?> icone-suave" aria-hidden="true"></i> <?= e(Cliente::TIPOS_DOSE[$d['tipo']]) ?>: <strong><?= e($d['nome']) ?></strong>
              <?php if ($d['observacao']): ?><br><small class="text-muted"><?= e($d['observacao']) ?></small><?php endif; ?>
            </td>
            <td class="text-nowrap"><?= date('d/m/Y', strtotime($d['aplicada_em'])) ?></td>
            <td class="text-nowrap">
              <?php if (!$d['proxima_em']): ?>
                <span class="vazio">—</span>
              <?php else: ?>
                <?= date('d/m/Y', strtotime($d['proxima_em'])) ?>
                <?php if (!$d['atual']): ?>
                  <span class="selo selo-neutro">já renovada</span>
                <?php elseif ($dias < 0): ?>
                  <span class="selo selo-erro">vencida</span>
                <?php elseif ($dias <= $avisoDias): ?>
                  <span class="selo selo-alerta"><?= $dias === 0 ? 'vence hoje' : ($dias === 1 ? 'vence amanhã' : "em $dias dias") ?></span>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td class="acoes">
              <?php // mesma janela do quadro de doses: vencida há mais tempo não é mais lembrada ?>
              <?php if ($d['atual'] && $dias !== null && $dias <= $avisoDias && $dias >= -Cliente::DOSE_VENCIDA_AVISO_DIAS): ?>
                <a class="btn btn-light btn-sm btn-quadrado" href="<?= e(Cliente::whatsapp($cliente['telefone'], Cliente::mensagemDose($d + ['cliente' => $cliente['nome'], 'dias' => $dias]))) ?>" target="_blank" rel="noopener noreferrer" title="Lembrar pelo WhatsApp" aria-label="Lembrar o dono pelo WhatsApp (abre o WhatsApp)"><i class="bi bi-whatsapp" aria-hidden="true"></i></a>
              <?php endif; ?>
              <form method="post" data-confirmar="Excluir <?= e($d['nome']) ?> de <?= date('d/m/Y', strtotime($d['aplicada_em'])) ?> da carteirinha de <?= e($d['pet']) ?>?">
                <?= csrf_campo() ?>
                <input type="hidden" name="acao" value="excluir_dose">
                <input type="hidden" name="cliente_id" value="<?= $id ?>">
                <input type="hidden" name="id" value="<?= $d['id'] ?>">
                <button class="btn btn-outline-danger btn-sm btn-quadrado" title="Excluir" aria-label="Excluir este registro da carteirinha"><i class="bi bi-trash3" aria-hidden="true"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</section>

<?php $agendamentos = Agenda::doCliente($id, 10); ?>
<?php if ($agendamentos): ?>
<section class="card card-body mb-3">
  <h2>Banho e tosa</h2>
  <div class="table-responsive"><table class="table align-middle">
    <thead><tr><th>Quando</th><th>Pet</th><th>Serviço</th><th>Situação</th><th class="num">Valor</th></tr></thead>
    <tbody>
      <?php foreach ($agendamentos as $a): ?>
        <tr<?= in_array($a['status'], ['faltou', 'cancelado'], true) ? ' class="apagado"' : '' ?>>
          <td class="text-nowrap"><a href="index.php?page=agenda&amp;dia=<?= substr($a['inicio'], 0, 10) ?>"><?= date('d/m/Y H:i', strtotime($a['inicio'])) ?></a></td>
          <td><?= e($a['pet']) ?></td>
          <td><?= e($a['servico']) ?></td>
          <td><span class="selo <?= ['pago' => 'selo-ok', 'pronto' => 'selo-ok', 'faltou' => 'selo-erro', 'cancelado' => 'selo-erro', 'em_atendimento' => 'selo-alerta'][$a['status']] ?? 'selo-neutro' ?>"><?= e(Agenda::STATUS[$a['status']]) ?></span></td>
          <td class="num"><?= moeda($a['preco']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php endif; ?>

<section class="card card-body mb-3">
  <h2><?= $soMinhas ? 'Suas vendas para este cliente' : 'Compras' ?></h2>

  <?php if (!$compras): ?>
    <p class="vazio">Nenhuma <?= $soMinhas ? 'venda sua' : 'compra' ?> com este cliente. Use "Nova venda" para vender já com ele escolhido.</p>
  <?php else: ?>
    <div class="table-responsive"><table class="table table-hover align-middle">
      <thead>
        <tr>
          <th>Venda</th>
          <th>Data</th>
          <th>Itens</th>
          <th>Situação</th>
          <th class="num">Valor</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($compras as $v): ?>
          <tr>
            <td><a class="venda-num" href="index.php?page=historico&amp;ver=<?= $v['id'] ?>">#<?= $v['id'] ?></a></td>
            <td class="text-nowrap"><?= date('d/m/Y H:i', strtotime($v['criado_em'])) ?></td>
            <td><?= e($v['itens']) ?></td>
            <td><span class="selo <?= $seloSituacao($v) ?>"><?= e(Venda::situacao($v)) ?></span></td>
            <td class="num"><strong><?= moeda($v['liquido']) ?></strong></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if (count($compras) === 100): ?><p class="nota">Mostrando as 100 compras mais recentes.</p><?php endif; ?>
  <?php endif; ?>
</section>

<?php endif; ?>
