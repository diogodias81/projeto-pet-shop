<?php
/** Banho e tosa: serviços e agendamentos. O pagamento acontece no caixa (Venda::registrar com 'agendamentos'). */
class Agenda {
    const STATUS = ['agendado' => 'Agendado', 'em_atendimento' => 'Em atendimento', 'pronto' => 'Pronto', 'pago' => 'Pago', 'faltou' => 'Faltou', 'cancelado' => 'Cancelado'];
    /**
     * Para onde cada situação pode ir pela agenda ('pago' só pelo caixa).
     * Pagamento e andamento são separados: cobrado antes do atendimento, o serviço segue agendado/em atendimento com venda_id,
     * e vira 'pago' quando fica pronto. 'pago' = pronto e pago.
     */
    const PASSOS = [
        'agendado' => ['em_atendimento', 'faltou', 'cancelado'],
        'em_atendimento' => ['pronto', 'agendado'],
        'pronto' => ['em_atendimento'],
        'faltou' => ['agendado'],
        'cancelado' => ['agendado'],
        'pago' => [],
    ];
    /** Situações que ocupam o horário (faltou/cancelado liberam) */
    const OCUPA = ['agendado', 'em_atendimento', 'pronto', 'pago'];
    /** Situações que podem ser cobradas no caixa (se ainda não tiverem venda_id) */
    const COBRAVEL = ['agendado', 'em_atendimento', 'pronto'];

    /** Ainda falta cobrar? (pago adiantado tem venda_id) */
    public static function aCobrar(array $a): bool {
        return in_array($a['status'], self::COBRAVEL, true) && empty($a['venda_id']);
    }

    // ---------- serviços ----------

    public static function servicos(bool $soAtivos = true): array {
        return Database::pdo()->query('SELECT * FROM servicos' . ($soAtivos ? ' WHERE ativo = 1' : '') . ' ORDER BY ativo DESC, nome COLLATE NOCASE')->fetchAll();
    }

    public static function salvarServico(array $d): void {
        $id = (int) ($d['id'] ?? 0);
        $nome = trim((string) ($d['nome'] ?? ''));
        $preco = numero($d['preco'] ?? '', 'Informe um preço válido, ex.: 45,00');
        $duracao = (int) ($d['duracao_min'] ?? 0);
        if ($nome === '' || mb_strlen($nome) > 80) throw new DomainException('Informe o nome do serviço (até 80 caracteres).');
        if ($preco <= 0 || $preco > 100000) throw new DomainException('Informe um preço válido, ex.: 45,00');
        if ($duracao < 15 || $duracao > 480 || $duracao % 15) throw new DomainException('A duração deve ser de 15 a 480 minutos, em passos de 15.');
        $pdo = Database::pdo();
        if ($id) {
            $st = $pdo->prepare('UPDATE servicos SET nome = ?, preco = ?, duracao_min = ? WHERE id = ?');
            $st->execute([$nome, round($preco, 2), $duracao, $id]);
            if ($st->rowCount() !== 1) throw new DomainException('Serviço não encontrado.');
            Auditoria::registrar('servico_alterado', "$nome (#$id): " . moeda($preco) . ", $duracao min");
        } else {
            $pdo->prepare('INSERT INTO servicos (nome, preco, duracao_min) VALUES (?,?,?)')->execute([$nome, round($preco, 2), $duracao]);
            Auditoria::registrar('servico_criado', "$nome: " . moeda($preco) . ", $duracao min");
        }
    }

    /** Desativa/reativa: agendamentos antigos continuam mostrando o serviço */
    public static function ativarServico(int $id, bool $ativo): void {
        $st = Database::pdo()->prepare('UPDATE servicos SET ativo = ? WHERE id = ?');
        $st->execute([(int) $ativo, $id]);
        if ($st->rowCount() !== 1) throw new DomainException('Serviço não encontrado.');
        Auditoria::registrar($ativo ? 'servico_reativado' : 'servico_desativado', "Serviço #$id");
    }

    // ---------- agendamentos ----------

    private const SELECT = "SELECT a.*, s.nome AS servico, p.nome AS pet, p.especie, p.raca, p.porte, p.observacao AS pet_obs,
        c.nome AS cliente, c.telefone, u.nome AS usuario, (p.ativo = 1 AND c.ativo = 1) AS dono_ativo
        FROM agendamentos a JOIN servicos s ON s.id = a.servico_id JOIN pets p ON p.id = a.pet_id JOIN clientes c ON c.id = a.cliente_id
        LEFT JOIN usuarios u ON u.id = a.usuario_id";

    /** Agendamentos de um dia (AAAA-MM-DD), por horário */
    public static function dia(string $data): array {
        $st = Database::pdo()->prepare(self::SELECT . " WHERE a.inicio >= ? AND a.inicio < date(?, '+1 day') ORDER BY a.inicio, a.id");
        $st->execute([$data, $data]);
        return $st->fetchAll();
    }

