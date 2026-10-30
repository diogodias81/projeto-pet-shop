<?php

[$de, $ate] = Relatorio::datas($_GET);

$acao = (string) ($_GET['acao'] ?? '');

$usuarioId = (int) ($_GET['usuario_id'] ?? 0);

$registros = Auditoria::listar($de, $ate, $acao, $usuarioId, isset($_GET['exportar']) ? 100000 : 500);

if (isset($_GET['exportar'])) {
  exportarCsv("auditoria_{$de}_a_{$ate}.csv", ['Data', 'Usuário', 'Operação', 'Detalhes', 'IP'], array_map(
    fn($a) => [dataBr($a['criado_em']), $a['usuario_nome'] ?? '—', Auditoria::ACOES[$a['acao']] ?? $a['acao'], $a['detalhes'], $a['ip']],
    $registros
  ));
}

?>

<section class="painel">

  <h2>Registro de operações</h2>

  <form method="get" class="linha">

    <input type="hidden" name="page" value="auditoria">

    <label>
      De
      <input type="date" name="de" value="<?= e($de) ?>">
    </label>

    <label>
      Até
      <input type="date" name="ate" value="<?= e($ate) ?>">
    </label>

    <label>
      Operação

      <select name="acao">
        <option value="">Todas</option>
        <?php foreach (Auditoria::ACOES as $chave => $rotulo): ?>
          <option value="<?= $chave ?>" <?= $acao === $chave ? 'selected' : '' ?>><?= e($rotulo) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <label>
      Usuário

      <select name="usuario_id">
        <option value="">Todos</option>
        <?php foreach (Usuario::listar() as $u): ?>
          <option value="<?= $u['id'] ?>" <?= $usuarioId === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['nome']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <button class="btn btn-claro">Filtrar</button>

    <a class="btn btn-claro" href="index.php?<?= e(http_build_query(['page' => 'auditoria', 'de' => $de, 'ate' => $ate, 'acao' => $acao, 'usuario_id' => $usuarioId ?: '', 'exportar' => 1])) ?>">Exportar planilha</a>

  </form>

  <?php if (!$registros): ?>

    <p class="vazio">Nenhuma operação registrada com esses filtros.</p>

  <?php else: ?>

    <table>

      <thead>
        <tr>
          <th>Data</th>
          <th>Usuário</th>
          <th>Operação</th>
          <th>Detalhes</th>
          <th>IP</th>
        </tr>
      </thead>

      <tbody>

        <?php foreach ($registros as $a): ?>

          <tr>
            <td><?= dataBr($a['criado_em']) ?></td>
            <td><?= e($a['usuario_nome'] ?? '—') ?></td>
            <td><?= e(Auditoria::ACOES[$a['acao']] ?? $a['acao']) ?></td>
            <td><?= e($a['detalhes']) ?></td>
            <td><small class="codigo"><?= e($a['ip']) ?></small></td>
          </tr>

        <?php endforeach; ?>

      </tbody>

    </table>

    <?php if (count($registros) === 500): ?>
      <p class="nota">Mostrando as 500 operações mais recentes. Use os filtros para ver as anteriores.</p>
    <?php endif; ?>

  <?php endif; ?>

</section>
