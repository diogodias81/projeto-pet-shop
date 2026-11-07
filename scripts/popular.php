<?php
// APAGA os dados do banco (menos as 4 contas principais) e cria dados novos de teste, passando pelas regras do sistema.
// Antes guarda uma cópia em storage/antes-popular-AAAAMMDD-HHMMSS.sqlite (para voltar: pare o container e troque o petshop.sqlite por ela).
//   docker compose exec -T php php scripts/popular.php --apagar-tudo
if (PHP_SAPI !== 'cli') exit("Só pela linha de comando.\n");
if (($argv[1] ?? '') !== '--apagar-tudo') exit("Isto apaga vendas, produtos, estoque, despesas e auditoria. Para confirmar: php scripts/popular.php --apagar-tudo\n");

define('BASE_PATH', dirname(__DIR__));
date_default_timezone_set('America/Belem');
require BASE_PATH . '/app/helpers.php';
require BASE_PATH . '/app/Database.php';
spl_autoload_register(fn($c) => is_file($f = BASE_PATH . "/app/Models/$c.php") ? require $f : null);
$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
mt_srand(2026); // mesmos dados a cada execução

Database::migrar();
$pdo = Database::pdo();

$copia = BASE_PATH . '/storage/antes-popular-' . date('Ymd-His') . '.sqlite';
$pdo->exec('VACUUM INTO ' . $pdo->quote($copia));
echo "Cópia do banco anterior: storage/" . basename($copia) . "\n";

// 1. Apaga tudo, menos as contas 1 a 4 (admin, vendedor, estoque, financeiro)
$pdo->exec('PRAGMA foreign_keys = OFF');
$pdo->exec('BEGIN IMMEDIATE');
foreach (['venda_item_lotes', 'devolucoes', 'movimentacoes_estoque', 'entradas_estoque', 'venda_itens', 'vendas', 'lotes', 'produtos', 'categorias', 'despesas', 'auditoria', 'tentativas_login'] as $t) $pdo->exec("DELETE FROM $t");
$pdo->exec('DELETE FROM usuarios WHERE id > 4');
$pdo->exec("DELETE FROM sqlite_sequence WHERE name <> 'usuarios'");
$pdo->exec("UPDATE sqlite_sequence SET seq = 4 WHERE name = 'usuarios'");
$pdo->exec('COMMIT');
$pdo->exec('PRAGMA foreign_keys = ON');
foreach (glob(BASE_PATH . '/public/uploads/produtos/*') as $f) if (is_file($f)) unlink($f);

$users = $pdo->query('SELECT * FROM usuarios ORDER BY id')->fetchAll();
$porPerfil = array_column($users, null, 'perfil');
foreach (['admin', 'vendedor', 'estoque', 'financeiro'] as $p) if (!isset($porPerfil[$p])) exit("Falta a conta de perfil $p: parei.\n");
$como = function (string $perfil) use ($porPerfil) { $_SESSION['user'] = $porPerfil[$perfil]; return $porPerfil[$perfil]; };
// Data/hora passada para o que acabou de ser gravado (as regras gravam "agora")
$ultimaAuditoria = fn(string $quando) => $pdo->prepare('UPDATE auditoria SET criado_em = ? WHERE id = (SELECT MAX(id) FROM auditoria)')->execute([$quando]);

