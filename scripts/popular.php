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
foreach (['caixa_movimentos', 'caixas', 'venda_servicos', 'agendamentos', 'servicos', 'venda_item_lotes', 'devolucoes', 'movimentacoes_estoque', 'entradas_estoque', 'venda_itens', 'vendas', 'lotes', 'produtos', 'categorias', 'pet_doses', 'pets', 'clientes', 'despesas', 'auditoria', 'tentativas_login'] as $t) $pdo->exec("DELETE FROM $t");
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
// Clientes e pets (nomes e telefones fictícios)
$clientesTeste = [
    ['Maria Souza', '91988871234', [['Thor', 'cao', 'Labrador', 'G', '-4 years', 'Puxa muito na guia']]],
    ['João Pereira', '91991234567', [['Mel', 'cao', 'Shih-tzu', 'P', '-2 years', ''], ['Bolinha', 'gato', 'SRD', 'P', '-6 years', '']]],
    ['Ana Lima', '91987654321', [['Luna', 'gato', 'Siamês', 'P', '-3 years', 'Alérgica a shampoo com perfume']]],
    ['Carlos Santos', '91984561237', [['Max', 'cao', 'Golden', 'G', '-1 year', '']]],
    ['Fernanda Costa', '91996325874', [['Nina', 'cao', 'Poodle', 'P', '-8 years', 'Idosa: cuidado ao secar'], ['Pipoca', 'cao', 'Yorkshire', 'P', '-5 months', '']]],
    ['Rafael Oliveira', '9132245566', [['Simba', 'gato', 'Persa', 'M', '-5 years', '']]],
    ['Juliana Alves', '91981112233', [['Bob', 'cao', 'Beagle', 'M', '-3 years', 'Morde ao cortar unha']]],
    ['Pedro Rocha', '91982223344', [['Fred', 'cao', 'Bulldog Francês', 'M', '-2 years', '']]],
    ['Camila Martins', '91983334455', [['Mia', 'gato', 'SRD', 'P', '-1 year', ''], ['Tom', 'gato', 'SRD', 'M', '-7 years', '']]],
    ['Lucas Ferreira', '91984445566', [['Zeus', 'cao', 'Pastor Alemão', 'G', '-6 years', '']]],
    ['Patrícia Gomes', '91985556677', [['Lili', 'cao', 'Maltês', 'P', '-4 years', '']]],
    ['Bruno Ribeiro', '91986667788', [['Nemo', 'outro', 'Peixe Betta', '', '', '']]],
    ['Aline Carvalho', '91987778899', []],
    ['Marcos Teixeira', '91988889900', [['Duke', 'cao', 'Rottweiler', 'G', '-5 years', 'Bravo com outros cães']]],
];
$como('vendedor');
$idsClientes = [];
foreach ($clientesTeste as $i => [$nome, $tel, $pets]) {
    $cid = Cliente::salvar(['nome' => $nome, 'telefone' => $tel, 'email' => $i % 3 === 0 ? strtolower(strtok(iconv('UTF-8', 'ASCII//TRANSLIT', $nome), ' ')) . '@exemplo.com' : '', 'observacao' => $i === 1 ? 'Prefere contato por WhatsApp' : '']);
    $idsClientes[] = $cid;
    foreach ($pets as [$pn, $esp, $raca, $porte, $idade, $obs]) {
        Cliente::salvarPet(['cliente_id' => $cid, 'nome' => $pn, 'especie' => $esp, 'raca' => $raca, 'porte' => $porte, 'nascimento' => $idade ? date('Y-m-d', strtotime($idade)) : '', 'observacao' => $obs]);
    }
}
$como('admin');

// Tudo o que foi cadastrado até aqui fica no primeiro dia
foreach (['auditoria', 'lotes', 'entradas_estoque', 'movimentacoes_estoque', 'clientes', 'pets'] as $t) $pdo->prepare("UPDATE $t SET criado_em = ?")->execute([$inicio]);

