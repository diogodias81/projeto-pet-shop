<?php
/** Cópia diária do banco e das fotos em storage/backups (rodada por scripts/backup.php, agendado no Windows). */
class Backup {
    const RETENCAO_DIAS = 30;

    public static function pasta(): string {
        return BASE_PATH . '/storage/backups';
    }

    /** Faz o backup, confere a cópia e apaga os antigos. Grava o resultado (ok ou erro) para o Dashboard avisar. */
    public static function executar(): string {
        $destino = self::pasta() . '/' . date('Y-m-d_Hi');
        try {
            if (is_dir($destino)) throw new RuntimeException("Já existe um backup em $destino.");
            mkdir($destino . '/fotos', 0775, true);

            // VACUUM INTO: cópia consistente mesmo com o sistema aberto (copiar o arquivo no meio de uma venda pode corromper)
            Database::pdo()->exec('VACUUM INTO ' . Database::pdo()->quote($destino . '/petshop.sqlite'));

            $copia = new PDO('sqlite:' . $destino . '/petshop.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $integridade = $copia->query('PRAGMA integrity_check')->fetchColumn();
            if ($integridade !== 'ok') throw new RuntimeException("Cópia do banco com problema: $integridade");
            $vendas = (int) $copia->query('SELECT COUNT(*) FROM vendas')->fetchColumn();
            $copia = null;

            $fotos = 0;
            foreach (glob(BASE_PATH . '/public/uploads/produtos/*') ?: [] as $foto) {
                if (is_file($foto) && copy($foto, $destino . '/fotos/' . basename($foto))) $fotos++;
            }

            $apagados = self::apagarAntigos();
            $msg = "Backup ok: $vendas vendas, $fotos fotos" . ($apagados ? ", $apagados backup(s) antigo(s) apagado(s)" : '');
            self::registrar(true, $msg, basename($destino));
            return $msg;
        } catch (Throwable $e) {
            self::registrar(false, $e->getMessage(), basename($destino));
            throw $e;
        }
    }

    /** Último resultado: ['quando' => 'Y-m-d H:i:s', 'ok' => bool, 'mensagem' => ..., 'pasta' => ...] ou null se nunca rodou */
    public static function ultimo(): ?array {
        $json = @file_get_contents(self::pasta() . '/ultimo.json');
        return $json ? json_decode($json, true) : null;
    }

    private static function apagarAntigos(): int {
        $limite = date('Y-m-d_Hi', strtotime('-' . self::RETENCAO_DIAS . ' days'));
        $apagados = 0;
        foreach (glob(self::pasta() . '/????-??-??_????', GLOB_ONLYDIR) ?: [] as $dir) {
            if (basename($dir) >= $limite) continue;
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($dir);
            $apagados++;
        }
        return $apagados;
    }

    private static function registrar(bool $ok, string $mensagem, string $pasta): void {
        if (!is_dir(self::pasta())) mkdir(self::pasta(), 0775, true);
        $quando = date('Y-m-d H:i:s');
        file_put_contents(self::pasta() . '/ultimo.json', json_encode(compact('quando', 'ok', 'mensagem', 'pasta'), JSON_UNESCAPED_UNICODE));
        file_put_contents(self::pasta() . '/backup.log', "$quando " . ($ok ? 'OK' : 'FALHA') . " $pasta: $mensagem\n", FILE_APPEND);
    }
}
