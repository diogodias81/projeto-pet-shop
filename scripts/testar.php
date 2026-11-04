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
