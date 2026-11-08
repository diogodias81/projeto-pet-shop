<?php

$descontoMax = temPerfil(['admin'])
  ? 1.0
  : (float) config('desconto_max_vendedor');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // Mesmo caixa enviado de novo (reenvio da página, rede lenta, clique duplo que passou): abre a venda já feita em vez de gravar outra e baixar o estoque em dobro
  $token = (string) ($_POST['venda_token'] ?? '');
  if (isset($_SESSION['vendas_feitas'][$token])) {
    redirect('comprovante', ['id' => $_SESSION['vendas_feitas'][$token]]);
  }

  try {

    $itensJson = is_string($_POST['itens'] ?? null) ? $_POST['itens'] : '[]';
    if (strlen($itensJson) > Venda::MAX_ITENS_BYTES) throw new DomainException('Carrinho grande demais: no máximo ' . Venda::MAX_ITENS . ' linhas.');
    $itens = json_decode($itensJson, true);
    if (!is_array($itens)) $itens = [];

    $res = Venda::registrar(
      usuario()['id'],
      $itens,
      $_POST,
      $descontoMax
    );

    if ($token !== '') {
      $_SESSION['vendas_feitas'][$token] = $res['id'];
      $_SESSION['vendas_feitas'] = array_slice($_SESSION['vendas_feitas'], -20, null, true); // só as últimas: a sessão não cresce sem fim
    }

    flash(
      'Venda #' . $res['id'] .
        ' registrada: ' .
        moeda($res['total']) .
        '.'
    );

    foreach ($res['alertas'] as $a) {

      flash(
        $a,
        'alerta'
      );
    }

    redirect('comprovante', ['id' => $res['id']]);
  } catch (DomainException $ex) {

    flash(
      $ex->getMessage(),
      'erro'
    );

    // devolve o carrinho: o cliente está no caixa, não dá para passar tudo de novo
    $_SESSION['form_venda'] = array_intersect_key(
      $_POST,
      array_flip(['itens', 'desconto_tipo', 'desconto', 'forma', 'recebido', 'cliente_id'])
    );
    // carrinho grande demais não volta para a tela (a página ficaria do tamanho do que foi enviado)
    if (!is_string($_SESSION['form_venda']['itens'] ?? '') || strlen($_SESSION['form_venda']['itens'] ?? '') > Venda::MAX_ITENS_BYTES) unset($_SESSION['form_venda']['itens']);
    // só os primeiros serviços: a lista volta para a tela e cada id vira uma consulta
    $_SESSION['form_venda']['agendamentos'] = implode(',', array_slice(Venda::idsAgendamentos($_POST['agendamentos'] ?? ''), 0, Venda::MAX_SERVICOS));
  }

  redirect('vendas');
}

$antigo = $_SESSION['form_venda'] ?? [];
unset($_SESSION['form_venda']);

$produtos = Produto::listar();

// Serviços da agenda a cobrar: ?agendamento=ID (vem da Agenda) ou de volta depois de um erro
$servicos = [];
foreach (array_slice(Venda::idsAgendamentos($antigo['agendamentos'] ?? ($_GET['agendamento'] ?? '')), 0, Venda::MAX_SERVICOS) as $aid) {
  $a = Agenda::buscar($aid);
  if (!$a) continue;
  $qual = "{$a['servico']} de {$a['pet']}";
  if (!$a['dono_ativo']) flash("$qual não entrou na venda: o pet ou o cliente foi excluído.", 'alerta');
  elseif ($a['venda_id']) flash("$qual já foi pago na venda #{$a['venda_id']}: não entrou na venda.", 'alerta');
  elseif (!Agenda::aCobrar($a)) flash("$qual não entrou na venda (está " . mb_strtolower(Agenda::STATUS[$a['status']]) . ').', 'alerta');
  // uma venda é de um cliente só: serviço de outro dono fica para outra venda
  elseif ($servicos && (int) $a['cliente_id'] !== (int) $servicos[0]['cliente_id']) flash("$qual é de {$a['cliente']}, não de {$servicos[0]['cliente']}: cobre em outra venda.", 'alerta');
  else $servicos[] = $a;
}
$servicosTotal = round(array_sum(array_column($servicos, 'preco')), 2);

// Cliente opcional: lista para escolher pelo nome/telefone; ?cliente=ID vem da ficha do cliente (ou o dono do pet do serviço)
$clientes = Cliente::listar();
if ($servicos && empty($antigo['cliente_id']) && empty($_GET['cliente'])) $_GET['cliente'] = $servicos[0]['cliente_id'];
$rotuloCliente = fn(array $c) => $c['nome'] . ' · ' . Cliente::formatarTelefone($c['telefone']);
$clienteAtual = (int) ($antigo['cliente_id'] ?? ($_GET['cliente'] ?? 0));
$clienteAtual = array_values(array_filter($clientes, fn($c) => (int) $c['id'] === $clienteAtual))[0] ?? null;