    public static function buscar(int $id): ?array {
        $st = Database::pdo()->prepare(self::SELECT . ' WHERE a.id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Do cliente: os próximos e os últimos */
    public static function doCliente(int $clienteId, int $limite = 20): array {
        $st = Database::pdo()->prepare(self::SELECT . ' WHERE a.cliente_id = ? ORDER BY a.inicio DESC LIMIT ' . (int) $limite);
        $st->execute([$clienteId]);
        return $st->fetchAll();
    }

    /** Quantos agendamentos de cada dia entre $de e $ate (para a navegação da agenda) */
    public static function contarPorDia(string $de, string $ate): array {
        $st = Database::pdo()->prepare("SELECT substr(inicio, 1, 10) AS dia, COUNT(*) FROM agendamentos WHERE inicio >= ? AND inicio < date(?, '+1 day') AND status IN ('agendado','em_atendimento','pronto','pago') GROUP BY dia");
        $st->execute([$de, $ate]);
        return $st->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /**
     * Marca um horário. Recusa horário passado, fora do expediente e acima do limite de atendimentos simultâneos.
     * @param array $d pet_id, servico_id, data (AAAA-MM-DD), hora (HH:MM), observacao
     * @return int id do agendamento
     */
    public static function agendar(array $d): int {
        $petId = (int) ($d['pet_id'] ?? 0);
        $servicoId = (int) ($d['servico_id'] ?? 0);
        $data = trim((string) ($d['data'] ?? ''));
        $hora = trim((string) ($d['hora'] ?? ''));
        $obs = trim((string) ($d['observacao'] ?? ''));
        $dt = DateTime::createFromFormat('!Y-m-d H:i', "$data $hora");
        if (!$dt || $dt->format('Y-m-d H:i') !== "$data $hora") throw new DomainException('Informe a data e a hora do agendamento.');
        if ((int) $dt->format('i') % 15) throw new DomainException('Escolha um horário de 15 em 15 minutos (ex.: 9:00, 9:15, 9:30).');
        if ($dt->format('Y-m-d H:i') < date('Y-m-d H:i')) throw new DomainException('Esse horário já passou.');
        if ($data > date('Y-m-d', strtotime('+1 year'))) throw new DomainException('Agende no máximo um ano à frente.');
        if (mb_strlen($obs) > 200) throw new DomainException('A observação deve ter no máximo 200 caracteres.');
        $pdo = Database::pdo();
        $pdo->exec('BEGIN IMMEDIATE'); // conferir o horário e gravar juntos: dois agendamentos não pegam a mesma vaga
        try {
            $st = $pdo->prepare('SELECT p.id, p.nome, p.cliente_id, c.nome AS cliente FROM pets p JOIN clientes c ON c.id = p.cliente_id WHERE p.id = ? AND p.ativo = 1 AND c.ativo = 1');
            $st->execute([$petId]);
            $pet = $st->fetch();
            $st->closeCursor();
            if (!$pet) throw new DomainException('Escolha o pet (ele pode ter sido excluído).');
            $st = $pdo->prepare('SELECT * FROM servicos WHERE id = ? AND ativo = 1');
            $st->execute([$servicoId]);
            $serv = $st->fetch();
            $st->closeCursor();
            if (!$serv) throw new DomainException('Escolha o serviço.');
            $inicio = $dt->format('Y-m-d H:i');
            $fim = (clone $dt)->modify('+' . (int) $serv['duracao_min'] . ' minutes')->format('Y-m-d H:i');
            if ($dt->format('H:i') < config('agenda_abre') || substr($fim, 11) > config('agenda_fecha') || substr($fim, 0, 10) !== $data) {
                throw new DomainException('Fora do horário da agenda (' . config('agenda_abre') . ' às ' . config('agenda_fecha') . '): o serviço tem ' . $serv['duracao_min'] . ' min.');
            }
            self::conferirVaga($inicio, $fim);
            $st = $pdo->prepare("SELECT COUNT(*) FROM agendamentos WHERE pet_id = ? AND status IN ('agendado','em_atendimento','pronto','pago') AND inicio < ? AND fim > ?");
            $st->execute([$petId, $fim, $inicio]);
            if ($st->fetchColumn()) throw new DomainException("{$pet['nome']} já tem um agendamento nesse horário.");
            $pdo->prepare('INSERT INTO agendamentos (cliente_id, pet_id, servico_id, inicio, fim, preco, observacao, usuario_id) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$pet['cliente_id'], $petId, $servicoId, $inicio, $fim, $serv['preco'], $obs ?: null, usuario()['id'] ?? null]);
            $id = (int) $pdo->lastInsertId();
            Auditoria::registrar('agendamento_criado', "{$serv['nome']} para {$pet['nome']} ({$pet['cliente']}) em " . date('d/m/Y H:i', strtotime($inicio)));
            $pdo->commit();
            return $id;
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    /** Recusa se o horário [inicio, fim) já tem o limite de atendimentos ao mesmo tempo. Ignora $exceto (o próprio, ao reabrir). */
    private static function conferirVaga(string $inicio, string $fim, int $exceto = 0): void {
        $limite = max(1, (int) config('banho_tosa_simultaneos'));
        // o pico de ocupação acontece no início de algum agendamento que cruza o intervalo (ou no próprio início)
        $st = Database::pdo()->prepare("SELECT inicio, fim FROM agendamentos WHERE status IN ('agendado','em_atendimento','pronto','pago') AND inicio < ? AND fim > ? AND id <> ?");
        $st->execute([$fim, $inicio, $exceto]);
        $outros = $st->fetchAll();
        foreach (array_merge([$inicio], array_column($outros, 'inicio')) as $t) {
            if ($t < $inicio || $t >= $fim) continue;
            $ao = count(array_filter($outros, fn($o) => $o['inicio'] <= $t && $o['fim'] > $t));
            if ($ao >= $limite) throw new DomainException('Horário ocupado: já há ' . ($limite === 1 ? 'um atendimento' : "$limite atendimentos") . ' às ' . substr($t, 11) . '. Escolha outro horário.');
        }
    }

    /** Muda a situação seguindo PASSOS. Voltar para "agendado" confere a vaga de novo. */
    public static function mudarStatus(int $id, string $novo): void {
        if (!isset(self::STATUS[$novo]) || $novo === 'pago') throw new DomainException('Situação inválida.');
        $pdo = Database::pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $a = self::buscar($id);
            if (!$a) throw new DomainException('Agendamento não encontrado.');
            if (!$a['dono_ativo']) throw new DomainException("O pet {$a['pet']} ou o cliente {$a['cliente']} foi excluído: este agendamento não pode mais mudar.");
            if (!in_array($novo, self::PASSOS[$a['status']], true)) {
                throw new DomainException($a['status'] === 'pago' ? 'Este agendamento já foi pago: para desfazer, cancele a venda.' : 'Não dá para passar de "' . self::STATUS[$a['status']] . '" para "' . self::STATUS[$novo] . '".');
            }
            if ($a['venda_id'] && in_array($novo, ['faltou', 'cancelado'], true)) {
                throw new DomainException("O {$a['servico']} de {$a['pet']} já foi pago na venda #{$a['venda_id']}: para desfazer, cancele a venda no Histórico.");
            }
            if ($novo === 'faltou' && $a['inicio'] > date('Y-m-d H:i')) throw new DomainException("Ainda não é a hora de {$a['pet']} (" . substr($a['inicio'], 11, 5) . '): marque a falta depois do horário, ou use Cancelar.');
            if (!in_array($a['status'], self::OCUPA, true) && in_array($novo, self::OCUPA, true)) self::conferirVaga($a['inicio'], $a['fim'], $id);
            // pago adiantado: ao ficar pronto, termina como "pago"
            $grava = $novo === 'pronto' && $a['venda_id'] ? 'pago' : $novo;
            $pdo->prepare('UPDATE agendamentos SET status = ? WHERE id = ?')->execute([$grava, $id]);
            Auditoria::registrar('agendamento_status', "{$a['servico']} de {$a['pet']} ({$a['cliente']}), " . date('d/m/Y H:i', strtotime($a['inicio'])) . ': ' . self::STATUS[$a['status']] . ' → ' . self::STATUS[$grava]);
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    /**
     * Para a venda: confere e marca como pagos (dentro da transação da venda).
     * @return array linhas de venda_servicos: [agendamento_id, descricao, preco, cliente_id]
     */
    public static function cobrar(array $ids, int $vendaId): array {
        $pdo = Database::pdo();
        $linhas = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $a = self::buscar($id);
            if (!$a) throw new DomainException('Agendamento não encontrado.');
            if (!$a['dono_ativo']) throw new DomainException("O {$a['servico']} de {$a['pet']} não pode ser cobrado: o pet ou o cliente {$a['cliente']} foi excluído.");
            if ($a['venda_id']) throw new DomainException("O {$a['servico']} de {$a['pet']} já foi pago na venda #{$a['venda_id']}.");
            if (!in_array($a['status'], self::COBRAVEL, true)) throw new DomainException("O {$a['servico']} de {$a['pet']} não pode ser cobrado (está " . mb_strtolower(self::STATUS[$a['status']]) . ').');
            // só o que já está pronto vira "pago"; cobrado antes, segue o atendimento normal
            $st = $pdo->prepare("UPDATE agendamentos SET status = CASE WHEN status = 'pronto' THEN 'pago' ELSE status END, venda_id = ?
                WHERE id = ? AND venda_id IS NULL AND status IN ('agendado','em_atendimento','pronto')");
            $st->execute([$vendaId, $id]);
            if ($st->rowCount() !== 1) throw new DomainException("O {$a['servico']} de {$a['pet']} já foi cobrado.");
            $desc = "{$a['servico']} — {$a['pet']}";
            $pdo->prepare('INSERT INTO venda_servicos (venda_id, agendamento_id, descricao, preco) VALUES (?,?,?,?)')->execute([$vendaId, $id, $desc, $a['preco']]);
            $linhas[] = ['agendamento_id' => $id, 'descricao' => $desc, 'preco' => (float) $a['preco'], 'cliente_id' => (int) $a['cliente_id'], 'cliente' => $a['cliente']];
        }
        return $linhas;
    }

    /** Pet ou cliente excluído: cancela os agendamentos ainda abertos (dentro da transação de quem exclui) */
    public static function cancelarAbertos(string $campo, int $id): void {
        $campo = $campo === 'cliente_id' ? 'cliente_id' : 'pet_id';
        $pdo = Database::pdo();
        // pago adiantado fica como está: o dinheiro só volta cancelando a venda (aí o estorno o cancela)
        $st = $pdo->prepare(self::SELECT . " WHERE a.$campo = ? AND a.status IN ('agendado','em_atendimento','pronto') AND a.venda_id IS NULL");
        $st->execute([$id]);
        foreach ($st->fetchAll() as $a) {
            $pdo->prepare("UPDATE agendamentos SET status = 'cancelado' WHERE id = ?")->execute([$a['id']]);
            Auditoria::registrar('agendamento_status', "{$a['servico']} de {$a['pet']} ({$a['cliente']}), " . date('d/m/Y H:i', strtotime($a['inicio'])) . ': ' . self::STATUS[$a['status']] . ' → Cancelado (' . ($campo === 'pet_id' ? 'pet' : 'cliente') . ' excluído)');
        }
    }

    /**
     * Venda cancelada: os serviços voltam a ficar sem pagamento, na situação em que estavam ("pago" = pronto volta a "pronto";
     * cobrado adiantado continua agendado/em atendimento). De pet ou cliente excluído vão para "cancelado" (não ficam presos).
     */
    public static function estornar(int $vendaId): void {
        Database::pdo()->prepare("UPDATE agendamentos SET venda_id = NULL,
            status = CASE WHEN NOT EXISTS (SELECT 1 FROM pets p JOIN clientes c ON c.id = p.cliente_id WHERE p.id = agendamentos.pet_id AND p.ativo = 1 AND c.ativo = 1) THEN 'cancelado'
                          WHEN status = 'pago' THEN 'pronto' ELSE status END
            WHERE venda_id = ?")->execute([$vendaId]);
    }

    /** Horários já tomados de hoje em diante [[inicio, fim], ...]: o formulário marca "(ocupado)" (quem decide continua sendo conferirVaga) */
    public static function ocupados(): array {
        // shortcut: até 3000 agendamentos futuros vão para a página; com mais que isso, buscar por dia via requisição
        return Database::pdo()->query("SELECT inicio, fim FROM agendamentos WHERE status IN ('agendado','em_atendimento','pronto','pago')
            AND fim > strftime('%Y-%m-%d %H:%M', 'now', 'localtime') ORDER BY inicio LIMIT 3000")->fetchAll(PDO::FETCH_NUM);
    }

    /** Horários de 15 em 15 minutos do expediente, para o formulário */
    public static function horarios(): array {
        $h = [];
        for ($t = strtotime('today ' . config('agenda_abre')); $t < strtotime('today ' . config('agenda_fecha')); $t += 900) $h[] = date('H:i', $t);
        return $h;
    }

    /** Mensagem pronta do WhatsApp: 'lembrete' (do horário marcado) ou 'pronto' (pode vir buscar) */
    public static function mensagem(array $a, string $tipo): string {
        $oi = 'Olá, ' . Cliente::primeiroNome($a['cliente']) . '! Aqui é da ' . (config('loja')['nome'] ?? config('app_nome')) . '. ';
        if ($tipo === 'pronto') return $oi . "Já pode vir buscar {$a['pet']}! Serviço: {$a['servico']}" . ($a['venda_id'] ? ' (já pago).' : '. Total: ' . moeda($a['preco']) . '.');
        $dia = substr($a['inicio'], 0, 10);
        $quando = $dia === date('Y-m-d') ? 'hoje' : ($dia === date('Y-m-d', strtotime('+1 day')) ? 'amanhã' : 'no dia ' . date('d/m', strtotime($dia)));
        return $oi . "Lembrando que {$a['pet']} tem {$a['servico']} $quando às " . substr($a['inicio'], 11, 5) . '. Podemos confirmar?';
    }
}
