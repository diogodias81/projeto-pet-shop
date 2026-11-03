<?php
/** Cópia diária do banco e das fotos em storage/backups (rodada por scripts/backup.php, agendado no Windows). */
class Backup {
    const RETENCAO_DIAS = 30;
    const MINIMO_COPIAS = 7; // nunca apaga as 7 mais novas, mesmo com o relógio errado
    const ESPERA_TRAVA = 600; // segundos esperando outro backup terminar antes de desistir com erro
    const NOME = '/^\d{4}-\d{2}-\d{2}_\d{4}(\d{2})?$/'; // AAAA-MM-DD_HHMMSS (o HHMM é dos backups antigos)

    public static function pasta(): string {
        return BASE_PATH . '/storage/backups';
    }

    /** Faz o backup, confere a cópia e apaga os antigos. Grava o resultado (ok ou erro) para o Dashboard avisar. */
    public static function executar(): string {
        $criada = false;
        try {
            // sem isto o PDO criaria um banco vazio e o "backup" sairia vazio
            if (!is_file(config('db_path'))) throw new RuntimeException('Banco de dados não encontrado em ' . config('db_path') . '.');
            if (!is_dir(self::pasta())) mkdir(self::pasta(), 0775, true);
            // Dois backups ao mesmo tempo esperam a vez (até o fim da limpeza): senão disputam os antigos e os dois avisam falha
            $trava = fopen(self::pasta() . '/.lock', 'c');
            if (!$trava) throw new RuntimeException('Não foi possível travar a pasta de backups.');
            // sem prazo, um backup pendurado deixaria os seguintes (e o docker exec) presos sem log nem aviso
            for ($limite = time() + self::ESPERA_TRAVA; !flock($trava, LOCK_EX | LOCK_NB); sleep(2)) {
                if (time() >= $limite) throw new RuntimeException('Outro backup em andamento há mais de ' . intdiv(self::ESPERA_TRAVA, 60) . ' minutos.');
            }
            // Dois backups no mesmo segundo: espera o próximo nome livre (mkdir é atômico e serve de trava).
            // Grava em .tmp: só vira pasta de backup (nome válido) depois de tudo conferido.
            for ($i = 0; $i < 5 && !$criada; $i++) {
                if ($i) sleep(1);
                $destino = self::pasta() . '/' . date('Y-m-d_His');
                $tmp = $destino . '.tmp';
                $criada = !file_exists($destino) && @mkdir($tmp);
            }
            if (!$criada) throw new RuntimeException('Não foi possível criar a pasta do backup em ' . self::pasta() . '.');
            mkdir($tmp . '/fotos');

            // VACUUM INTO: cópia consistente mesmo com o sistema aberto (copiar o arquivo no meio de uma venda pode corromper)
            Database::pdo()->exec('VACUUM INTO ' . Database::pdo()->quote($tmp . '/petshop.sqlite'));

            $copia = new PDO('sqlite:' . $tmp . '/petshop.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $integridade = $copia->query('PRAGMA integrity_check')->fetchColumn();
            if ($integridade !== 'ok') throw new RuntimeException("Cópia do banco com problema: $integridade");
            $vendas = (int) $copia->query('SELECT COUNT(*) FROM vendas')->fetchColumn();
            $copia = null;

            $fotos = array_filter(glob(BASE_PATH . '/public/uploads/produtos/*') ?: [], 'is_file');
            foreach ($fotos as $foto) {
                if (!copy($foto, $tmp . '/fotos/' . basename($foto))) throw new RuntimeException('Não foi possível copiar a foto ' . basename($foto) . '.');
            }

            if (!rename($tmp, $destino)) throw new RuntimeException('Não foi possível renomear a pasta do backup.');
            $criada = false; // daqui em diante o backup está pronto: nada no catch pode apagá-lo
        } catch (Throwable $e) {
            $copia = null;
            if ($criada) self::apagarPasta(realpath($tmp) ?: $tmp); // apagarPasta compara com realpath
            self::registrar(false, $e->getMessage(), basename($destino ?? ''));
            throw $e;
        }

        [$apagados, $falhas] = self::apagarAntigos();
        $msg = 'Backup ok: ' . $vendas . ' vendas, ' . count($fotos) . ' fotos' . ($apagados ? ", $apagados backup(s) antigo(s) apagado(s)" : '');
        self::registrar(true, $msg, basename($destino));
        if ($falhas) {
            // O backup é bom (linha OK acima, vale para restaurar), mas o Dashboard precisa avisar: senão o disco enche em silêncio
            $msg = 'Backup feito, mas não foi possível apagar os backups antigos: ' . implode(', ', $falhas) . '.';
            self::registrar(false, $msg, basename($destino));
        }
        return $msg;
    }

    /**
     * Último resultado: ['quando' => 'Y-m-d H:i:s', 'ok' => bool, 'mensagem' => ..., 'pasta' => ...] ou null se nunca rodou.
     * Registro ilegível ou com data inválida/no futuro volta como ['ok' => false, 'quando' => null] (o Dashboard avisa).
     */
    public static function ultimo(): ?array {
        $json = @file_get_contents(self::pasta() . '/ultimo.json');
        if ($json === false) return null;
        $b = json_decode($json, true);
        // formato exato (strtotime aceitaria "now" e corrigiria 30/02 sem avisar)
        $d = is_array($b) && is_string($b['quando'] ?? null) ? DateTime::createFromFormat('Y-m-d H:i:s', $b['quando']) : false;
        $quando = $d && $d->format('Y-m-d H:i:s') === $b['quando'] ? $d->getTimestamp() : false;
        if (!$quando || $quando > time() + 300 || !is_bool($b['ok'] ?? null)) return ['ok' => false, 'quando' => null];
        return $b;
    }

    /** Retorna [quantos apagou, nomes que não conseguiu apagar] */
    private static function apagarAntigos(): array {
        $base = realpath(self::pasta());
        $pastas = [];
        $falhas = [];
        foreach (scandir($base) ?: [] as $nome) {
            $dir = $base . DIRECTORY_SEPARATOR . $nome;
            $semTmp = preg_replace('/\.tmp$/', '', $nome);
            // só pastas de verdade com nome de data válida; junção/atalho pode apontar para fora (apagaria o destino)
            if (!preg_match(self::NOME, $semTmp) || date('Y-m-d', strtotime(substr($semTmp, 0, 10))) !== substr($semTmp, 0, 10)) continue;
            if (!is_dir($dir) || is_link($dir) || realpath($dir) !== $dir) continue;
            if ($semTmp === $nome) {
                $pastas[] = $nome;
            } elseif (filemtime($dir) < time() - 86400 && !self::apagarPasta($dir)) {
                // .tmp parado há mais de 1 dia = backup interrompido (processo morto). O em andamento é recente.
                $falhas[] = $nome;
            }
        }
        rsort($pastas);
        $limite = date('Y-m-d', strtotime('-' . self::RETENCAO_DIAS . ' days'));
        $apagados = 0;
        foreach (array_slice($pastas, self::MINIMO_COPIAS) as $nome) {
            if (substr($nome, 0, 10) >= $limite) continue;
            if (self::apagarPasta($base . DIRECTORY_SEPARATOR . $nome)) $apagados++;
            else $falhas[] = $nome;
        }
        return [$apagados, $falhas];
    }

    /** Apaga a pasta e o conteúdo. false se algo ficou para trás (sem aviso do PHP: quem chama decide o que fazer). */
    private static function apagarPasta(string $dir): bool {
        foreach (@scandir($dir) ?: [] as $nome) { // sem permissão de leitura: o rmdir abaixo falha e volta false
            if ($nome === '.' || $nome === '..') continue;
            $p = $dir . DIRECTORY_SEPARATOR . $nome;
            // Só entra em pasta de verdade. Link/junção (realpath aponta para outro lugar; o PHP no Windows nem
            // reconhece a junção como link) perde só o link: unlink no Linux, rmdir no Windows. O alvo fica intacto.
            if (is_dir($p) && realpath($p) === $p) {
                if (!self::apagarPasta($p)) return false;
            } elseif (!@unlink($p) && !@rmdir($p)) {
                return false;
            }
        }
        return @rmdir($dir);
    }

    private static function registrar(bool $ok, string $mensagem, string $pasta): void {
        if (!is_dir(self::pasta())) mkdir(self::pasta(), 0775, true);
        $quando = date('Y-m-d H:i:s');
        // grava em .tmp e renomeia: o Dashboard nunca lê um ultimo.json pela metade
        file_put_contents(self::pasta() . '/ultimo.json.tmp', json_encode(compact('quando', 'ok', 'mensagem', 'pasta'), JSON_UNESCAPED_UNICODE));
        rename(self::pasta() . '/ultimo.json.tmp', self::pasta() . '/ultimo.json');
        file_put_contents(self::pasta() . '/backup.log', "$quando " . ($ok ? 'OK' : 'FALHA') . " $pasta: $mensagem\n", FILE_APPEND);
    }
}
