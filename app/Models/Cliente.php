<?php
/** Clientes da loja e os pets de cada um. Excluir só desativa: as vendas antigas continuam mostrando o cliente. */
class Cliente {
    const ESPECIES = ['cao' => 'Cão', 'gato' => 'Gato', 'outro' => 'Outro'];
    const PORTES = ['P' => 'Pequeno', 'M' => 'Médio', 'G' => 'Grande'];
    const ICONES_ESPECIE = ['cao' => '🐶', 'gato' => '🐱', 'outro' => '🐾'];
    const TIPOS_DOSE = ['vacina' => 'Vacina', 'vermifugo' => 'Vermífugo', 'antipulgas' => 'Antipulgas'];
    const ICONES_DOSE = ['vacina' => 'shield-plus', 'vermifugo' => 'capsule', 'antipulgas' => 'bug'];
    // Dose vencida há mais tempo que isso sai do aviso: o cliente provavelmente vacinou em outro lugar
    const DOSE_VENCIDA_AVISO_DIAS = 60;

    /**
     * Uma dose é trocada por outra mais nova do mesmo pet e tipo (vacina: também do mesmo nome, V10 não troca a antirrábica).
     * Só a mais nova conta para o aviso de próxima dose.
     */
    private const SQL_DOSE_ATUAL = "NOT EXISTS (SELECT 1 FROM pet_doses n WHERE n.pet_id = d.pet_id AND n.tipo = d.tipo
        AND (d.tipo <> 'vacina' OR n.nome = d.nome COLLATE NOCASE) AND (n.aplicada_em > d.aplicada_em OR (n.aplicada_em = d.aplicada_em AND n.id > d.id)))";

    /** Ativos, por nome ou telefone (só os números contam: "(91) 9 8888" acha "91988887777") */
    public static function listar(string $busca = ''): array {
        $sql = 'SELECT c.*, (SELECT COUNT(*) FROM pets p WHERE p.cliente_id = c.id AND p.ativo = 1) AS qtd_pets,
                (SELECT GROUP_CONCAT(p.nome, \', \') FROM pets p WHERE p.cliente_id = c.id AND p.ativo = 1) AS nomes_pets,
                (SELECT MAX(v.criado_em) FROM vendas v WHERE v.cliente_id = c.id AND v.status = \'concluida\') AS ultima_compra
                FROM clientes c WHERE c.ativo = 1 ORDER BY c.nome COLLATE NOCASE';
        $todos = Database::pdo()->query($sql)->fetchAll();
        $busca = self::semAcento($busca);
        if ($busca === '') return $todos;
        // filtra no PHP: o LIKE do SQLite não ignora acento ("patricia" não achava "Patrícia").
        // shortcut: carrega todos os clientes ativos; com dezenas de milhares, trocar por coluna normalizada no banco.
        $digitos = preg_replace('/\D/', '', $busca);
        return array_values(array_filter($todos, fn($c) =>
            str_contains(self::semAcento($c['nome'] . ' ' . ($c['nomes_pets'] ?? '')), $busca)
            || ($digitos !== '' && str_contains($c['telefone'], $digitos))));
    }

    /** Minúsculas sem acento, para comparar ("PATRÍCIA" → "patricia") */
    public static function semAcento(string $s): string {
        return strtr(mb_strtolower(trim($s)), ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n']);
    }

    /** Cliente ativo com os pets ativos */
    public static function buscar(int $id): ?array {
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT * FROM clientes WHERE id = ? AND ativo = 1');
        $st->execute([$id]);
        if (!$c = $st->fetch()) return null;
        $st = $pdo->prepare('SELECT * FROM pets WHERE cliente_id = ? AND ativo = 1 ORDER BY nome COLLATE NOCASE');
        $st->execute([$id]);
        $c['pets'] = $st->fetchAll();
        return $c;
    }

    /** Todos os pets ativos com o dono, para escolher na agenda */
    public static function petsAtivos(): array {
        return Database::pdo()->query('SELECT p.id, p.nome, p.especie, p.cliente_id, c.nome AS cliente FROM pets p JOIN clientes c ON c.id = p.cliente_id
            WHERE p.ativo = 1 AND c.ativo = 1 ORDER BY c.nome COLLATE NOCASE, p.nome COLLATE NOCASE')->fetchAll();
    }

    /** Resumo das compras: quantidade, total líquido (sem canceladas e devolvidas) e última compra; com $usuarioId, só as vendas dele */
    public static function resumo(int $id, ?int $usuarioId = null): array {
        $st = Database::pdo()->prepare("SELECT COUNT(*) AS compras, COALESCE(SUM(total - valor_devolvido), 0) AS gasto, MAX(criado_em) AS ultima
            FROM vendas WHERE cliente_id = ? AND status = 'concluida' AND NOT (total > 0 AND valor_devolvido >= total)" . ($usuarioId ? ' AND usuario_id = ?' : ''));
        $st->execute($usuarioId ? [$id, $usuarioId] : [$id]);
        return $st->fetch();
    }

    /** Link do WhatsApp para o telefone (só números, com DDD) com a mensagem já escrita */
    public static function whatsapp(string $telefone, string $texto = ''): string {
        return 'https://wa.me/55' . $telefone . ($texto !== '' ? '?text=' . rawurlencode($texto) : '');
    }

    /** Primeiro nome, para as mensagens ("Maria Souza" → "Maria") */
    public static function primeiroNome(string $nome): string {
        return explode(' ', trim($nome))[0];
    }

    /** Carteirinha dos pets do cliente, da dose mais nova; 'atual' = 0 quando já veio outra dose depois */
    public static function doses(int $clienteId): array {
        $st = Database::pdo()->prepare('SELECT d.*, p.nome AS pet, (' . self::SQL_DOSE_ATUAL . ') AS atual FROM pet_doses d JOIN pets p ON p.id = d.pet_id
            WHERE p.cliente_id = ? AND p.ativo = 1 ORDER BY d.aplicada_em DESC, d.id DESC');
        $st->execute([$clienteId]);
        return $st->fetchAll();
    }

    /** Próximas doses vencendo em até $dias (e as vencidas há pouco), só a mais nova de cada; 'dias' negativo = vencida */
    public static function dosesAVencer(int $dias): array {
        $st = Database::pdo()->prepare("SELECT d.*, p.nome AS pet, p.especie, c.id AS cliente_id, c.nome AS cliente, c.telefone,
                CAST(julianday(d.proxima_em) - julianday(date('now', 'localtime')) AS INTEGER) AS dias
            FROM pet_doses d JOIN pets p ON p.id = d.pet_id JOIN clientes c ON c.id = p.cliente_id
            WHERE p.ativo = 1 AND c.ativo = 1 AND d.proxima_em IS NOT NULL
              AND d.proxima_em BETWEEN date('now', 'localtime', ?) AND date('now', 'localtime', ?)
              AND " . self::SQL_DOSE_ATUAL . ' ORDER BY d.proxima_em, p.nome COLLATE NOCASE');
        $st->execute(['-' . self::DOSE_VENCIDA_AVISO_DIAS . ' days', "+$dias days"]);
        return $st->fetchAll();
    }

    /** Mensagem pronta para lembrar o dono da próxima dose */
    public static function mensagemDose(array $d): string {
        $loja = config('loja')['nome'] ?? config('app_nome');
        $data = date('d/m', strtotime($d['proxima_em']));
        $quando = $d['dias'] < 0 ? "venceu em $data" : ($d['dias'] == 0 ? 'vence hoje' : "vence em $data");
        return 'Olá, ' . self::primeiroNome($d['cliente']) . "! Aqui é da $loja. A próxima dose de " . mb_strtolower(self::TIPOS_DOSE[$d['tipo']]) . " ({$d['nome']}) de {$d['pet']} $quando. Quer agendar a aplicação?";
    }

    /** Registra uma dose na carteirinha de um pet do cliente */
    public static function salvarDose(array $d): void {
        $clienteId = (int) ($d['cliente_id'] ?? 0);
        $petId = (int) ($d['pet_id'] ?? 0);
        $tipo = (string) ($d['tipo'] ?? '');
        $nome = trim((string) ($d['nome'] ?? ''));
        $aplicada = trim((string) ($d['aplicada_em'] ?? ''));
        $proxima = trim((string) ($d['proxima_em'] ?? ''));
        $obs = trim((string) ($d['observacao'] ?? ''));
        $data = fn(string $s) => ($dt = DateTime::createFromFormat('!Y-m-d', $s)) && $dt->format('Y-m-d') === $s;
        if (!isset(self::TIPOS_DOSE[$tipo])) throw new DomainException('Escolha vacina, vermífugo ou antipulgas.');
        if ($nome === '') throw new DomainException('Informe o nome do produto, ex.: V10, antirrábica, Bravecto.');
        if (mb_strlen($nome) > 80) throw new DomainException('O nome deve ter no máximo 80 caracteres.');
        if (!$data($aplicada) || $aplicada > date('Y-m-d') || $aplicada < '1990-01-01') throw new DomainException('Data da aplicação inválida: não pode ser no futuro.');
        if ($proxima !== '' && (!$data($proxima) || $proxima <= $aplicada || $proxima > date('Y-m-d', strtotime("$aplicada +3 years")))) {
            throw new DomainException('Próxima dose inválida: deve ser depois da aplicação e em até 3 anos.');
        }
        if (mb_strlen($obs) > 200) throw new DomainException('A observação deve ter no máximo 200 caracteres.');
        $pdo = Database::pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            // o pet tem de ser deste cliente: os dois ids vêm do formulário
            $st = $pdo->prepare('SELECT p.nome, c.nome AS dono FROM pets p JOIN clientes c ON c.id = p.cliente_id WHERE p.id = ? AND p.cliente_id = ? AND p.ativo = 1 AND c.ativo = 1');
            $st->execute([$petId, $clienteId]);
            $p = $st->fetch();
            $st->closeCursor();
            if (!$p) throw new DomainException('Escolha um dos pets deste cliente.');
            $pdo->prepare('INSERT INTO pet_doses (pet_id, tipo, nome, aplicada_em, proxima_em, observacao, usuario_id) VALUES (?,?,?,?,?,?,?)')
                ->execute([$petId, $tipo, $nome, $aplicada, $proxima ?: null, $obs ?: null, usuario()['id'] ?? null]);
            Auditoria::registrar('dose_registrada', self::TIPOS_DOSE[$tipo] . " $nome em {$p['nome']}, de {$p['dono']}");
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    public static function excluirDose(int $id, int $clienteId): void {
        $pdo = Database::pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $st = $pdo->prepare('SELECT d.tipo, d.nome, d.aplicada_em, p.nome AS pet FROM pet_doses d JOIN pets p ON p.id = d.pet_id WHERE d.id = ? AND p.cliente_id = ?');
            $st->execute([$id, $clienteId]);
            $d = $st->fetch();
            $st->closeCursor();
            if (!$d) throw new DomainException('Registro não encontrado: ele pode já ter sido excluído.');
            $pdo->prepare('DELETE FROM pet_doses WHERE id = ?')->execute([$id]);
            Auditoria::registrar('dose_excluida', self::TIPOS_DOSE[$d['tipo']] . " {$d['nome']} de " . date('d/m/Y', strtotime($d['aplicada_em'])) . " em {$d['pet']}");
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    /** Telefone só com números: 10 (fixo) ou 11 (celular) dígitos, com DDD */
    public static function telefone(string $t): string {
        $d = preg_replace('/\D/', '', $t);
        if (str_starts_with($d, '55') && strlen($d) > 11) $d = substr($d, 2); // +55 do Brasil
        if (!preg_match('/^[1-9]{2}\d{8,9}$/', $d)) throw new DomainException('Informe o telefone com DDD, ex.: (91) 98888-7777.');
        return $d;
    }

    /** (91) 98888-7777 / (91) 3222-1111 */
    public static function formatarTelefone(?string $d): string {
        if (!$d || !preg_match('/^(\d{2})(\d{4,5})(\d{4})$/', $d, $m)) return (string) $d;
        return "($m[1]) $m[2]-$m[3]";
    }

    /** Cria (sem id) ou edita. @return int id do cliente */
    public static function salvar(array $d): int {
        $id = (int) ($d['id'] ?? 0);
        $nome = trim((string) ($d['nome'] ?? ''));
        $email = trim((string) ($d['email'] ?? ''));
        $endereco = trim((string) ($d['endereco'] ?? ''));
        $obs = trim((string) ($d['observacao'] ?? ''));
        if ($nome === '') throw new DomainException('Informe o nome do cliente.');
        if (mb_strlen($nome) > 100) throw new DomainException('O nome deve ter no máximo 100 caracteres.');
        $telefone = self::telefone((string) ($d['telefone'] ?? ''));
        if ($email !== '' && (mb_strlen($email) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL))) throw new DomainException('E-mail inválido.');
        if (mb_strlen($endereco) > 200) throw new DomainException('O endereço deve ter no máximo 200 caracteres.');
        if (mb_strlen($obs) > 200) throw new DomainException('A observação deve ter no máximo 200 caracteres.');
        $pdo = Database::pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            // telefone repetido costuma ser cadastro em dobro; pessoas da mesma casa podem dividir o número (confirmação na tela)
            if (empty($d['mesmo_telefone'])) {
                $st = $pdo->prepare('SELECT id, nome FROM clientes WHERE telefone = ? AND ativo = 1 AND id <> ? LIMIT 1');
                $st->execute([$telefone, $id]);
                $outro = $st->fetch();
                $st->closeCursor();
                if ($outro) throw new DomainException("Já existe cliente com este telefone: {$outro['nome']} (#{$outro['id']}). Se for outra pessoa com o mesmo número, marque \"Mesmo telefone de outro cliente\" e salve de novo.");
            }
            if ($id) {
                $st = $pdo->prepare('UPDATE clientes SET nome = ?, telefone = ?, email = ?, endereco = ?, observacao = ? WHERE id = ? AND ativo = 1');
                $st->execute([$nome, $telefone, $email ?: null, $endereco ?: null, $obs ?: null, $id]);
                if ($st->rowCount() !== 1) throw new DomainException('Cliente não encontrado: ele pode ter sido excluído.');
                Auditoria::registrar('cliente_alterado', "$nome (#$id)");
            } else {
                $pdo->prepare('INSERT INTO clientes (nome, telefone, email, endereco, observacao) VALUES (?,?,?,?,?)')
                    ->execute([$nome, $telefone, $email ?: null, $endereco ?: null, $obs ?: null]);
                $id = (int) $pdo->lastInsertId();
                Auditoria::registrar('cliente_criado', "$nome (#$id)");
            }
            $pdo->commit();
            return $id;
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    public static function excluir(int $id): void {
        $pdo = Database::pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $st = $pdo->prepare('SELECT nome FROM clientes WHERE id = ? AND ativo = 1');
            $st->execute([$id]);
            $nome = $st->fetchColumn();
            $st->closeCursor();
            if ($nome === false) throw new DomainException('Cliente não encontrado: ele pode já ter sido excluído.');
            $pdo->prepare('UPDATE clientes SET ativo = 0 WHERE id = ?')->execute([$id]);
            Auditoria::registrar('cliente_excluido', "$nome (#$id)");
            Agenda::cancelarAbertos('cliente_id', $id);
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    /** Cria (sem id) ou edita um pet do cliente */
    public static function salvarPet(array $d): void {
        $id = (int) ($d['id'] ?? 0);
        $clienteId = (int) ($d['cliente_id'] ?? 0);
        $nome = trim((string) ($d['nome'] ?? ''));
        $especie = (string) ($d['especie'] ?? '');
        $raca = trim((string) ($d['raca'] ?? ''));
        $porte = (string) ($d['porte'] ?? '');
        $nasc = trim((string) ($d['nascimento'] ?? ''));
        $obs = trim((string) ($d['observacao'] ?? ''));
        if ($nome === '') throw new DomainException('Informe o nome do pet.');
        if (mb_strlen($nome) > 60) throw new DomainException('O nome do pet deve ter no máximo 60 caracteres.');
        if (!isset(self::ESPECIES[$especie])) throw new DomainException('Escolha a espécie do pet.');
        if (mb_strlen($raca) > 60) throw new DomainException('A raça deve ter no máximo 60 caracteres.');
        if ($porte !== '' && !isset(self::PORTES[$porte])) throw new DomainException('Porte inválido.');
        if ($nasc !== '') {
            $dt = DateTime::createFromFormat('!Y-m-d', $nasc);
            if (!$dt || $dt->format('Y-m-d') !== $nasc || $nasc > date('Y-m-d') || $nasc < '1990-01-01') throw new DomainException('Data de nascimento inválida.');
        }
        if (mb_strlen($obs) > 200) throw new DomainException('A observação deve ter no máximo 200 caracteres.');
        $pdo = Database::pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $st = $pdo->prepare('SELECT nome FROM clientes WHERE id = ? AND ativo = 1');
            $st->execute([$clienteId]);
            $dono = $st->fetchColumn();
            $st->closeCursor();
            if ($dono === false) throw new DomainException('Cliente não encontrado: ele pode ter sido excluído.');
            $campos = [$nome, $especie, $raca ?: null, $porte ?: null, $nasc ?: null, $obs ?: null];
            if ($id) {
                // o pet tem de ser deste cliente: o id vem do formulário
                $st = $pdo->prepare('UPDATE pets SET nome = ?, especie = ?, raca = ?, porte = ?, nascimento = ?, observacao = ? WHERE id = ? AND cliente_id = ? AND ativo = 1');
                $st->execute([...$campos, $id, $clienteId]);
                if ($st->rowCount() !== 1) throw new DomainException('Pet não encontrado: ele pode ter sido excluído.');
                Auditoria::registrar('pet_alterado', "$nome (#$id), de $dono");
            } else {
                $pdo->prepare('INSERT INTO pets (nome, especie, raca, porte, nascimento, observacao, cliente_id) VALUES (?,?,?,?,?,?,?)')->execute([...$campos, $clienteId]);
                Auditoria::registrar('pet_criado', "$nome (#" . $pdo->lastInsertId() . "), de $dono");
            }
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    public static function excluirPet(int $id, int $clienteId): void {
        $pdo = Database::pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $st = $pdo->prepare('SELECT p.nome, c.nome AS dono FROM pets p JOIN clientes c ON c.id = p.cliente_id WHERE p.id = ? AND p.cliente_id = ? AND p.ativo = 1');
            $st->execute([$id, $clienteId]);
            $p = $st->fetch();
            $st->closeCursor();
            if (!$p) throw new DomainException('Pet não encontrado: ele pode já ter sido excluído.');
            $pdo->prepare('UPDATE pets SET ativo = 0 WHERE id = ?')->execute([$id]);
            Auditoria::registrar('pet_excluido', "{$p['nome']} (#$id), de {$p['dono']}");
            Agenda::cancelarAbertos('pet_id', $id);
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    /** Idade do pet em texto: "3 anos", "5 meses" */
    public static function idade(?string $nasc): string {
        if (!$nasc) return '';
        $d = (new DateTime($nasc))->diff(new DateTime('today'));
        if ($d->y > 0) return $d->y . ($d->y === 1 ? ' ano' : ' anos');
        return $d->m . ($d->m === 1 ? ' mês' : ' meses');
    }
}