// Serviços de banho e tosa (os atendimentos pagos entram no dia a dia abaixo)
$como('admin');
foreach ([['Banho porte P', '45,00', 60], ['Banho porte M', '60,00', 60], ['Banho porte G', '80,00', 90], ['Tosa higiênica', '35,00', 30], ['Tosa completa', '90,00', 120], ['Hidratação de pelos', '30,00', 30]] as [$n, $pr, $du]) {
    Agenda::salvarServico(['nome' => $n, 'preco' => $pr, 'duracao_min' => $du]);
}
$pdo->prepare('UPDATE auditoria SET criado_em = ? WHERE acao = ?')->execute([$inicio, 'servico_criado']);
$servs = $pdo->query('SELECT * FROM servicos')->fetchAll();
$petsCao = $pdo->query("SELECT p.id, p.cliente_id, p.porte FROM pets p WHERE p.especie <> 'outro'")->fetchAll();
$servicoDo = function (array $pet) use ($servs) { // banho do porte do pet ou tosa/hidratação
    $porte = ['P' => 0, 'M' => 1, 'G' => 2][$pet['porte'] ?: 'P'] ?? 0;
    return $servs[mt_rand(1, 4) === 1 ? mt_rand(3, 5) : $porte];
};
$insere = $pdo->prepare('INSERT INTO agendamentos (cliente_id, pet_id, servico_id, inicio, fim, preco, status, usuario_id, criado_em) VALUES (?,?,?,?,?,?,?,?,?)');
$vend = $como('vendedor');

