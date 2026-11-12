<?php
// E5 - Confiabilidade: restaura o backup mais novo numa pasta temporária (nunca no banco em uso)
// e confere integridade e contagem de linhas de todas as tabelas contra o banco atual.
// Uso (depois de rodar o backup): docker compose exec -T -u www-data php php < artigo/experimentos/testar_restauracao.php
$base = '/var/www/html';
$pastas = glob("$base/storage/backups/20*", GLOB_ONLYDIR);
sort($pastas);
$backup = end($pastas) ?: exit("Nenhum backup encontrado.\n");

$inicio = microtime(true);
$destino = sys_get_temp_dir() . '/restauracao-' . bin2hex(random_bytes(4));
mkdir("$destino/fotos", 0700, true);
copy("$backup/petshop.sqlite", "$destino/petshop.sqlite");
foreach (glob("$backup/fotos/*") as $f) copy($f, "$destino/fotos/" . basename($f));
$restaurado = new PDO("sqlite:$destino/petshop.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$integridade = $restaurado->query('PRAGMA integrity_check')->fetchColumn();
$segundos = microtime(true) - $inicio;

$atual = new PDO("sqlite:$base/storage/petshop.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$tabelas = $restaurado->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

echo 'Backup: ' . basename($backup) . "\n";
printf("Restauração em pasta temporária: %.2f s; integrity_check: %s\n", $segundos, $integridade);
printf("Tamanho do banco restaurado: %.1f MB; fotos: %d\n\n", filesize("$destino/petshop.sqlite") / 1048576, count(glob("$destino/fotos/*")));
echo "| Tabela | Linhas no backup | Linhas no banco atual |\n|---|---:|---:|\n";
$iguais = 0;
foreach ($tabelas as $t) {
    $b = (int) $restaurado->query("SELECT COUNT(*) FROM \"$t\"")->fetchColumn();
    $a = (int) $atual->query("SELECT COUNT(*) FROM \"$t\"")->fetchColumn();
    $iguais += $a === $b;
    echo "| $t | $b | $a" . ($a === $b ? '' : ' (mudou depois do backup)') . " |\n";
}
echo "\nTabelas iguais: $iguais de " . count($tabelas) . "\n";

// apaga a cópia restaurada
$restaurado = null;
array_map('unlink', glob("$destino/fotos/*"));
rmdir("$destino/fotos");
unlink("$destino/petshop.sqlite");
rmdir($destino);
