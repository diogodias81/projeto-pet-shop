<?php

$descontoMax = temPerfil(['admin'])
  ? 1.0
  : (float) config('desconto_max_vendedor');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  try {

    $itens = json_decode(
      $_POST['itens'] ?? '[]',
      true
    ) ?: [];

    $res = Venda::registrar(
      usuario()['id'],
      $itens,
      $_POST,
      $descontoMax
    );

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
      array_flip(['itens', 'desconto_tipo', 'desconto', 'forma', 'recebido'])
    );
  }

  redirect('vendas');
}

$antigo = $_SESSION['form_venda'] ?? [];
unset($_SESSION['form_venda']);

$produtos = Produto::listar();

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

?>

<form
    method="post"
    id="form-venda"
    class="pdv"
    data-desconto-max="<?= $descontoMax ?>">

    <?= csrf_campo() ?>

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

    <div class="pesquisa">

      <label>
        <span class="visually-hidden">Produto</span>

        <i class="bi bi-upc-scan pesquisa-icone" aria-hidden="true"></i>

        <input
          type="search"
          id="v-busca"
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
