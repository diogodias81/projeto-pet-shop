<?php

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  try {

    if (($_POST['acao'] ?? '') === 'excluir') {

      Usuario::excluir(
        (int) $_POST['id']
      );

      flash('Usuário excluído.');
    } elseif (in_array($_POST['acao'] ?? '', ['desativar', 'reativar'], true)) {

      $ativar = $_POST['acao'] === 'reativar';

      Usuario::definirAtivo(
        (int) $_POST['id'],
        $ativar
      );

      flash($ativar ? 'Usuário reativado: já pode entrar no sistema.' : 'Usuário desativado: não consegue mais entrar.');
    } elseif (($_POST['acao'] ?? '') === 'redefinir') {

      Usuario::redefinirSenha(
        (int) $_POST['id'],
        $_POST['senha'] ?? ''
      );

      flash('Senha redefinida. O usuário deverá trocá-la no próximo acesso.');
    } else {

      Usuario::criar($_POST);

      flash('Usuário criado.');
    }
  } catch (DomainException $ex) {

    flash(
      $ex->getMessage(),
      'erro'
    );

    // Erro no cadastro: a gaveta reabre com nome, e-mail e perfil (a senha nunca volta para a tela)
    if (!in_array($_POST['acao'] ?? '', ['excluir', 'desativar', 'reativar', 'redefinir'], true)) {
      $_SESSION['form_usuario'] = array_intersect_key($_POST, array_flip(['nome', 'email', 'perfil']));
    }
  }

  redirect('usuarios');
}

$antigo = $_SESSION['form_usuario'] ?? [];
unset($_SESSION['form_usuario']);

$usuarios = Usuario::listar();
$ativos = count(array_filter($usuarios, fn($u) => $u['ativo']));

// O que cada perfil faz (ajuda a escolher sem criar admin por engano)
$perfis = [
  'admin' => ['shield-lock', 'Tudo, inclusive usuários, cancelamentos e auditoria'],
  'vendedor' => ['cart3', 'Vende, consulta produtos, estoque e as próprias vendas'],
  'estoque' => ['boxes', 'Cadastra produtos e categorias, dá entrada e baixa'],
  'financeiro' => ['graph-up', 'Dashboard, despesas, relatórios e histórico'],
];

$subtituloTopo = $ativos . ($ativos === 1 ? ' usuário ativo' : ' usuários ativos') . (count($usuarios) > $ativos ? ' · ' . (count($usuarios) - $ativos) . (count($usuarios) - $ativos === 1 ? ' desativado' : ' desativados') : '');
$acoesTopo = '<button class="btn btn-primary" type="button" data-bs-toggle="offcanvas" data-bs-target="#gaveta-usuario" aria-controls="gaveta-usuario"><i class="bi bi-person-plus" aria-hidden="true"></i> Novo usuário</button>';

?>

<div
  class="offcanvas offcanvas-end gaveta"
  tabindex="-1"
  id="gaveta-usuario"
  aria-labelledby="gaveta-usuario-titulo"
  <?= $antigo ? 'data-abrir' : '' ?>>

  <div class="offcanvas-header">
    <div>
      <h2 class="offcanvas-title" id="gaveta-usuario-titulo">Novo usuário</h2>
      <p class="card-sub">Ele vai trocar a senha no primeiro acesso.</p>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Fechar"></button>
  </div>

  <form method="post" class="offcanvas-body gaveta-form">

    <?= csrf_campo() ?>

    <fieldset>
      <legend>Dados de acesso</legend>

      <label>
        Nome

        <input
          name="nome"
          value="<?= e($antigo['nome'] ?? '') ?>"
          required>
      </label>

      <label>
        E-mail

        <input
          type="email"
          name="email"
          value="<?= e($antigo['email'] ?? '') ?>"
          required>
      </label>

      <label>
        Senha provisória
        <small>Mínimo de 6 caracteres<?= $antigo ? ' · digite de novo' : '' ?></small>

        <input
          type="password"
          name="senha"
          minlength="6"
          maxlength="1024"
          autocomplete="new-password"
          data-revelar
          required>
      </label>
    </fieldset>

    <fieldset>
      <legend>Perfil</legend>

      <!-- Sem padrão: esquecer o campo não pode criar outro administrador -->
      <div class="opcoes-perfil">
        <?php foreach (Usuario::PERFIS as $k => $v): ?>
          <label class="opcao-perfil">
            <input type="radio" name="perfil" value="<?= $k ?>" required<?= ($antigo['perfil'] ?? '') === $k ? ' checked' : '' ?>>
            <span class="icone-redondo perfil-<?= $k ?>" aria-hidden="true"><i class="bi bi-<?= $perfis[$k][0] ?? 'person' ?>"></i></span>
            <span class="tile-texto">
              <strong><?= e($v) ?></strong>
              <small><?= e($perfis[$k][1] ?? '') ?></small>
            </span>
          </label>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <div class="gaveta-rodape">
      <button type="button" class="btn btn-light" data-bs-dismiss="offcanvas">Cancelar</button>
      <button class="btn btn-primary">Criar usuário</button>
    </div>

  </form>