// 2. Categorias e produtos: [nome, categoria, código de barras, venda, custo, mínimo, estoque inicial, validade em dias (null = sem), peso nas vendas]
$como('admin');
$cats = ['Rações', 'Petiscos', 'Higiene', 'Medicamentos', 'Brinquedos', 'Acessórios', 'Aquarismo'];
foreach ($cats as $c) Categoria::criar($c);
$catId = array_column($pdo->query('SELECT id, nome FROM categorias')->fetchAll(), 'id', 'nome');
$produtos = [
    ['Ração Golden Adulto Frango 15kg', 'Rações', '7896000000011', 189.90, 132.00, 4, 40, 300, 6],
    ['Ração Premier Filhotes 2,5kg', 'Rações', '7896000000028', 74.90, 49.50, 5, 50, 240, 5],
    ['Ração Whiskas Gatos Carne 1kg', 'Rações', '7896000000035', 29.90, 19.80, 8, 90, 200, 8],
    ['Ração Pedigree Adulto 10kg', 'Rações', '7896000000042', 129.90, 92.00, 4, 40, 280, 5],
    ['Sachê Whiskas Peixe 85g', 'Rações', '7896000000059', 3.49, 2.10, 30, 400, 150, 10],
    ['Bifinho Keldog Carne 65g', 'Petiscos', '7896000000066', 8.90, 4.90, 15, 200, 180, 9],
    ['Osso Nó Natural 15cm', 'Petiscos', '7896000000073', 12.90, 6.50, 10, 120, 365, 5],
    ['Petisco Dreamies Gatos 40g', 'Petiscos', '7896000000080', 9.90, 5.80, 12, 160, 160, 6],
    ['Shampoo Neutro Pet 500ml', 'Higiene', '7896000000097', 24.90, 12.40, 6, 70, 720, 4],
    ['Tapete Higiênico 30 un', 'Higiene', '7896000000103', 59.90, 38.00, 6, 70, null, 5],
    ['Areia Sanitária Pipicat 4kg', 'Higiene', '7896000000110', 22.90, 14.20, 10, 130, null, 7],
    ['Antipulgas NexGard 10-25kg', 'Medicamentos', '7896000000127', 89.90, 58.00, 5, 50, 540, 3],
    ['Vermífugo Drontal Plus 4 comp.', 'Medicamentos', '7896000000134', 46.90, 28.50, 5, 45, 600, 3],
    ['Bolinha de Borracha Maciça', 'Brinquedos', '7896000000141', 14.90, 5.20, 8, 90, null, 4],
    ['Corda Mordedor Colorida', 'Brinquedos', '7896000000158', 19.90, 7.90, 6, 70, null, 3],
    ['Arranhador Gato Torre 50cm', 'Brinquedos', '7896000000165', 149.90, 85.00, 2, 15, null, 1],
    ['Coleira Nylon Ajustável M', 'Acessórios', '7896000000172', 29.90, 11.00, 5, 50, null, 3],
    ['Comedouro Inox 500ml', 'Acessórios', '7896000000189', 27.90, 12.50, 5, 50, null, 3],
    ['Caminha Pet Redonda M', 'Acessórios', '7896000000196', 119.90, 64.00, 2, 14, null, 1],
    ['Ração Peixes Tetra 20g', 'Aquarismo', '7896000000202', 15.90, 8.10, 6, 60, 400, 2],
    // sem código de barras e com margem baixa, para conferir a tela de rentabilidade
    ['Ração a Granel Cães (kg)', 'Rações', null, 12.90, 11.20, 20, 260, 90, 7],
];
$inicio = date('Y-m-d', strtotime('-92 days')) . ' 08:10:00';
$ids = [];
foreach ($produtos as [$nome, $cat, $codigo, $preco, $custo, $min, $qtd, $dias, $peso]) {
    $qtd = max($qtd, 16 * $peso + 3 * $min); // o bastante para 90 dias de vendas
    Produto::salvar(['nome' => $nome, 'categoria_id' => $catId[$cat], 'codigo_barras' => $codigo, 'preco' => number_format($preco, 2, ',', ''), 'preco_custo' => number_format($custo, 2, ',', ''), 'estoque_minimo' => $min]);
    $id = (int) $pdo->query('SELECT MAX(id) FROM produtos')->fetchColumn();
    $ids[] = $id;
    $como('estoque');
    Estoque::entrada($id, $qtd, 'Distribuidora Norte Pet', 'NP' . date('ymd', strtotime($inicio)), $dias ? date('Y-m-d', strtotime("+$dias days")) : '');
    $como('admin');
}
// Tudo o que foi cadastrado até aqui fica no primeiro dia
foreach (['auditoria', 'lotes', 'entradas_estoque', 'movimentacoes_estoque'] as $t) $pdo->prepare("UPDATE $t SET criado_em = ?")->execute([$inicio]);