// 3. Vendas dos últimos 90 dias (domingo fechado; sábado mais movimentado; hoje só até a hora atual)
$pesos = [];
foreach ($produtos as $i => $p) $pesos = array_merge($pesos, array_fill(0, $p[8], $ids[$i]));
$formas = ['pix', 'pix', 'pix', 'pix', 'credito', 'credito', 'credito', 'debito', 'debito', 'dinheiro', 'dinheiro'];
$vendas = 0; $vendasIds = [];
for ($d = 90; $d >= 0; $d--) {
    $dia = date('Y-m-d', strtotime("-$d days"));
    $sem = (int) date('w', strtotime($dia));
    if ($sem === 0) continue;
    // abre o caixa do dia antes das vendas
    $como('vendedor');
    $caixaDia = Caixa::abrir($sem === 6 ? '150,00' : '100,00');
    $pdo->prepare('UPDATE caixas SET aberto_em = ? WHERE id = ?')->execute(["$dia 07:55:00", $caixaDia]);
    $ultimaAuditoria("$dia 07:55:00");
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
        if (mt_rand(1, 2) === 1) $pag['cliente_id'] = $idsClientes[min(mt_rand(0, count($idsClientes) - 1), mt_rand(0, count($idsClientes) - 1))]; // metade com cliente; os primeiros compram mais
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
    // banho e tosa dos últimos 30 dias: pagos no fim do atendimento (um em dez faltou)
    if ($d >= 1 && $d <= 30) {
        $hora = strtotime("$dia 08:00");
        for ($k = mt_rand(1, 4); $k > 0; $k--) {
            $pet = $petsCao[mt_rand(0, count($petsCao) - 1)];
            $sv = $servicoDo($pet);
            $ini = date('Y-m-d H:i', $hora); $fim = date('Y-m-d H:i', $hora + $sv['duracao_min'] * 60);
            $hora += ($sv['duracao_min'] + 30) * 60;
            if (substr($fim, 11) > '19:00') break;
            $faltou = mt_rand(1, 10) === 1;
            $insere->execute([$pet['cliente_id'], $pet['id'], $sv['id'], $ini, $fim, $sv['preco'], $faltou ? 'faltou' : 'pronto', $vend['id'], date('Y-m-d H:i:s', strtotime("$dia -3 days 10:00"))]);
            if ($faltou) continue;
            $aid = (int) $pdo->lastInsertId();
            $como('vendedor');
            $v = Venda::registrar((int) $vend['id'], [], ['forma' => ['pix', 'credito', 'debito', 'dinheiro'][mt_rand(0, 3)], 'agendamentos' => [$aid]]);
            $pdo->prepare('UPDATE vendas SET criado_em = ? WHERE id = ?')->execute(["$fim:00", $v['id']]);
            $ultimaAuditoria("$fim:00");
        }
    }
    // sábado: sangria do meio do dia para o banco
    $como('vendedor');
    if ($sem === 6 && Caixa::resumo(Caixa::aberto())['esperado'] >= 250) {
        Caixa::movimentar('sangria', '200,00', 'Depósito no banco');
        $pdo->prepare('UPDATE caixa_movimentos SET criado_em = ? WHERE id = (SELECT MAX(id) FROM caixa_movimentos)')->execute(["$dia 13:00:00"]);
        $ultimaAuditoria("$dia 13:00:00");
    }
    // fecha o caixa do dia (hoje fica aberto); de vez em quando a contagem não bate
    if ($d > 0) {
        $pdo->prepare('UPDATE caixas SET fechado_em = ? WHERE id = ?')->execute(["$dia 19:05:00", $caixaDia]);
        $esperado = Caixa::resumo(Caixa::buscar($caixaDia))['esperado'];
        $dif = mt_rand(1, 8) === 1 ? [-2.5, -10.0, 1.0, 0.5][mt_rand(0, 3)] : 0.0;
        $pdo->prepare('UPDATE caixas SET fechado_por = ?, contado = ?, esperado = ?, diferenca = ?, observacao = ? WHERE id = ?')
            ->execute([$vend['id'], $esperado + $dif, $esperado, $dif, $dif ? ($dif < 0 ? 'Troco dado a mais' : 'Cliente deixou o troco') : null, $caixaDia]);
        Auditoria::registrar('caixa_fechado', "Caixa #$caixaDia: contado " . moeda($esperado + $dif) . ', esperado ' . moeda($esperado));
        $ultimaAuditoria("$dia 19:05:00");
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
    $pdo->prepare('UPDATE vendas SET cancelado_em = ?, cancelado_caixa_id = NULL WHERE id = ? AND cancelado_em IS NOT NULL')->execute([$q, $vid]);
    $pdo->prepare('UPDATE devolucoes SET caixa_id = NULL WHERE venda_id = ?')->execute([$vid]); // estorno de dias passados: não sai da gaveta de hoje
    $ultimaAuditoria($q);
};
// estorno em dinheiro exige caixa aberto: um caixa provisório só para estes estornos de dias passados (some no fim)
$caixaProvisorio = Caixa::aberto() ? 0 : ($pdo->exec('INSERT INTO caixas (aberto_por, troco_inicial) VALUES (' . (int) $admin['id'] . ', 0)') ? (int) $pdo->lastInsertId() : 0);
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
if ($caixaProvisorio) $pdo->prepare('DELETE FROM caixas WHERE id = ?')->execute([$caixaProvisorio]);

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

// 6. Banho e tosa de hoje (em andamento) e da próxima semana (marcada pelas regras da agenda)
// hoje: um já pago de manhã, um em atendimento, um pronto esperando o dono, e os da tarde marcados
$hoje = date('Y-m-d');
if (date('w') !== '0') {
    foreach ([['08:00', 'pronto'], ['09:30', 'em_atendimento'], ['11:00', 'pronto'], ['14:00', 'agendado'], ['16:00', 'agendado']] as $i => [$h, $st]) {
        $pet = $petsCao[$i * 2 % count($petsCao)];
        $sv = $servicoDo($pet);
        $ini = "$hoje $h"; $fim = date('Y-m-d H:i', strtotime($ini) + $sv['duracao_min'] * 60);
        $insere->execute([$pet['cliente_id'], $pet['id'], $sv['id'], $ini, $fim, $sv['preco'], $st, $vend['id'], date('Y-m-d H:i:s', strtotime('-2 days'))]);
        if ($i === 0) Venda::registrar((int) $vend['id'], [], ['forma' => 'pix', 'agendamentos' => [(int) $pdo->lastInsertId()]]);
    }
}
// próximos 7 dias, pelas regras da agenda (horário ocupado é pulado)
for ($d = 1; $d <= 7; $d++) {
    $dia = date('Y-m-d', strtotime("+$d days"));
    if (date('w', strtotime($dia)) === '0') continue;
    foreach (['08:30', '10:30', '13:30', '15:30'] as $h) {
        if (mt_rand(1, 3) === 1) continue;
        $pet = $petsCao[mt_rand(0, count($petsCao) - 1)];
        try { Agenda::agendar(['pet_id' => $pet['id'], 'servico_id' => $servicoDo($pet)['id'], 'data' => $dia, 'hora' => $h, 'observacao' => mt_rand(1, 4) === 1 ? 'Dono busca às 17h' : '']); }
        catch (DomainException $e) { }
    }
}

// Carteirinha: vacina anual e vermífugo a cada 3 meses de cada cão e gato. A data da aplicação
// varia por pet, então uns vencem nesta semana, um venceu há pouco e os outros estão em dia.
$como('vendedor');
$haDias = fn(string $rel) => date('Y-m-d', strtotime($rel));
foreach ($pdo->query("SELECT p.id, p.cliente_id, p.especie FROM pets p WHERE p.especie <> 'outro' ORDER BY p.id")->fetchAll() as $i => $pet) {
    $vacina = $pet['especie'] === 'gato' ? 'V4 felina' : 'V10';
    $doses = [
        ['vacina', $vacina, $haDias('-' . (200 + 9 * $i) . ' days'), '+1 year'],
        ['vacina', 'Antirrábica', $haDias('-' . (150 + 11 * $i) . ' days'), '+1 year'],
    ];
    // vermífugo: o de 3 meses atrás foi renovado (fica "já renovada") e o atual vence em dias diferentes por pet
    $doses[] = ['vermifugo', 'Drontal', $haDias('-' . (110 + 6 * $i) . ' days'), '+90 days'];
    $doses[] = ['vermifugo', 'Drontal', $haDias('-' . (20 + 6 * $i) . ' days'), '+90 days'];
    if ($i % 3 === 0) $doses[] = ['antipulgas', 'Bravecto', $haDias('-' . (30 + 5 * $i) . ' days'), '+84 days'];
    foreach ($doses as [$tipo, $nome, $aplicada, $prox]) {
        Cliente::salvarDose(['cliente_id' => $pet['cliente_id'], 'pet_id' => $pet['id'], 'tipo' => $tipo, 'nome' => $nome, 'aplicada_em' => $aplicada,
            'proxima_em' => date('Y-m-d', strtotime("$aplicada $prox")), 'observacao' => $i % 4 === 1 && $tipo === 'vacina' ? 'Aplicada na clínica Vet Amigo' : '']);
        $ultimaAuditoria("$aplicada 10:00:00");
    }
}

// 7. Despesas dos três meses anteriores e do mês atual (até hoje)
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
printf("Pronto: %d agendamentos, %d clientes, %d pets (%d doses na carteirinha, %d vencendo em %d dias), %d produtos, %d lotes, %d vendas (%d canceladas, %d com devolução), %d despesas, %d registros de auditoria.\n",
    $conta('SELECT COUNT(*) FROM agendamentos'), $conta('SELECT COUNT(*) FROM clientes'), $conta('SELECT COUNT(*) FROM pets'), $conta('SELECT COUNT(*) FROM pet_doses'), count(Cliente::dosesAVencer((int) config('doses_aviso_dias'))), config('doses_aviso_dias'), $conta('SELECT COUNT(*) FROM produtos'), $conta('SELECT COUNT(*) FROM lotes'), $conta('SELECT COUNT(*) FROM vendas'),
    $conta("SELECT COUNT(*) FROM vendas WHERE status='cancelada'"), $conta('SELECT COUNT(*) FROM vendas WHERE valor_devolvido > 0'),
    $conta('SELECT COUNT(*) FROM despesas'), $conta('SELECT COUNT(*) FROM auditoria'));
