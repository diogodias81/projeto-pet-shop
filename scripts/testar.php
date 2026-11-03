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