// 3. Vendas dos últimos 90 dias (domingo fechado; sábado mais movimentado; hoje só até a hora atual)
$pesos = [];
foreach ($produtos as $i => $p) $pesos = array_merge($pesos, array_fill(0, $p[8], $ids[$i]));
$formas = ['pix', 'pix', 'pix', 'pix', 'credito', 'credito', 'credito', 'debito', 'debito', 'dinheiro', 'dinheiro'];
$vendas = 0; $vendasIds = [];
for ($d = 90; $d >= 0; $d--) {
    $dia = date('Y-m-d', strtotime("-$d days"));
    $sem = (int) date('w', strtotime($dia));
    if ($sem === 0) continue;
    $n = $sem === 6 ? mt_rand(6, 10) : mt_rand(2, 7);
    $horas = [];
    for ($k = 0; $k < $n; $k++) $horas[] = sprintf('%s %02d:%02d:%02d', $dia, mt_rand(8, 18), mt_rand(0, 59), mt_rand(0, 59));
    sort($horas);
    foreach ($horas as $quando) {
        if ($quando > date('Y-m-d H:i:s')) break;
        $itens = [];
        $escolhidos = [];
        for ($k = mt_rand(1, 4); count($escolhidos) < $k;) $escolhidos[$pesos[mt_rand(0, count($pesos) - 1)]] = true;
        foreach (array_keys($escolhidos) as $pid) {
            $q = mt_rand(1, 10) > 8 ? mt_rand(2, 4) : 1;
            if (Produto::buscar($pid)['vendavel'] - 2 >= $q) $itens[] = ['produto_id' => $pid, 'quantidade' => $q];
        }
        if (!$itens) continue;
        $u = $como(mt_rand(1, 5) === 1 ? 'admin' : 'vendedor');
        $forma = $formas[array_rand($formas)];
        $pag = ['forma' => $forma];
        if (mt_rand(1, 10) === 1) { $pag['desconto_tipo'] = 'percentual'; $pag['desconto'] = (string) [5, 10][mt_rand(0, 1)]; }
        if ($forma === 'dinheiro') $pag['recebido'] = ''; // ajustado abaixo pelo total
        try {
            if ($forma === 'dinheiro') {
                // recebido "redondo" acima do total, para o troco aparecer
                $sub = 0; foreach ($itens as $it) $sub += Produto::buscar($it['produto_id'])['preco'] * $it['quantidade'];
                $pag['recebido'] = number_format(ceil($sub / 10) * 10 + [0, 0, 10, 50][mt_rand(0, 3)], 2, ',', '');
            }
            $v = Venda::registrar((int) $u['id'], $itens, $pag, $u['perfil'] === 'admin' ? 1.0 : (float) config('desconto_max_vendedor'));
        } catch (DomainException $e) { continue; }
        $pdo->prepare('UPDATE vendas SET criado_em = ? WHERE id = ?')->execute([$quando, $v['id']]);
        $pdo->prepare('UPDATE movimentacoes_estoque SET criado_em = ? WHERE venda_id = ?')->execute([$quando, $v['id']]);
        $ultimaAuditoria($quando);
        $vendas++; $vendasIds[$v['id']] = $quando;
    }
    // Reposição no meio do período (entrada com fornecedor e lote novos)
    if ($d === 45) {
        $como('estoque');
        foreach ([0 => 20, 2 => 40, 4 => 200, 5 => 80, 10 => 50, 20 => 120] as $i => $qtd) {
            Estoque::entrada($ids[$i], $qtd, 'Atacadão Pet Belém', 'AP' . date('ymd', strtotime($dia)), $produtos[$i][7] ? date('Y-m-d', strtotime('+' . ($produtos[$i][7] + 60) . ' days')) : '');
            $pdo->prepare("UPDATE lotes SET criado_em = ? WHERE id = (SELECT MAX(id) FROM lotes)")->execute(["$dia 07:50:00"]);
            $pdo->prepare("UPDATE entradas_estoque SET criado_em = ? WHERE id = (SELECT MAX(id) FROM entradas_estoque)")->execute(["$dia 07:50:00"]);
            $pdo->prepare("UPDATE movimentacoes_estoque SET criado_em = ? WHERE id = (SELECT MAX(id) FROM movimentacoes_estoque)")->execute(["$dia 07:50:00"]);
            $ultimaAuditoria("$dia 07:50:00");
        }
    }
}

// 4. Cancelamentos e devoluções (duas horas depois da venda)
$admin = $como('admin');
$antigas = array_slice(array_keys($vendasIds), 5, -5);
$depois = function (int $vid, array $tipos) use ($pdo, $vendasIds, $ultimaAuditoria) {
    $q = date('Y-m-d H:i:s', strtotime($vendasIds[$vid]) + 7200);
    $pdo->prepare("UPDATE movimentacoes_estoque SET criado_em = ? WHERE venda_id = ? AND tipo IN ('" . implode("','", $tipos) . "')")->execute([$q, $vid]);
    $pdo->prepare('UPDATE devolucoes SET criado_em = ? WHERE venda_id = ?')->execute([$q, $vid]);
    $pdo->prepare('UPDATE vendas SET cancelado_em = ? WHERE id = ? AND cancelado_em IS NOT NULL')->execute([$q, $vid]);
    $ultimaAuditoria($q);
};
foreach ([$antigas[10], $antigas[120], $antigas[200]] as $vid) {
    Venda::cancelar($vid, $admin, 'Cliente desistiu da compra no caixa');
    $depois($vid, ['cancelamento']);
}
foreach ([$antigas[40], $antigas[150]] as $vid) {
    $item = Venda::buscar($vid)['itens'][0];
    Venda::devolver($vid, $admin, [$item['id'] => 1], 'Embalagem danificada');
    $depois($vid, ['devolucao']);
}
$vid = $antigas[90]; // devolução da venda inteira
$v = Venda::buscar($vid);
Venda::devolver($vid, $admin, array_column(array_map(fn($i) => ['id' => $i['id'], 'q' => $i['quantidade']], $v['itens']), 'q', 'id'), 'Produto trocado por outro tamanho');
$depois($vid, ['devolucao']);

