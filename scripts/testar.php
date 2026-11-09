<?php
// Testes das regras principais (venda, lotes, desconto, cancelamento) num banco TEMPORÁRIO: o banco real nunca é aberto.
//   docker compose exec -T php php scripts/testar.php
if (PHP_SAPI !== 'cli') exit("Só pela linha de comando.\n");

$real = dirname(__DIR__);
// BASE_PATH aponta para uma pasta temporária: o config real monta o db_path a partir dela
$tmp = sys_get_temp_dir() . '/petshop-teste-' . getmypid();
mkdir("$tmp/config", 0700, true);
file_put_contents("$tmp/config/config.php", '<?php return require ' . var_export("$real/config/config.php", true) . ';');
define('BASE_PATH', $tmp);
date_default_timezone_set('America/Belem');
ini_set('error_log', "$tmp/php.log"); // a senha inicial do admin de teste vai para cá, não para a tela
require "$real/app/helpers.php";
require "$real/app/Database.php";
spl_autoload_register(fn($c) => is_file($f = "$real/app/Models/$c.php") ? require $f : null);
$_SESSION = [];

$falhas = 0;
function ok(bool $cond, string $nome): void {
    global $falhas;
    echo ($cond ? 'ok   ' : 'FALHA') . " - $nome\n";
    if (!$cond) $falhas++;
}
function recusa(callable $f, string $trecho): bool {
    try { $f(); return false; } catch (DomainException $e) { return str_contains($e->getMessage(), $trecho); }
}