// Catálogo para a pesquisa rápida no navegador (só o que pode ser vendido)
$catalogo = array_values(array_map(
  fn($p) => [
    'id' => (int) $p['id'],
    'nome' => $p['nome'],
    'codigo' => $p['codigo_barras'],
    'preco' => (float) $p['preco'],
    'estoque' => (int) $p['vendavel'],
    'foto' => Produto::urlFoto($p['foto']),
  ],
  array_filter(
    $produtos,
    fn($p) => $p['vendavel'] > 0
  )
));

// Produtos sem estoque vendável: a busca (por código ou nome) avisa "sem estoque" em vez de "não encontrado"
$esgotados = array_values(array_map(
  fn($p) => ['nome' => $p['nome'], 'codigo' => $p['codigo_barras']],
  array_filter($produtos, fn($p) => $p['vendavel'] <= 0)
));

$caixaAberto = Caixa::aberto();

?>

<?php if (!$caixaAberto): ?>
  <section class="alert alert-warning caixa-fechado d-print-none" role="alert">
    <span><i class="bi bi-lock" aria-hidden="true"></i> <strong>O caixa está fechado.</strong> Abra com o troco da gaveta para vender.</span>
    <form autocomplete="off" method="post" action="index.php?page=caixa" class="caixa-fechado-form">
      <?= csrf_campo() ?>
      <input type="hidden" name="acao" value="abrir">
      <input type="hidden" name="voltar" value="vendas">
      <?php if ($servicos): // volta com os serviços da agenda depois de abrir o caixa ?><input type="hidden" name="agendamento" value="<?= e(implode(',', array_column($servicos, 'id'))) ?>"><?php endif; ?>
      <input name="troco" required inputmode="decimal" placeholder="Troco, ex.: 100,00" aria-label="Troco inicial (R$)">
      <button class="btn btn-primary btn-sm"><i class="bi bi-unlock" aria-hidden="true"></i> Abrir caixa</button>
    </form>
  </section>
<?php endif; ?>