// 5. Estoque para os alertas: lotes vencendo em 5, 12 e 25 dias, um já vencido, um produto zerado e dois abaixo do mínimo
$como('estoque');
Estoque::entrada($ids[7], 12, 'Distribuidora Norte Pet', 'DRM-ALERTA7', date('Y-m-d', strtotime('+5 days')));
Estoque::entrada($ids[5], 18, 'Distribuidora Norte Pet', 'KEL-ALERTA15', date('Y-m-d', strtotime('+12 days')));
Estoque::entrada($ids[1], 6, 'Distribuidora Norte Pet', 'PRE-ALERTA30', date('Y-m-d', strtotime('+25 days')));
Estoque::entrada($ids[12], 4, 'Distribuidora Norte Pet', 'DRO-VENCIDO', date('Y-m-d', strtotime('+1 day')));
$pdo->prepare("UPDATE lotes SET validade = ? WHERE codigo = 'DRO-VENCIDO'")->execute([date('Y-m-d', strtotime('-3 days'))]);
$lotesDe = fn(int $pid) => $pdo->query("SELECT id, quantidade FROM lotes WHERE produto_id = $pid AND quantidade > 0")->fetchAll();
foreach ($lotesDe($ids[15]) as $l) Estoque::baixa((int) $l['id'], (int) $l['quantidade'], 'inventario', 'Contagem do mês'); // arranhador zerado
foreach ([[$ids[3], 2], [$ids[11], 3]] as [$pid, $fica]) { // abaixo do mínimo
    $total = array_sum(array_column($lotesDe($pid), 'quantidade'));
    foreach ($lotesDe($pid) as $l) { if ($total <= $fica) break; $q = min((int) $l['quantidade'], $total - $fica); Estoque::baixa((int) $l['id'], $q, 'avaria', 'Saco rasgado no depósito'); $total -= $q; }
}
$l = $lotesDe($ids[8])[0];
Estoque::baixa((int) $l['id'], 2, 'perda', 'Não encontrado na prateleira');

// 6. Despesas dos três meses anteriores e do mês atual (até hoje)
$como('financeiro');
$fin = (int) $porPerfil['financeiro']['id'];
for ($m = 3; $m >= 0; $m--) {
    $mes = date('Y-m', strtotime("first day of -$m month"));
    $lista = [
        // valores perto do lucro bruto do mês: uns meses fecham no positivo, outros no negativo
        ['Aluguel da loja', 'aluguel', 1500, 5], ['Salário vendedor', 'salarios', 1200, 5], ['Salário estoquista', 'salarios', 1000, 5],
        ['Conta de energia', 'energia', mt_rand(420, 610), 12], ['Conta de água', 'agua', mt_rand(95, 140), 12],
        ['Simples Nacional', 'impostos', mt_rand(650, 980), 20], ['Compra Distribuidora Norte Pet', 'fornecedores', mt_rand(200, 700), 8],
        ['Internet e telefone', 'outras', 149.90, 15],
    ];
    if ($m === 2) $lista[] = ['Conserto do ar-condicionado', 'manutencao', 380, 22];
    if ($m === 1) $lista[] = ['Compra Atacadão Pet Belém', 'fornecedores', 1250.40, 18];
    foreach ($lista as [$desc, $cat, $valor, $diaMes]) {
        $data = "$mes-" . sprintf('%02d', $diaMes);
        if ($data > date('Y-m-d')) continue;
        Despesa::criar(['descricao' => $desc, 'categoria' => $cat, 'valor' => number_format($valor, 2, ',', ''), 'data' => $data], $fin);
        $ultimaAuditoria("$data 09:00:00");
    }
}

// Conferência: estoque de cada produto = soma dos seus lotes
$erradas = $pdo->query('SELECT p.nome FROM produtos p WHERE p.estoque <> (SELECT COALESCE(SUM(quantidade),0) FROM lotes WHERE produto_id = p.id)')->fetchAll(PDO::FETCH_COLUMN);
if ($erradas) { echo 'ERRO: estoque diferente dos lotes em ' . implode(', ', $erradas) . "\n"; exit(1); }
$conta = fn(string $sql) => $pdo->query($sql)->fetchColumn();
printf("Pronto: %d produtos, %d lotes, %d vendas (%d canceladas, %d com devolução), %d despesas, %d registros de auditoria.\n",
    $conta('SELECT COUNT(*) FROM produtos'), $conta('SELECT COUNT(*) FROM lotes'), $conta('SELECT COUNT(*) FROM vendas'),
    $conta("SELECT COUNT(*) FROM vendas WHERE status='cancelada'"), $conta('SELECT COUNT(*) FROM vendas WHERE valor_devolvido > 0'),
    $conta('SELECT COUNT(*) FROM despesas'), $conta('SELECT COUNT(*) FROM auditoria'));