try {
    if (!str_starts_with(config('db_path'), "$tmp/")) throw new RuntimeException('O banco de teste não está na pasta temporária: parei para não abrir o banco real.');
    Database::migrar();
    $pdo = Database::pdo();
    $admin = $pdo->query("SELECT * FROM usuarios WHERE perfil = 'admin'")->fetch();
    $estoque = fn(int $id) => (int) $pdo->query("SELECT estoque FROM produtos WHERE id = $id")->fetchColumn();
    $lote = fn(string $codigo) => (int) $pdo->query("SELECT quantidade FROM lotes WHERE codigo = '$codigo'")->fetchColumn();

    // venda só com caixa aberto
    Produto::salvar(['nome' => 'Sem caixa', 'preco' => '1,00', 'estoque' => '1', 'estoque_minimo' => '0']);
    ok(recusa(fn() => Venda::registrar($admin['id'], [['produto_id' => (int) $pdo->query("SELECT id FROM produtos WHERE nome = 'Sem caixa'")->fetchColumn(), 'quantidade' => 1]], ['forma' => 'pix']), 'caixa está fechado'), 'não vende com o caixa fechado');
    $caixa1 = Caixa::abrir('0');

    Produto::salvar(['nome' => 'Produto de teste', 'preco' => '10,00', 'preco_custo' => '4,00', 'estoque_minimo' => '0']);
    $p = (int) $pdo->query("SELECT id FROM produtos WHERE nome = 'Produto de teste'")->fetchColumn();
    Estoque::entrada($p, 5, '', 'VENCE-DEPOIS', date('Y-m-d', strtotime('+30 days')));
    Estoque::entrada($p, 5, '', 'VENCE-ANTES', date('Y-m-d', strtotime('+10 days')));
    Estoque::entrada($p, 5, '', 'SEM-VALIDADE');
    ok($estoque($p) === 15, 'entradas somam 15 no estoque');

    // 1. Venda tira primeiro do lote que vence antes; sem validade fica por último
    Venda::registrar($admin['id'], [['produto_id' => $p, 'quantidade' => 7]], ['forma' => 'pix']);
    ok($lote('VENCE-ANTES') === 0 && $lote('VENCE-DEPOIS') === 3 && $lote('SEM-VALIDADE') === 5, 'venda de 7 usa o lote que vence antes (5) e depois o seguinte (2)');
    ok($estoque($p) === 8, 'estoque cai para 8');

    // 2. Lote vencido não é vendido (o que sobrou de VENCE-DEPOIS passa a estar vencido)
    $pdo->exec("UPDATE lotes SET validade = date('now','localtime','-1 day') WHERE codigo = 'VENCE-DEPOIS'");
    ok(recusa(fn() => Venda::registrar($admin['id'], [['produto_id' => $p, 'quantidade' => 6]], ['forma' => 'pix']), 'Estoque insuficiente'), 'não vende 6 quando só 5 não estão vencidos');
    ok($estoque($p) === 8 && $lote('VENCE-DEPOIS') === 3, 'venda recusada não mexe no estoque');

    // 3. Desconto do vendedor limitado a 10%
    $max = (float) config('desconto_max_vendedor');
    $item = [['produto_id' => $p, 'quantidade' => 2]]; // subtotal R$ 20,00
    ok(recusa(fn() => Venda::registrar($admin['id'], $item, ['forma' => 'pix', 'desconto_tipo' => 'percentual', 'desconto' => '11'], $max), 'acima do permitido'), 'vendedor não dá 11% de desconto');
    $v = Venda::registrar($admin['id'], $item, ['forma' => 'dinheiro', 'desconto_tipo' => 'percentual', 'desconto' => '10', 'recebido' => '20,00'], $max);
    ok(abs($v['total'] - 18.0) < 0.001, 'vendedor dá 10%: total R$ 18,00');
    ok($estoque($p) === 6, 'estoque cai para 6');

    // 4. Cancelar devolve o estoque (só admin)
    ok(recusa(fn() => Venda::cancelar($v['id'], ['id' => 2, 'perfil' => 'vendedor', 'nome' => 'x'], 'teste'), 'Só o administrador'), 'vendedor não cancela venda');
    Venda::cancelar($v['id'], $admin, 'teste automático');
    ok($estoque($p) === 8 && $lote('SEM-VALIDADE') === 5, 'cancelar devolve as 2 unidades ao lote de onde saíram');
    ok(recusa(fn() => Venda::cancelar($v['id'], $admin, 'de novo'), 'cancelar'), 'não cancela a mesma venda duas vezes');

    // 5. Valores: formatos da tela aceitos, texto com dígitos soltos recusado (antes "1' OR '1'='1" virava 111)
    $lidos = array_map('numero', ['12,50', '1.250,00', 'R$ 1.250,00', '12.5', '12', '1.250', '0.500', '', '-5']);
    ok($lidos === [12.5, 1250.0, 1250.0, 12.5, 12.0, 1250.0, 0.5, 0.0, -5.0], 'numero() lê 12,50 / 1.250,00 / R$ 1.250,00 / 12.5 / 12 / 1.250 / 0.500 / vazio / -5');
    foreach (["1' OR '1'='1", '12abc', '1e5', '1.250.00', '1,250.00', 'abc', '-', '12 15', '1 00', '1R$2', "1\u{A0}234", "\u{FEFF}12", "12\n", '0,001', '50,001', '0.125', '1.250,005'] as $t) ok(recusa(fn() => numero($t), 'valor válido'), 'numero() recusa ' . json_encode($t));
    ok(numero(' R$ 12,50 ') === 12.5 && numero("R$\u{A0}1.234,56") === 1234.56 && numero("\t12\u{A0}") === 12.0, 'numero() aceita "R$" e espaços (inclusive NBSP) só nas pontas');
    ok(recusa(fn() => Produto::salvar(['nome' => 'Milésimo', 'preco' => '0,001']), 'preço de venda válido'), 'preço 0,001 (exibido como R$ 0,00) é recusado');
    ok(recusa(fn() => Despesa::criar(['descricao' => 'Milésimo', 'categoria' => 'outras', 'valor' => '0,001', 'data' => '2099-01-01'], (int) $admin['id']), 'valor válido'), 'despesa 0,001 é recusada pelo valor (antes da data)');
    ok(recusa(fn() => Venda::registrar($admin['id'], [['produto_id' => $p, 'quantidade' => 1]], ['forma' => 'dinheiro', 'recebido' => '50,001']), 'Valor recebido inválido'), 'recebido 50,001 é recusado');
    ok(numero('0,10') === 0.1 && numero('4,35') === 4.35 && numero('0,07') === 0.07, 'numero() aceita centavos (0,10 / 4,35 / 0,07)');
    ok(recusa(fn() => Produto::salvar(['nome' => 'Injeção', 'preco' => "1' OR '1'='1"]), 'preço de venda válido'), 'produto com preço em texto é recusado');

    // 6. Admin não redefine a própria senha (tem que usar "Minha senha", que pede a atual)
    $_SESSION['user'] = ['id' => (int) $admin['id']];
    ok(recusa(fn() => Usuario::redefinirSenha((int) $admin['id'], 'outra123'), 'Minha senha'), 'admin não redefine a própria senha sem a atual');

    // 7. Editar usuário: e-mail único, próprio perfil travado, nunca sem admin ativo
    Usuario::criar(['nome' => 'Caixa Um', 'email' => 'caixa1@teste.com', 'senha' => 'senha123', 'perfil' => 'vendedor']);
    $caixa = (int) $pdo->query("SELECT id FROM usuarios WHERE email = 'caixa1@teste.com'")->fetchColumn();
    // Senha definitiva (primeiro acesso e "Minha senha") recusa as óbvias; a provisória do admin continua livre
    foreach (['senha123', 'Senha123', '123456', 'PETSHOP', '999999', 'caixa1', 'caixa1@teste.com'] as $fraca) ok(recusa(fn() => Usuario::trocarSenha($caixa, $fraca, $fraca), 'fácil de adivinhar'), 'trocar senha recusa a senha óbvia ' . json_encode($fraca));
    ok(recusa(fn() => Usuario::alterarSenha($caixa, 'senha123', '12345678', '12345678'), 'fácil de adivinhar'), 'minha senha recusa 12345678');
    Usuario::trocarSenha($caixa, 'banho do rex 2x', 'banho do rex 2x');
    ok((int) $pdo->query("SELECT trocar_senha FROM usuarios WHERE id = $caixa")->fetchColumn() === 0, 'trocar senha aceita uma frase que não é óbvia');
    Usuario::editar($caixa, ['nome' => 'Caixa Um Silva', 'email' => 'Caixa.Silva@Teste.com', 'perfil' => 'financeiro']);
    $c = Usuario::buscar($caixa);
    ok($c['nome'] === 'Caixa Um Silva' && $c['email'] === 'caixa.silva@teste.com' && $c['perfil'] === 'financeiro', 'editar usuário muda nome, e-mail (minúsculo) e perfil');
    ok(recusa(fn() => Usuario::editar($caixa, ['nome' => 'X', 'email' => $admin['email'], 'perfil' => 'financeiro']), 'já está cadastrado'), 'editar não aceita e-mail de outro usuário');
    ok(recusa(fn() => Usuario::editar((int) $admin['id'], ['nome' => $admin['nome'], 'email' => $admin['email'], 'perfil' => 'vendedor']), 'próprio perfil'), 'admin não muda o próprio perfil');
    Usuario::editar((int) $admin['id'], ['nome' => 'Admin Renomeado', 'email' => $admin['email'], 'perfil' => 'admin']);
    ok(Usuario::buscar((int) $admin['id'])['nome'] === 'Admin Renomeado', 'admin muda o próprio nome');
    // outro admin logado tenta rebaixar o único admin: recusa
    Usuario::criar(['nome' => 'Admin Dois', 'email' => 'admin2@teste.com', 'senha' => 'senha123', 'perfil' => 'admin']);
    $admin2 = (int) $pdo->query("SELECT id FROM usuarios WHERE email = 'admin2@teste.com'")->fetchColumn();
    Usuario::definirAtivo($admin2, false);
    $_SESSION['user'] = ['id' => $admin2];
    ok(recusa(fn() => Usuario::editar((int) $admin['id'], ['nome' => 'Admin Renomeado', 'email' => $admin['email'], 'perfil' => 'vendedor']), 'único administrador'), 'não rebaixa o único admin ativo');
    ok(Usuario::buscar((int) $admin['id'])['perfil'] === 'admin', 'o único admin continua admin');
    // o último admin ativo é um usuário promovido (perfil lido dentro da transação): rebaixá-lo também é recusado
    $pdo->exec("UPDATE usuarios SET perfil = 'admin' WHERE id = $caixa");
    $pdo->exec("UPDATE usuarios SET perfil = 'vendedor' WHERE id = " . (int) $admin['id']);
    ok(recusa(fn() => Usuario::editar($caixa, ['nome' => 'Caixa Um Silva', 'email' => 'caixa.silva@teste.com', 'perfil' => 'financeiro']), 'único administrador'), 'não rebaixa o último admin ativo mesmo que tenha virado admin há pouco');
    $pdo->exec("UPDATE usuarios SET perfil = 'admin' WHERE id = " . (int) $admin['id']);
    $pdo->exec("UPDATE usuarios SET perfil = 'financeiro' WHERE id = $caixa");
    $_SESSION = [];

    // 7b. Nome com no máximo 100 caracteres (500 mil caracteres enchiam usuarios, auditoria e vendas)
    ok(recusa(fn() => Usuario::criar(['nome' => str_repeat('A', 101), 'email' => 'longo@teste.com', 'senha' => 'senha123', 'perfil' => 'vendedor']), '100 caracteres'), 'criar usuário recusa nome com 101 caracteres');
    ok(recusa(fn() => Usuario::editar($caixa, ['nome' => str_repeat('é', 101), 'email' => 'caixa.silva@teste.com', 'perfil' => 'financeiro']), '100 caracteres'), 'editar usuário recusa nome com 101 caracteres');
    Usuario::editar($caixa, ['nome' => str_repeat('é', 100), 'email' => 'caixa.silva@teste.com', 'perfil' => 'financeiro']);
    ok(Usuario::buscar($caixa)['nome'] === str_repeat('é', 100), 'nome com 100 caracteres (acentuados) é aceito');

    // 8. Editar despesa: mesmas validações do cadastro; quem registrou continua o mesmo
    Despesa::criar(['descricao' => 'Conta de luz', 'categoria' => 'energia', 'valor' => '100,00', 'data' => date('Y-m-d')], (int) $admin['id']);
    $desp = (int) $pdo->query("SELECT id FROM despesas WHERE descricao = 'Conta de luz'")->fetchColumn();
    $_SESSION['user'] = ['id' => (int) $admin['id'], 'nome' => 'Admin Renomeado'];
    $auditAntes = (int) $pdo->query("SELECT COUNT(*) FROM auditoria")->fetchColumn();
    ok(Despesa::salvar(['id' => $desp, 'descricao' => 'Conta de luz', 'categoria' => 'energia', 'valor' => '100,00', 'data' => date('Y-m-d')], $caixa) === false
        && (int) $pdo->query("SELECT COUNT(*) FROM auditoria")->fetchColumn() === $auditAntes, 'salvar despesa sem mudança não grava nem audita');
    ok(Usuario::editar($caixa, Usuario::buscar($caixa)) === false && (int) $pdo->query("SELECT COUNT(*) FROM auditoria")->fetchColumn() === $auditAntes, 'salvar usuário sem mudança não grava nem audita');
    Despesa::salvar(['id' => $desp, 'descricao' => 'Conta de luz outubro', 'categoria' => 'energia', 'valor' => '120,50', 'data' => date('Y-m-d')], $caixa);
    ok(str_contains((string) $pdo->query("SELECT detalhes FROM auditoria WHERE acao = 'despesa_alterada' ORDER BY id DESC LIMIT 1")->fetchColumn(), 'descrição "Conta de luz" → "Conta de luz outubro"'), 'auditoria da despesa mostra a descrição antiga');
    $d = Despesa::buscar($desp);
    ok($d['descricao'] === 'Conta de luz outubro' && abs($d['valor'] - 120.5) < 0.001 && (int) $d['usuario_id'] === (int) $admin['id'], 'editar despesa muda descrição e valor e mantém quem registrou');
    ok(recusa(fn() => Despesa::salvar(['id' => $desp, 'descricao' => 'X', 'categoria' => 'energia', 'valor' => '12abc', 'data' => date('Y-m-d')], $caixa), 'valor válido'), 'editar despesa recusa valor inválido');
    ok(recusa(fn() => Despesa::salvar(['id' => 999999, 'descricao' => 'X', 'categoria' => 'energia', 'valor' => '10', 'data' => date('Y-m-d')], $caixa), 'não encontrada'), 'editar despesa inexistente é recusado');
    ok((int) $pdo->query("SELECT COUNT(*) FROM auditoria WHERE acao = 'despesa_alterada'")->fetchColumn() === 1, 'edição de despesa fica na auditoria');
    // data antes de 2000 sumia dos períodos (ano digitado errado)
    ok(recusa(fn() => Despesa::criar(['descricao' => 'Antiga', 'categoria' => 'outras', 'valor' => '10', 'data' => '1999-12-31'], (int) $admin['id']), 'anterior a 01/01/2000'), 'criar despesa recusa data antes de 2000');
    ok(recusa(fn() => Despesa::salvar(['id' => $desp, 'descricao' => 'X', 'categoria' => 'energia', 'valor' => '10', 'data' => '0001-01-01'], $caixa), 'anterior a 01/01/2000'), 'editar despesa recusa data 0001-01-01');
    Despesa::salvar(['id' => $desp, 'descricao' => 'Conta de luz outubro', 'categoria' => 'energia', 'valor' => '120,50', 'data' => '2000-01-01'], $caixa);
    ok(Despesa::buscar($desp)['data'] === '2000-01-01', 'despesa em 01/01/2000 é aceita');

    // 10. Filtro de datas: pega o dia inteiro (00:00:00 a 23:59:59), nada dos vizinhos, e usa o índice
    $nova = $pdo->prepare("INSERT INTO vendas (total, status, criado_em) VALUES (?, 'concluida', ?)");
    foreach ([1 => '2001-03-10 00:00:00', 2 => '2001-03-10 23:59:59', 4 => '2001-03-09 23:59:59', 8 => '2001-03-11 00:00:00'] as $total => $quando) $nova->execute([$total, $quando]);
    ok(abs(Relatorio::faturamento('2001-03-10', '2001-03-10') - 3) < 0.001, 'faturamento do dia conta 00:00:00 e 23:59:59 e não os dias vizinhos');
    ok(count(Venda::listar(['de' => '2001-03-10', 'ate' => '2001-03-10'])) === 2, 'histórico do dia traz as 2 vendas do primeiro ao último segundo');
    $plano = implode(' ', $pdo->query("EXPLAIN QUERY PLAN SELECT SUM(total) FROM vendas WHERE criado_em BETWEEN '2001-03-10' AND '2001-03-10' || ' 23:59:59'")->fetchAll(PDO::FETCH_COLUMN, 3));
    ok(str_contains($plano, 'idx_vendas_data'), 'filtro por período usa o índice de datas');
    $fontes = implode(array_map('file_get_contents', glob("$real/app/Models/*.php")));
    ok(!preg_match('/date\(\w*\.?criado_em\)/', $fontes), 'nenhuma consulta volta a usar date(criado_em), que ignora o índice');

    // 11. Limite de texto no servidor (conta caracteres, não bytes: "ç" são 2 bytes e 1 caractere)
    $texto = fn(int $n) => str_repeat('ç', $n);
    $precos = ['preco' => '10,00', 'preco_custo' => '4,00'];
    ok(recusa(fn() => Produto::salvar(['nome' => $texto(151)] + $precos), 'no máximo 150'), 'produto recusa nome de 151 caracteres');
    Produto::salvar(['nome' => $texto(150)] + $precos);
    $lim = (int) $pdo->query("SELECT id FROM produtos WHERE length(nome) = 150")->fetchColumn();
    ok($lim > 0, 'produto aceita nome de 150 caracteres');
    ok(recusa(fn() => Categoria::criar($texto(151)), 'no máximo 150'), 'categoria recusa nome de 151 caracteres');
    Categoria::criar($texto(150));
    ok((int) $pdo->query("SELECT COUNT(*) FROM categorias WHERE length(nome) = 150")->fetchColumn() === 1, 'categoria aceita nome de 150 caracteres');
    ok(recusa(fn() => Estoque::entrada($lim, 1, '', $texto(101)), 'lote deve ter no máximo 100'), 'entrada recusa lote de 101 caracteres');
    ok(recusa(fn() => Estoque::entrada($lim, 1, $texto(101)), 'fornecedor deve ter no máximo 100'), 'entrada recusa fornecedor de 101 caracteres');
    ok($estoque($lim) === 0, 'entrada recusada não mexe no estoque');
    Estoque::entrada($lim, 10, $texto(100), $texto(100));
    ok($estoque($lim) === 10, 'entrada aceita lote e fornecedor de 100 caracteres');
    // o lote longo vai junto do cadastro do produto: nada fica pela metade
    ok(recusa(fn() => Produto::salvar(['nome' => 'Lote longo', 'estoque' => '1', 'lote' => $texto(101)] + $precos), 'no máximo 100'), 'cadastro com estoque inicial recusa lote de 101 caracteres');
    ok(!$pdo->query("SELECT 1 FROM produtos WHERE nome = 'Lote longo'")->fetchColumn(), 'cadastro recusado pelo lote não deixa o produto criado');
    $idLote = (int) $pdo->query("SELECT id FROM lotes WHERE produto_id = $lim")->fetchColumn();
    ok(recusa(fn() => Estoque::baixa($idLote, 1, 'perda', $texto(201)), 'no máximo 200'), 'baixa recusa observação de 201 caracteres');
    Estoque::baixa($idLote, 1, 'perda', $texto(200));
    ok($estoque($lim) === 9, 'baixa aceita observação de 200 caracteres');
    $vLim = Venda::registrar($admin['id'], [['produto_id' => $lim, 'quantidade' => 2]], ['forma' => 'pix']);
    $itemLim = (int) $pdo->query("SELECT id FROM venda_itens WHERE venda_id = {$vLim['id']}")->fetchColumn();
    ok(recusa(fn() => Venda::devolver($vLim['id'], $admin, [$itemLim => 1], $texto(201)), 'no máximo 200'), 'devolução recusa motivo de 201 caracteres');
    Venda::devolver($vLim['id'], $admin, [$itemLim => 1], $texto(200));
    ok(recusa(fn() => Venda::cancelar($vLim['id'], $admin, $texto(201)), 'no máximo 200'), 'cancelamento recusa motivo de 201 caracteres');
    Venda::cancelar($vLim['id'], $admin, $texto(200));
    ok(Venda::buscar($vLim['id'])['status'] === 'cancelada', 'devolução e cancelamento aceitam motivo de 200 caracteres');
    // registro de antes do limite continua aparecendo e podendo dar baixa
    $pdo->prepare('INSERT INTO lotes (produto_id, codigo, quantidade) VALUES (?, ?, 1)')->execute([$lim, 'A' . str_repeat('X', 1000)]);
    $idAntigo = (int) $pdo->lastInsertId();
    $achou = array_filter(Estoque::lotes(), fn($l) => (int) $l['id'] === $idAntigo);
    Estoque::baixa($idAntigo, 1, 'perda');
    ok(count($achou) === 1 && (int) $pdo->query("SELECT quantidade FROM lotes WHERE id = $idAntigo")->fetchColumn() === 0, 'lote antigo acima do limite continua na lista e aceita baixa');

    // 12. Período: só datas reais de 2000 até o fim do ano que vem (0000 a 9999 estourava o dashboard)
    $hoje = date('Y-m-d'); $mes = date('Y-m-01'); $anoQueVem = (int) date('Y') + 1;
    ok(Relatorio::datas(['de' => '0000-01-01', 'ate' => '9999-12-31']) === [$mes, $hoje], 'período 0000 a 9999 vira o mês atual');
    ok(Relatorio::datas(['de' => '2020-01-01', 'ate' => '2026-11-06']) === ['2020-01-01', '2026-11-06'], 'período de 2020 a 2026 continua valendo');
    ok(Relatorio::datas(['de' => '2000-01-01', 'ate' => "$anoQueVem-12-31"]) === ['2000-01-01', "$anoQueVem-12-31"], 'limites da faixa são aceitos');
    ok(Relatorio::datas(['de' => '1999-12-31', 'ate' => ($anoQueVem + 1) . '-01-01']) === [$mes, $hoje], 'um dia fora da faixa vira o padrão');
    foreach (['x', '0', '', '2026-02-30', "2026-01-01\n", '26-01-01', null, ['2026-01-01']] as $d) ok(!Relatorio::dataValida($d), 'dataValida recusa ' . json_encode($d));
    ok(count(Relatorio::serie('2000-01-01', "$anoQueVem-12-31")) === ($anoQueVem - 1999) * 12, 'maior período possível dá uma série de tamanho limitado, um ponto por mês');
    ok(count(Relatorio::maisVendidos()) > 0, 'mais vendidos sem datas continua usando o padrão interno 0000 a 9999');

    // 13. Erro que já desfez a transação (como "disk I/O error" e RAISE(ROLLBACK)): chega o erro original, não "no active transaction"
    $pdo->exec("CREATE TEMP TRIGGER erro_simulado BEFORE INSERT ON tentativas_login BEGIN SELECT RAISE(ROLLBACK, 'erro simulado'); END");
    try { Usuario::autenticar('x@petshop.com', 'errada', '127.0.0.1'); $erro = ''; } catch (Throwable $e) { $erro = $e->getMessage(); }
    $pdo->exec('DROP TRIGGER erro_simulado');
    ok(str_contains($erro, 'erro simulado'), 'erro que desfaz a transação não é escondido pelo rollBack');
    $fontes = implode(array_map('file_get_contents', glob("$real/app/Models/*.php")));
    ok(!preg_match('/(?<!inTransaction\(\)\) )\$pdo->rollBack\(\)/', $fontes), 'nenhum rollBack sem conferir inTransaction()');

    // 14. Clientes e pets: telefone com DDD, pet só do próprio cliente, excluído não entra em venda, compras no resumo
    $_SESSION['user'] = $admin;
    ok(Cliente::telefone('(91) 98888-7777') === '91988887777' && Cliente::telefone('+55 91 3222-1111') === '9132221111', 'telefone vira só números, aceita +55');
    foreach (['988887777', '(01) 98888-7777', '91 98888-77770', 'abc'] as $t) ok(recusa(fn() => Cliente::telefone($t), 'DDD'), "telefone recusado: $t");
    ok(recusa(fn() => Cliente::salvar(['nome' => 'Ana', 'telefone' => '91988887777', 'email' => 'ana@']), 'E-mail'), 'e-mail inválido recusado');
    $ana = Cliente::salvar(['nome' => 'Ana', 'telefone' => '(91) 98888-7777']);
    $bia = Cliente::salvar(['nome' => 'Bia', 'telefone' => '91 3222-1111']);
    Cliente::salvarPet(['cliente_id' => $ana, 'nome' => 'Thor', 'especie' => 'cao', 'porte' => 'M', 'nascimento' => date('Y-m-d', strtotime('-2 years -1 day'))]);
    $thor = (int) $pdo->query("SELECT id FROM pets WHERE nome = 'Thor'")->fetchColumn();
    ok(Cliente::idade(date('Y-m-d', strtotime('-2 years -1 day'))) === '2 anos', 'idade do pet em anos');
    ok(recusa(fn() => Cliente::salvarPet(['cliente_id' => $ana, 'nome' => 'X', 'especie' => 'peixe']), 'espécie'), 'espécie fora da lista recusada');
    ok(recusa(fn() => Cliente::salvarPet(['cliente_id' => $ana, 'nome' => 'X', 'especie' => 'gato', 'nascimento' => date('Y-m-d', strtotime('+1 day'))]), 'nascimento'), 'nascimento no futuro recusado');
    ok(recusa(fn() => Cliente::salvarPet(['id' => $thor, 'cliente_id' => $bia, 'nome' => 'Roubado', 'especie' => 'cao']), 'Pet não encontrado'), 'não edita pet de outro cliente trocando o cliente_id');
    ok(recusa(fn() => Cliente::excluirPet($thor, $bia), 'Pet não encontrado'), 'não exclui pet de outro cliente');
    ok(count(Cliente::listar('thor')) === 1 && count(Cliente::listar('3222')) === 1 && count(Cliente::listar('(91) 9888')) === 1, 'busca por nome do pet e por telefone com máscara');
    $vc = Venda::registrar($admin['id'], [['produto_id' => $p, 'quantidade' => 1]], ['forma' => 'pix', 'cliente_id' => $ana]);
    ok((int) Venda::buscar($vc['id'])['cliente_id'] === $ana && Venda::buscar($vc['id'])['cliente'] === 'Ana', 'venda guarda o cliente');
    ok((int) Cliente::resumo($ana)['compras'] === 1 && abs(Cliente::resumo($ana)['gasto'] - 10.0) < 0.001, 'resumo do cliente conta a compra');
    Venda::cancelar($vc['id'], $admin, 'teste');
    ok((int) Cliente::resumo($ana)['compras'] === 0, 'venda cancelada sai do resumo do cliente');
    Cliente::excluir($bia);
    $antes = $estoque($p);
    ok(recusa(fn() => Venda::registrar($admin['id'], [['produto_id' => $p, 'quantidade' => 1]], ['forma' => 'pix', 'cliente_id' => $bia]), 'Cliente não encontrado'), 'cliente excluído não entra em venda');
    ok($estoque($p) === $antes, 'venda recusada pelo cliente não mexe no estoque');
    ok(count(Cliente::listar()) === 1, 'cliente excluído some da lista');
    $pat = Cliente::salvar(['nome' => 'Patrícia Gomes', 'telefone' => '91 98111-2222']);
    ok(count(Cliente::listar('patricia')) === 1 && count(Cliente::listar('PATRÍCIA')) === 1 && count(Cliente::listar('gomes')) === 1, 'busca ignora acento e maiúsculas');
    ok(recusa(fn() => Cliente::salvar(['nome' => 'Outra', 'telefone' => '(91) 98111-2222']), 'Já existe cliente com este telefone'), 'telefone repetido pede confirmação');
    ok(Cliente::salvar(['nome' => 'Filha da Patrícia', 'telefone' => '(91) 98111-2222', 'mesmo_telefone' => '1']) > 0, 'com a confirmação, aceita o mesmo telefone');
    ok(Cliente::salvar(['id' => $pat, 'nome' => 'Patrícia Gomes', 'telefone' => '91 98111-2222', 'mesmo_telefone' => '1']) === $pat, 'editar o próprio cliente não conta como repetido');

    // 14b. Carteirinha: datas válidas, pet só do cliente, dose renovada sai do aviso, vencida há muito também
    $hojeT = date('Y-m-d');
    $dose = fn(array $d) => Cliente::salvarDose($d + ['cliente_id' => $ana, 'pet_id' => $thor, 'tipo' => 'vacina', 'nome' => 'V10', 'aplicada_em' => $hojeT]);
    ok(recusa(fn() => $dose(['aplicada_em' => date('Y-m-d', strtotime('+1 day'))]), 'aplicação'), 'dose aplicada no futuro recusada');
    ok(recusa(fn() => $dose(['proxima_em' => $hojeT]), 'Próxima dose'), 'próxima dose no mesmo dia da aplicação recusada');
    ok(recusa(fn() => $dose(['proxima_em' => date('Y-m-d', strtotime('+4 years'))]), 'Próxima dose'), 'próxima dose depois de 3 anos recusada');
    ok(recusa(fn() => $dose(['tipo' => 'banho']), 'vermífugo'), 'tipo fora da lista recusado');
    ok(recusa(fn() => $dose(['cliente_id' => $bia]), 'pets deste cliente'), 'não registra dose em pet de outro cliente');
    $dose(['aplicada_em' => date('Y-m-d', strtotime('-360 days')), 'proxima_em' => date('Y-m-d', strtotime('+5 days'))]);           // V10 vence em 5 dias
    $dose(['nome' => 'Antirrábica', 'aplicada_em' => date('Y-m-d', strtotime('-1 year')), 'proxima_em' => date('Y-m-d', strtotime('-90 days'))]); // vencida há muito
    $dose(['tipo' => 'vermifugo', 'nome' => 'Drontal', 'aplicada_em' => date('Y-m-d', strtotime('-100 days')), 'proxima_em' => date('Y-m-d', strtotime('-10 days'))]); // vencida há pouco
    $avisos = Cliente::dosesAVencer(7);
    ok(count($avisos) === 2 && $avisos[0]['nome'] === 'Drontal' && (int) $avisos[0]['dias'] === -10 && (int) $avisos[1]['dias'] === 5, 'aviso: vencida há pouco e a que vence em 5 dias (a vencida há 90 dias não)');
    $dose(['tipo' => 'vermifugo', 'nome' => 'Vermivet', 'proxima_em' => date('Y-m-d', strtotime('+90 days'))]);   // vermífugo de outra marca renova
    $dose(['nome' => 'v10', 'proxima_em' => date('Y-m-d', strtotime('+1 year'))]);                                 // V10 renovada (sem diferenciar maiúscula)
    ok(Cliente::dosesAVencer(7) === [], 'dose renovada sai do aviso (vermífugo de qualquer marca, vacina do mesmo nome)');
    $doses = Cliente::doses($ana);
    ok(count($doses) === 5 && count(array_filter($doses, fn($d) => $d['atual'])) === 3, 'carteirinha mostra tudo, com as renovadas marcadas');
    ok(recusa(fn() => Cliente::excluirDose((int) $doses[0]['id'], $bia), 'não encontrado'), 'não exclui dose de pet de outro cliente');
    Cliente::excluirDose((int) $doses[0]['id'], $ana);
    ok(count(Cliente::doses($ana)) === 4, 'exclui dose da carteirinha');
    ok(str_starts_with(Cliente::whatsapp('91988887777', 'Olá, Ana & cia'), 'https://wa.me/5591988887777?text=Ol%C3%A1%2C%20Ana%20%26%20cia'), 'link do WhatsApp com +55 e a mensagem codificada');
    ok(str_contains(Cliente::mensagemDose(['cliente' => 'Ana Souza', 'tipo' => 'vermifugo', 'nome' => 'Drontal', 'pet' => 'Thor', 'proxima_em' => $hojeT, 'dias' => 0]), 'Olá, Ana! ') , 'mensagem da dose usa o primeiro nome');

    // 15. Banho e tosa: horário livre, expediente, pet do cliente, cobrança no caixa e estorno ao cancelar
    $amanha = date('Y-m-d', strtotime('+1 day'));
    ok(recusa(fn() => Agenda::salvarServico(['nome' => 'X', 'preco' => '10', 'duracao_min' => '20']), 'passos de 15'), 'duração fora dos passos de 15 recusada');
    Agenda::salvarServico(['nome' => 'Banho P', 'preco' => '45,00', 'duracao_min' => '60']);
    Agenda::salvarServico(['nome' => 'Tosa', 'preco' => '70,00', 'duracao_min' => '90']);
    $banho = (int) $pdo->query("SELECT id FROM servicos WHERE nome = 'Banho P'")->fetchColumn();
    $tosa = (int) $pdo->query("SELECT id FROM servicos WHERE nome = 'Tosa'")->fetchColumn();
    Cliente::salvarPet(['cliente_id' => $ana, 'nome' => 'Mimi', 'especie' => 'gato']);
    $mimi = (int) $pdo->query("SELECT id FROM pets WHERE nome = 'Mimi'")->fetchColumn();
    $a1 = Agenda::agendar(['pet_id' => $thor, 'servico_id' => $banho, 'data' => $amanha, 'hora' => '09:00']);
    ok(Agenda::buscar($a1)['fim'] === "$amanha 10:00" && (float) Agenda::buscar($a1)['preco'] === 45.0, 'agendamento termina pela duração e guarda o preço');
    ok(recusa(fn() => Agenda::agendar(['pet_id' => $mimi, 'servico_id' => $banho, 'data' => $amanha, 'hora' => '09:30']), 'Horário ocupado'), 'com 1 atendimento por vez, recusa horário que cruza outro');
    ok(recusa(fn() => Agenda::agendar(['pet_id' => $mimi, 'servico_id' => $tosa, 'data' => $amanha, 'hora' => '08:00']), 'Horário ocupado'), 'recusa serviço que começa antes e termina dentro de outro');
    $a2 = Agenda::agendar(['pet_id' => $mimi, 'servico_id' => $banho, 'data' => $amanha, 'hora' => '10:00']);
    ok(in_array(["$amanha 09:00", Agenda::buscar($a1)['fim']], Agenda::ocupados(), true) && in_array(["$amanha 10:00", Agenda::buscar($a2)['fim']], Agenda::ocupados(), true), 'ocupados() lista os horários tomados (formulário marca "(ocupado)")');
    ok($a2 > 0, 'horário encostado (termina 10:00, começa 10:00) é aceito');
    ok(recusa(fn() => Agenda::agendar(['pet_id' => $mimi, 'servico_id' => $tosa, 'data' => $amanha, 'hora' => '18:00']), 'Fora do horário'), 'serviço que passa do fechamento é recusado');
    ok(recusa(fn() => Agenda::agendar(['pet_id' => $mimi, 'servico_id' => $banho, 'data' => date('Y-m-d', strtotime('-1 day')), 'hora' => '10:00']), 'já passou'), 'horário passado é recusado');
    ok(recusa(fn() => Agenda::agendar(['pet_id' => $mimi, 'servico_id' => $banho, 'data' => $amanha, 'hora' => '11:10']), '15 em 15'), 'minuto quebrado é recusado');
    Agenda::mudarStatus($a2, 'cancelado');
    $a3 = Agenda::agendar(['pet_id' => $mimi, 'servico_id' => $banho, 'data' => $amanha, 'hora' => '10:30']);
    ok($a3 > 0, 'cancelado libera o horário');
    ok(recusa(fn() => Agenda::mudarStatus($a2, 'agendado'), 'Horário ocupado'), 'reabrir o cancelado confere a vaga de novo');
    ok(recusa(fn() => Agenda::mudarStatus($a1, 'pronto'), 'Não dá para passar'), 'não pula de agendado para pronto');
    Agenda::mudarStatus($a1, 'em_atendimento'); Agenda::mudarStatus($a1, 'pronto');
    $antes = $estoque($p);
    $vs = Venda::registrar($admin['id'], [['produto_id' => $p, 'quantidade' => 1]], ['forma' => 'pix', 'agendamentos' => (string) $a1]);
    $vsb = Venda::buscar($vs['id']);
    ok(abs($vs['total'] - 55.0) < 0.001 && count($vsb['servicos']) === 1, 'venda soma produto (10) + banho (45)');
    ok((int) $vsb['cliente_id'] === $ana, 'venda sem cliente fica com o dono do pet');
    ok(Agenda::buscar($a1)['status'] === 'pago' && (int) Agenda::buscar($a1)['venda_id'] === $vs['id'], 'agendamento vira pago com o número da venda');
    ok(recusa(fn() => Venda::registrar($admin['id'], [], ['forma' => 'pix', 'agendamentos' => [$a1]]), 'já foi pago'), 'não cobra o mesmo serviço duas vezes');
    ok(recusa(fn() => Agenda::mudarStatus($a1, 'agendado'), 'cancele a venda'), 'pago não volta pela agenda');
    ok(abs(Venda::lucro($vsb) - 51.0) < 0.001, 'serviço entra no lucro sem custo (55 - custo 4,00 do produto)');
    Venda::devolver($vs['id'], $admin, [$vsb['itens'][0]['id'] => 1], 'teste');
    ok(Venda::situacao(Venda::buscar($vs['id'])) === 'Devolução parcial', 'devolver todos os produtos de venda com serviço não vira "Devolvida"');
    Venda::cancelar($vs['id'], $admin, 'teste');
    ok(Agenda::buscar($a1)['status'] === 'pronto' && Agenda::buscar($a1)['venda_id'] === null, 'cancelar a venda devolve o serviço para "pronto"');
    ok($estoque($p) === $antes, 'estoque volta ao que era');
    $so = Venda::registrar($admin['id'], [], ['forma' => 'dinheiro', 'recebido' => '50', 'agendamentos' => [$a1]]);
    ok(abs($so['total'] - 45.0) < 0.001, 'venda só com serviço, em dinheiro');
    ok(str_contains(Venda::listar(['cliente_id' => $ana])[0]['itens'], 'Banho P — Thor'), 'histórico lista o serviço junto dos itens');
    $rs = Relatorio::servicos(date('Y-m-d'), date('Y-m-d'));
    ok(count($rs) === 1 && $rs[0]['nome'] === 'Banho P' && (int) $rs[0]['qtd'] === 1 && abs($rs[0]['receita'] - 45) < 0.005, 'relatório de banho e tosa: só a venda concluída (a cancelada sai)');
    ok((int) Cliente::resumo($ana, (int) $admin['id'])['compras'] > 0 && (int) Cliente::resumo($ana, 999999)['compras'] === 0, 'resumo do cliente filtrado pelo vendedor só conta as vendas dele');

    // 15a. Pago antes do atendimento: segue o andamento; cancelar a venda devolve à situação de antes (não a "pronto")
    $p5 = Agenda::agendar(['pet_id' => $thor, 'servico_id' => $banho, 'data' => $amanha, 'hora' => '12:00']);
    Venda::registrar($admin['id'], [], ['forma' => 'pix', 'agendamentos' => [$p5]]);
    ok(Agenda::buscar($p5)['status'] === 'agendado' && Agenda::buscar($p5)['venda_id'], 'pago adiantado continua "agendado", com a venda');
    ok(recusa(fn() => Agenda::mudarStatus($p5, 'faltou'), 'já foi pago'), 'pago adiantado não vira "faltou" sem cancelar a venda');
    ok(recusa(fn() => Agenda::mudarStatus($a3, 'faltou'), 'Ainda não é a hora'), 'falta só depois do horário');
    Agenda::mudarStatus($p5, 'em_atendimento'); Agenda::mudarStatus($p5, 'pronto');
    ok(Agenda::buscar($p5)['status'] === 'pago', 'pago adiantado, ao ficar pronto, termina como "pago"');
    $p6 = Agenda::agendar(['pet_id' => $thor, 'servico_id' => $banho, 'data' => $amanha, 'hora' => '13:00']);
    $v6 = Venda::registrar($admin['id'], [], ['forma' => 'pix', 'agendamentos' => [$p6]]);
    Venda::cancelar($v6['id'], $admin, 'teste');
    ok(Agenda::buscar($p6)['status'] === 'agendado' && Agenda::buscar($p6)['venda_id'] === null, 'cancelar venda paga adiantado volta a "agendado" (não "pronto")');

    // 15b. Serviço de outro cliente não entra na venda; pet/cliente excluído cancela a agenda e não é cobrado
    $caio = Cliente::salvar(['nome' => 'Caio', 'telefone' => '91 98777-1111']);
    ok(recusa(fn() => Venda::registrar($admin['id'], [], ['forma' => 'pix', 'cliente_id' => $caio, 'agendamentos' => [$a3]]), 'outro cliente'), 'venda com serviço de pet de outro cliente é recusada');
    ok(Agenda::buscar($a3)['status'] === 'agendado', 'serviço recusado continua em aberto');
    $va = Venda::registrar($admin['id'], [], ['forma' => 'pix', 'cliente_id' => $ana, 'agendamentos' => [$a3]]);
    ok((int) Venda::buscar($va['id'])['cliente_id'] === $ana, 'venda com o dono do pet escolhido é aceita');
    $a4 = Agenda::agendar(['pet_id' => $mimi, 'servico_id' => $banho, 'data' => $amanha, 'hora' => '14:00']);
    Cliente::salvarPet(['cliente_id' => $caio, 'nome' => 'Rex', 'especie' => 'cao']);
    $a5 = Agenda::agendar(['pet_id' => (int) $pdo->query("SELECT id FROM pets WHERE nome = 'Rex'")->fetchColumn(), 'servico_id' => $banho, 'data' => $amanha, 'hora' => '15:00']);
    foreach (['', 'abc'] as $semCliente) {
        ok(recusa(fn() => Venda::registrar($admin['id'], [], ['forma' => 'pix', 'cliente_id' => $semCliente, 'agendamentos' => [$a4, $a5]]), 'Cobre um cliente por venda'), "venda sem cliente ('$semCliente') com serviços de donos diferentes é recusada");
    }
    $linhas = fn(int $n) => array_map(fn($i) => ['produto_id' => 900000 + $i, 'quantidade' => 1], range(1, $n));
    ok(recusa(fn() => Venda::registrar($admin['id'], $linhas(201), ['forma' => 'pix']), 'Carrinho grande demais: no máximo 200 linhas'), 'carrinho com mais de 200 linhas é recusado');
    ok(recusa(fn() => Venda::registrar($admin['id'], $linhas(200), ['forma' => 'pix']), 'Item inválido'), 'carrinho com 200 linhas passa do limite e vai para a conferência dos produtos');
    ok(strlen(json_encode($linhas(200))) < Venda::MAX_ITENS_BYTES, 'o JSON de 200 linhas cabe no limite de bytes da tela');
    ok(Venda::idsAgendamentos('3,3,abc,0,-1,5') === [3, 5] && Venda::idsAgendamentos(['7', '7', 'x']) === [7], 'ids de agendamento: só inteiros positivos, sem repetir');
    ok(recusa(fn() => Venda::registrar($admin['id'], [], ['forma' => 'pix', 'agendamentos' => implode(',', range(1000, 1020))]), 'No máximo 20 serviços por venda'), 'venda com mais de 20 serviços é recusada');
    ok(recusa(fn() => Venda::registrar($admin['id'], [], ['forma' => 'pix', 'agendamentos' => str_repeat("$a4,", 300) . implode(',', range(1000, 1018))]), 'não encontrado'), 'ids repetidos contam uma vez (20 distintos passam do limite e vão para a conferência)');
    ok(Agenda::buscar($a4)['status'] === 'agendado' && Agenda::buscar($a5)['status'] === 'agendado', 'serviços recusados continuam em aberto');
    Cliente::excluirPet($mimi, $ana);
    ok(Agenda::buscar($a4)['status'] === 'cancelado' && Agenda::buscar($a3)['status'] === 'agendado' && Agenda::buscar($a3)['venda_id'], 'excluir o pet cancela os agendamentos em aberto (o pago adiantado fica)');
    ok(recusa(fn() => Agenda::mudarStatus($a4, 'agendado'), 'foi excluído'), 'agendamento de pet excluído não muda de situação');
    $pdo->exec("UPDATE agendamentos SET status = 'pronto' WHERE id = $a4"); // como os que ficaram abertos antes desta regra
    ok(recusa(fn() => Venda::registrar($admin['id'], [], ['forma' => 'pix', 'agendamentos' => [$a4]]), 'foi excluído'), 'serviço de pet excluído não é cobrado');
    Cliente::excluir($caio);
    ok(Agenda::buscar($a5)['status'] === 'cancelado', 'excluir o cliente cancela os agendamentos em aberto');
    ok((int) $pdo->query("SELECT COUNT(*) FROM auditoria WHERE acao = 'agendamento_status' AND detalhes LIKE '%excluído)'")->fetchColumn() === 2, 'cancelamento pela exclusão fica na auditoria');
    // venda cancelada depois que o pet foi excluído: o serviço vai para "cancelado", não fica "pronto" preso
    Cliente::excluirPet($thor, $ana);
    ok(Agenda::buscar($a1)['status'] === 'pago', 'excluir o pet não mexe no serviço já pago');
    Venda::cancelar($so['id'], $admin, 'teste');
    ok(Agenda::buscar($a1)['status'] === 'cancelado' && Agenda::buscar($a1)['venda_id'] === null, 'estorno de serviço de pet excluído vai para "cancelado"');

    // 16. Caixa: esperado = troco + vendas em dinheiro + reforço − sangria − devolvido em dinheiro; fechamento com contagem
    $c1 = Caixa::fechar((string) Caixa::resumo(Caixa::aberto())['esperado'], '');
    ok(abs((float) $c1['diferenca']) < 0.005, 'caixa de teste anterior fecha batendo com o esperado');
    ok(recusa(fn() => Caixa::fechar('0', ''), 'já está fechado'), 'não fecha caixa já fechado');
    ok(recusa(fn() => Caixa::movimentar('sangria', '10', 'x'), 'fechado'), 'sangria só com caixa aberto');
    ok(recusa(fn() => Caixa::abrir('-5'), 'Troco'), 'troco negativo recusado');
    Caixa::abrir('100,00');
    ok(recusa(fn() => Caixa::abrir('10'), 'já está aberto'), 'só um caixa aberto por vez');
    $cx = Caixa::aberto();
    Estoque::entrada($p, 10, '');
    $d1 = Venda::registrar($admin['id'], [['produto_id' => $p, 'quantidade' => 3]], ['forma' => 'dinheiro', 'recebido' => '50']); // 30, troco 20
    Venda::registrar($admin['id'], [['produto_id' => $p, 'quantidade' => 2]], ['forma' => 'pix']);                           // 20 no PIX
    $d2 = Venda::registrar($admin['id'], [['produto_id' => $p, 'quantidade' => 1]], ['forma' => 'dinheiro']);                 // 10
    Caixa::movimentar('reforco', '50', 'troco extra');
    Caixa::movimentar('sangria', '80', 'depósito');
    ok(recusa(fn() => Caixa::movimentar('sangria', '1000', 'x'), 'maior que o dinheiro'), 'sangria maior que a gaveta é recusada');
    ok(recusa(fn() => Caixa::movimentar('reforco', '100000,01', 'x'), 'limite') && recusa(fn() => Caixa::movimentar('sangria', '100000,01', 'x'), 'limite'), 'sangria/reforço acima de R$ 100.000,00 recusados');
    Venda::devolver($d1['id'], $admin, [Venda::buscar($d1['id'])['itens'][0]['id'] => 1], 'teste'); // devolve 10 em dinheiro
    Venda::cancelar($d2['id'], $admin, 'teste');                                                  // estorna 10 em dinheiro
    $r = Caixa::resumo($cx);
    ok(abs($r['vendas_dinheiro'] - 40) < 0.005 && abs(($r['formas']['pix']['total'] ?? 0) - 20) < 0.005, 'vendas por forma: 40 em dinheiro, 20 no PIX');
    ok(abs($r['devolvido_dinheiro'] - 20) < 0.005, 'devolução e cancelamento em dinheiro saem da gaveta (20)');
    ok(abs($r['esperado'] - 90) < 0.005, 'esperado = 100 + 40 + 50 − 80 − 20 = 90');
    ok(abs(($r['formas']['dinheiro']['estornado'] ?? 0) - 20) < 0.005, 'por forma: mostra o cancelado/devolvido (20 em dinheiro)');
    ok(recusa(fn() => Caixa::conferirEstorno(90.01), 'faça um reforço'), 'estorno em dinheiro maior que a gaveta é recusado');
    Caixa::conferirEstorno(90);
    ok(recusa(fn() => Caixa::conferirEstorno(10, -100), 'A gaveta está sem dinheiro pela conta do sistema (esperado -R$ 10,00): faça um reforço no Caixa antes de devolver R$ 10,00'), 'gaveta com esperado negativo: mensagem sem "deveria ter só -R$"');
    ok(true, 'estorno até o dinheiro da gaveta é aceito');
    ok(recusa(fn() => Caixa::fechar('85', ''), 'falta de R$'), 'contagem que não bate pede observação');
    $fc = Caixa::fechar('85,00', 'faltou troco');
    ok(abs($fc['diferenca'] + 5) < 0.005 && abs($fc['esperado'] - 90) < 0.005, 'fecha com falta de R$ 5,00 registrada');
    ok(recusa(fn() => Venda::registrar($admin['id'], [['produto_id' => $p, 'quantidade' => 1]], ['forma' => 'pix']), 'caixa está fechado'), 'depois de fechar, venda bloqueada');
    ok((int) Venda::buscar($d1['id'])['caixa_id'] === (int) $cx['id'], 'venda guarda o caixa em que foi feita');
    ok(recusa(fn() => Venda::devolver($d1['id'], $admin, [Venda::buscar($d1['id'])['itens'][0]['id'] => 1], 'teste'), 'abra o caixa'), 'devolução em dinheiro pede o caixa aberto');
    ok(recusa(fn() => Venda::cancelar($d1['id'], $admin, 'teste'), 'abra o caixa'), 'cancelamento em dinheiro pede o caixa aberto');

    // vendedor conta às cegas: nenhuma mensagem mostra o valor esperado
    $sessaoAntes = $_SESSION;
    // id próprio: o limite de sangria do vendedor soma as dele no dia, e o admin já fez sangria hoje
    $_SESSION['user'] = ['id' => $admin2, 'perfil' => 'vendedor', 'nome' => 'Vendedor teste'];
    Caixa::abrir('30');
    // sangria do vendedor não é comparada com o esperado: aceitar/recusar revelaria o valor (busca binária)
    Caixa::movimentar('sangria', '40', 'depósito');
    ok(abs(Caixa::resumo(Caixa::aberto())['sangrias'] - 40) < 0.005, 'sangria do vendedor maior que a gaveta é registrada, sem oráculo do esperado');
    // teto da soma das sangrias do vendedor no dia (500), que não depende do esperado
    Caixa::movimentar('sangria', '460', 'depósito');
    ok(recusa(fn() => Caixa::movimentar('sangria', '0,01', 'x'), 'limite do vendedor no dia (R$ 500,00 somadas)'), 'sangria do vendedor acima de R$ 500,00 somadas no dia é recusada');
    Caixa::movimentar('reforco', '10000', 'troco extra');
    ok(recusa(fn() => Caixa::movimentar('sangria', '0,01', 'x'), 'limite do vendedor'), 'o teto do vendedor não muda com o dinheiro na gaveta (não é oráculo do esperado)');
    $fv = Caixa::fechar('5', '');
    ok(abs($fv['diferenca'] + 9525) < 0.005, 'vendedor fecha sem observação e a diferença fica registrada (30 + 10.000 − 500 = 9.530, contou 5)');
    Caixa::abrir('30');
    ok(recusa(fn() => Caixa::movimentar('sangria', '0,01', 'x'), 'limite do vendedor no dia'), 'fechar e reabrir o caixa não zera o limite de sangria do vendedor');
    $pdo->exec("UPDATE caixa_movimentos SET criado_em = datetime(criado_em, '-1 day') WHERE tipo = 'sangria'"); // as sangrias de ontem não contam hoje
    Caixa::movimentar('sangria', '500', 'depósito');
    ok(abs(Caixa::resumo(Caixa::aberto())['sangrias'] - 500) < 0.005, 'no dia seguinte o limite do vendedor recomeça');
    Caixa::fechar('0', '');
    $_SESSION = $sessaoAntes;

    $_SESSION = [];
} catch (Throwable $e) {
    echo 'ERRO - ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    $falhas++;
} finally {
    // apaga a pasta temporária inteira (banco de teste incluído)
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    rmdir($tmp);
}

echo $falhas ? "\n$falhas teste(s) falharam.\n" : "\nTodos os testes passaram.\n";
exit($falhas ? 1 : 0);