<form
    method="post"
    autocomplete="off"
    id="form-venda"
    class="pdv"
    data-desconto-max="<?= $descontoMax ?>"
    data-servicos-total="<?= $servicosTotal ?>"
    data-servicos-qtd="<?= count($servicos) ?>"
    <?= $caixaAberto ? '' : 'data-caixa-fechado' ?>
    data-usuario="<?= (int) usuario()['id'] ?>">

    <?= csrf_campo() ?>

    <input type="hidden" name="venda_token" value="<?= bin2hex(random_bytes(16)) ?>">

    <input
      type="hidden"
      name="itens"
      id="venda-itens"
      value="<?= e($antigo['itens'] ?? '') ?>">

    <script type="application/json" id="v-catalogo"><?= json_encode($catalogo, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>

    <script type="application/json" id="v-esgotados"><?= json_encode($esgotados,JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>

  <section class="card card-body pdv-itens">

    <div class="card-titulo">
      <h2>Itens da venda</h2>
      <a class="btn btn-light btn-sm d-print-none" href="index.php?page=historico"><i class="bi bi-clock-history" aria-hidden="true"></i> Histórico</a>
    </div>

    <?php if ($servicos): ?>
      <input type="hidden" name="agendamentos" value="<?= e(implode(',', array_column($servicos, 'id'))) ?>">
      <ul class="pdv-servicos">
        <?php foreach ($servicos as $a): ?>
          <li>
            <span class="pet-emoji" aria-hidden="true">✂️</span>
            <span class="pet-texto">
              <strong><?= e($a['servico']) ?> — <?= e($a['pet']) ?></strong>
              <small><?= e($a['cliente']) ?> · agendado para <?= date('d/m H:i', strtotime($a['inicio'])) ?></small>
            </span>
            <strong class="text-nowrap"><?= moeda($a['preco']) ?></strong>
            <?php $outros = array_diff(array_column($servicos, 'id'), [$a['id']]); ?>
            <a class="btn btn-light btn-sm btn-quadrado" href="index.php?page=vendas<?= $outros ? '&amp;agendamento=' . e(implode(',', $outros)) : '' ?>" title="Tirar da venda" aria-label="Tirar <?= e($a['servico']) ?> de <?= e($a['pet']) ?> da venda"><i class="bi bi-x-lg" aria-hidden="true"></i></a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <div class="pesquisa">

      <label>
        <span class="visually-hidden">Produto</span>

        <i class="bi bi-upc-scan pesquisa-icone" aria-hidden="true"></i>

        <input
          type="search"
          id="v-busca"
          data-camera
          aria-label="Produto"
          placeholder="Leia o código de barras ou digite o nome"
          autocomplete="off"
          autofocus
          role="combobox"
          aria-expanded="false"
          aria-controls="v-sugestoes">
      </label>

      <p class="busca-aviso" id="v-busca-aviso" role="alert" hidden></p>

      <ul
        id="v-sugestoes"
        class="sugestoes"
        role="listbox"
        hidden></ul>

    </div>


    <div class="table-responsive"><table id="v-tabela" class="table align-middle">

      <thead>

        <tr>
          <th></th>
          <th>Produto</th>
          <th class="num">Qtd.</th>
          <th class="num">Unitário</th>
          <th class="num">Subtotal</th>
          <th></th>
        </tr>

      </thead>

      <tbody></tbody>

    </table></div>


    <div class="estado-vazio" id="v-vazio">
      <span class="estado-vazio-icone"><i class="bi bi-cart3" aria-hidden="true"></i></span>
      <strong>Carrinho vazio</strong>
      <p>Leia o código de barras ou busque pelo nome para adicionar.</p>
    </div>

  </section>

  <aside class="card card-body pdv-resumo" id="pdv-pagamento">

      <h2>Pagamento</h2>

      <div class="pdv-campos">

        <label>
          Cliente <small>(opcional)</small>

          <input
            id="v-cliente-busca"
            list="v-clientes-lista"
            type="search"
            maxlength="140"
            placeholder="Nome ou telefone"
            autocomplete="off"
            data-cliente-inicial="<?= $clienteAtual ? (int) $clienteAtual['id'] : '' ?>"
            value="<?= $clienteAtual ? e($rotuloCliente($clienteAtual)) : '' ?>">

          <input type="hidden" name="cliente_id" id="v-cliente" value="<?= $clienteAtual ? (int) $clienteAtual['id'] : '' ?>">

          <datalist id="v-clientes-lista">
            <?php foreach ($clientes as $c): ?>
              <option value="<?= e($rotuloCliente($c)) ?>" data-id="<?= (int) $c['id'] ?>"></option>
            <?php endforeach; ?>
          </datalist>

          <small><a href="index.php?page=clientes&amp;novo=1&amp;voltar=vendas">Cadastrar cliente novo</a></small>
        </label>

        <label>
          Desconto

          <span class="campo-composto">

            <select name="desconto_tipo" id="v-desc-tipo" aria-label="Tipo de desconto">
              <option value="valor">R$</option>
              <option value="percentual" <?= ($antigo['desconto_tipo'] ?? '') === 'percentual' ? 'selected' : '' ?>>%</option>
            </select>

            <input
              name="desconto"
              id="v-desconto"
              aria-label="Valor do desconto"
              inputmode="decimal"
              placeholder="0,00"
              value="<?= e($antigo['desconto'] ?? '') ?>">

          </span>

          <?php if ($descontoMax < 1): ?>
            <small>Máximo para o seu perfil: <?= round($descontoMax * 100) ?>%</small>
          <?php endif; ?>

        </label>

        <label>
          Pagamento

          <select name="forma" id="v-forma" required>

            <option value="">Escolha…</option>

            <?php foreach (Venda::FORMAS as $valor => $rotulo): ?>

              <option value="<?= $valor ?>" <?= ($antigo['forma'] ?? '') === $valor ? 'selected' : '' ?>>
                <?= e($rotulo) ?>
              </option>

            <?php endforeach; ?>

          </select>

        </label>

        <label id="v-recebido-campo" hidden>
          Valor recebido (R$)

          <input
            name="recebido"
            id="v-recebido"
            inputmode="decimal"
            placeholder="0,00"
            value="<?= e($antigo['recebido'] ?? '') ?>">
        </label>

        <p class="alert alert-danger" id="v-erro" role="alert" hidden></p>

      </div>


      <dl class="resumo-venda">

        <dt>Subtotal</dt>
        <dd id="v-subtotal">R$ 0,00</dd>

        <dt>Desconto</dt>
        <dd id="v-desc-valor">R$ 0,00</dd>

        <dt class="resumo-total">Total</dt>
        <dd class="resumo-total" id="v-total">R$ 0,00</dd>

        <dt id="v-troco-rotulo" hidden>Troco</dt>
        <dd id="v-troco" hidden>R$ 0,00</dd>

      </dl>

      <button
        class="btn btn-primary"
        id="v-finalizar"
        disabled
        aria-describedby="v-motivo">
        Finalizar venda
      </button>

      <p class="nota" id="v-motivo" aria-live="polite">Adicione um produto para finalizar.</p>

  </aside>

  <!-- Celular: total sempre à vista; leva ao pagamento (app.js mostra quando há itens) -->
  <a class="pdv-barra d-lg-none d-print-none" href="#pdv-pagamento" id="v-barra" hidden>
    <span class="pdv-barra-total">
      <small id="v-barra-itens">0 itens</small>
      <strong id="v-barra-total">R$ 0,00</strong>
    </span>
    <span class="btn btn-primary">Pagamento <i class="bi bi-arrow-down" aria-hidden="true"></i></span>
  </a>

</form>