</div>


<section class="card card-body mb-3">

  <div class="table-responsive"><table class="table table-hover align-middle">

    <thead>

      <tr>
        <th>Usuário</th>
        <th>Perfil</th>
        <th>Situação</th>
        <th><span class="visually-hidden">Ações</span></th>
      </tr>

    </thead>

    <tbody>

      <?php foreach ($usuarios as $u): ?>

        <?php $souEu = (int) $u['id'] === (int) usuario()['id']; ?>

        <tr class="<?= $u['ativo'] ? '' : 'linha-inativa' ?>">

          <td>
            <span class="pessoa">
              <span class="avatar" aria-hidden="true"><?= e(iniciais($u['nome'])) ?></span>
              <span class="tile-texto">
                <strong><?= e($u['nome']) ?><?= $souEu ? ' <span class="selo selo-neutro">você</span>' : '' ?></strong>
                <small><?= e($u['email']) ?></small>
              </span>
            </span>
          </td>

          <td>
            <span class="selo selo-perfil perfil-<?= e($u['perfil']) ?>"><i class="bi bi-<?= $perfis[$u['perfil']][0] ?? 'person' ?>" aria-hidden="true"></i> <?= e(Usuario::PERFIS[$u['perfil']]) ?></span>
          </td>

          <td>
            <span class="selo <?= $u['ativo'] ? 'selo-ok' : 'selo-neutro' ?>"><?= $u['ativo'] ? 'Ativo' : 'Desativado' ?></span>
          </td>

          <td class="acoes">

            <details class="menu-acoes">

              <summary class="btn btn-light btn-sm btn-quadrado" title="Ações" aria-label="Ações para <?= e($u['nome']) ?>">
                <i class="bi bi-three-dots" aria-hidden="true"></i>
              </summary>

              <div class="menu-acoes-painel">

                <?php if ($souEu): ?>

                  <!-- A própria senha só em "Minha senha", que pede a atual -->
                  <a class="menu-acoes-item" href="index.php?page=minha_senha">
                    <i class="bi bi-key" aria-hidden="true"></i> Trocar minha senha
                  </a>

                <?php else: ?>

                <form
                  method="post"
                  class="menu-acoes-senha"
                  data-confirmar="Redefinir a senha de <?= e($u['nome']) ?>?">

                  <?= csrf_campo() ?>

                  <input
                    type="hidden"
                    name="acao"
                    value="redefinir">

                  <input
                    type="hidden"
                    name="id"
                    value="<?= $u['id'] ?>">

                  <label>
                    Nova senha provisória

                    <input
                      type="password"
                      name="senha"
                      placeholder="Mínimo 6 caracteres"
                      aria-label="Senha provisória para <?= e($u['nome']) ?>"
                      minlength="6"
                      maxlength="1024"
                      autocomplete="new-password"
                      data-revelar
                      required>
                  </label>

                  <button class="btn btn-light btn-sm">
                    <i class="bi bi-key" aria-hidden="true"></i> Redefinir senha
                  </button>

                </form>

                  <form
                    method="post"
                    data-confirmar="<?= $u['ativo'] ? 'Desativar' : 'Reativar' ?> o usuário <?= e($u['nome']) ?>?">

                    <?= csrf_campo() ?>

                    <input
                      type="hidden"
                      name="acao"
                      value="<?= $u['ativo'] ? 'desativar' : 'reativar' ?>">

                    <input
                      type="hidden"
                      name="id"
                      value="<?= $u['id'] ?>">

                    <button class="menu-acoes-item">
                      <i class="bi bi-<?= $u['ativo'] ? 'person-slash' : 'person-check' ?>" aria-hidden="true"></i>
                      <?= $u['ativo'] ? 'Desativar acesso' : 'Reativar acesso' ?>
                    </button>

                  </form>

                  <form
                    method="post"
                    data-confirmar="Excluir o usuário <?= e($u['nome']) ?>?">

                    <?= csrf_campo() ?>

                    <input
                      type="hidden"
                      name="acao"
                      value="excluir">

                    <input
                      type="hidden"
                      name="id"
                      value="<?= $u['id'] ?>">

                    <button class="menu-acoes-item perigo">
                      <i class="bi bi-trash3" aria-hidden="true"></i> Excluir usuário
                    </button>

                  </form>

                <?php endif; ?>

              </div>

            </details>

          </td>

        </tr>

      <?php endforeach; ?>

    </tbody>

  </table></div>

</section>
