<?php
/**
 * Caixa da loja: um aberto por vez. Venda só com o caixa aberto.
 * Dinheiro esperado na gaveta = troco inicial + vendas em dinheiro + reforços − sangrias
 *   − devoluções e cancelamentos de vendas em dinheiro feitos enquanto este caixa estava aberto.
 */
class Caixa {
    const TIPOS = ['sangria' => 'Sangria', 'reforco' => 'Reforço'];

    /** O caixa aberto agora, ou null */
    public static function aberto(): ?array {
        $st = Database::pdo()->query('SELECT c.*, u.nome AS aberto_por_nome FROM caixas c LEFT JOIN usuarios u ON u.id = c.aberto_por WHERE c.fechado_em IS NULL ORDER BY c.id DESC LIMIT 1');
        $c = $st->fetch() ?: null;
        $st->closeCursor();
        return $c;
    }

    public static function buscar(int $id): ?array {
        $st = Database::pdo()->prepare('SELECT c.*, a.nome AS aberto_por_nome, f.nome AS fechado_por_nome FROM caixas c LEFT JOIN usuarios a ON a.id = c.aberto_por LEFT JOIN usuarios f ON f.id = c.fechado_por WHERE c.id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Caixas fechados, do mais novo */
    public static function listar(int $limite = 60): array {
        return Database::pdo()->query('SELECT c.*, a.nome AS aberto_por_nome, f.nome AS fechado_por_nome FROM caixas c LEFT JOIN usuarios a ON a.id = c.aberto_por LEFT JOIN usuarios f ON f.id = c.fechado_por
            WHERE c.fechado_em IS NOT NULL ORDER BY c.id DESC LIMIT ' . (int) $limite)->fetchAll();
    }

    public static function abrir(string $troco): int {
        $valor = numero($troco, 'Troco inicial inválido: digite só números, ex.: 100,00.');
        if ($valor < 0 || $valor > 100000) throw new DomainException('Troco inicial inválido: digite só números, ex.: 100,00.');
        $pdo = Database::pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            if (self::aberto()) throw new DomainException('O caixa já está aberto.');
            $pdo->prepare('INSERT INTO caixas (aberto_por, troco_inicial) VALUES (?, ?)')->execute([usuario()['id'] ?? null, round($valor, 2)]);
            $id = (int) $pdo->lastInsertId();
            Auditoria::registrar('caixa_aberto', "Caixa #$id aberto com troco de " . moeda($valor));
            $pdo->commit();
            return $id;
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    /** Sangria (tira dinheiro da gaveta) ou reforço (coloca) */
    public static function movimentar(string $tipo, string $valor, string $motivo): void {
        if (!isset(self::TIPOS[$tipo])) throw new DomainException('Escolha sangria ou reforço.');
        $v = numero($valor, 'Valor inválido: digite só números, ex.: 50,00.');
        $motivo = trim($motivo);
        if ($v <= 0) throw new DomainException('Valor inválido: digite só números, ex.: 50,00.');
        if ($v > 100000) throw new DomainException('Valor acima do limite de R$ 100.000,00 por movimento.');
        if ($motivo === '' || mb_strlen($motivo) > 200) throw new DomainException('Informe o motivo (até 200 caracteres).');
        $pdo = Database::pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $c = self::aberto();
            if (!$c) throw new DomainException('O caixa está fechado.');
            // o vendedor conta às cegas: sem conferir com o esperado (aceitar/recusar revelaria o valor); a diferença aparece no fechamento.
            // O teto dele é a soma das próprias sangrias no dia, de qualquer caixa (fechar e reabrir não zera), e não depende do esperado.
            if ($tipo === 'sangria' && temPerfil(['vendedor'])) {
                $st = $pdo->prepare("SELECT COALESCE(SUM(valor), 0) FROM caixa_movimentos WHERE tipo = 'sangria' AND usuario_id = ? AND criado_em >= date('now', 'localtime') AND criado_em < date('now', 'localtime', '+1 day')");
                $st->execute([usuario()['id'] ?? 0]);
                $ja = (float) $st->fetchColumn();
                $st->closeCursor();
                $max = (float) config('sangria_max_vendedor');
                if (round($ja + $v, 2) > round($max, 2)) throw new DomainException('Sangria acima do limite do vendedor no dia (' . moeda($max) . ' somadas): peça a um administrador.');
            }
            if ($tipo === 'sangria' && !temPerfil(['vendedor'])) {
                $tem = self::resumo($c)['esperado'];
                if (round($v, 2) > round($tem, 2)) throw new DomainException('A sangria (' . moeda($v) . ') é maior que o dinheiro que deveria estar na gaveta (' . moeda($tem) . ').');
            }
            $pdo->prepare('INSERT INTO caixa_movimentos (caixa_id, tipo, valor, motivo, usuario_id) VALUES (?,?,?,?,?)')->execute([$c['id'], $tipo, round($v, 2), $motivo, usuario()['id'] ?? null]);
            Auditoria::registrar('caixa_' . $tipo, self::TIPOS[$tipo] . ' de ' . moeda($v) . " no caixa #{$c['id']}: $motivo");
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    /**
     * Números do caixa. Devoluções e cancelamentos contam no caixa que estava aberto quando foram feitos.
     * @return array troco, formas [forma => [qtd, total]], vendas_dinheiro, devolvido_dinheiro, sangrias, reforcos, esperado, movimentos
     */
    public static function resumo(array $c): array {
        $pdo = Database::pdo();
        $um = function (string $sql, array $par) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($par); $v = (float) $st->fetchColumn(); $st->closeCursor(); return $v; };

        // vendas registradas neste caixa, por forma (o dinheiro que entrou, mesmo se cancelada depois: o estorno conta abaixo)
        // 'estornado': canceladas e devolvidas depois (para conferir PIX/cartão com a maquininha e o banco)
        $st = $pdo->prepare("SELECT forma_pagamento, COUNT(*) AS qtd, SUM(total) AS total,
            SUM(CASE WHEN status = 'cancelada' THEN total ELSE valor_devolvido END) AS estornado
            FROM vendas WHERE caixa_id = ? GROUP BY forma_pagamento");
        $st->execute([$c['id']]);
        $formas = [];
        foreach ($st->fetchAll() as $r) $formas[$r['forma_pagamento']] = ['qtd' => (int) $r['qtd'], 'total' => (float) $r['total'], 'estornado' => (float) $r['estornado']];

        // dinheiro devolvido ao cliente com este caixa aberto (de vendas pagas em dinheiro, de qualquer dia)
        $devolucoes = $um("SELECT COALESCE(SUM(d.valor), 0) FROM devolucoes d JOIN vendas v ON v.id = d.venda_id WHERE v.forma_pagamento = 'dinheiro' AND d.caixa_id = ?", [$c['id']]);
        $cancelamentos = $um("SELECT COALESCE(SUM(total - valor_devolvido), 0) FROM vendas WHERE forma_pagamento = 'dinheiro' AND status = 'cancelada' AND cancelado_caixa_id = ?", [$c['id']]);

        $st = $pdo->prepare('SELECT m.*, u.nome AS usuario FROM caixa_movimentos m LEFT JOIN usuarios u ON u.id = m.usuario_id WHERE m.caixa_id = ? ORDER BY m.id');
        $st->execute([$c['id']]);
        $movimentos = $st->fetchAll();
        $sangrias = array_sum(array_map(fn($m) => $m['tipo'] === 'sangria' ? $m['valor'] : 0, $movimentos));
        $reforcos = array_sum(array_map(fn($m) => $m['tipo'] === 'reforco' ? $m['valor'] : 0, $movimentos));

        $vendasDinheiro = $formas['dinheiro']['total'] ?? 0.0;
        $devolvido = round($devolucoes + $cancelamentos, 2);
        return [
            'troco' => (float) $c['troco_inicial'],
            'formas' => $formas,
            'vendas_dinheiro' => $vendasDinheiro,
            'devolvido_dinheiro' => $devolvido,
            'sangrias' => $sangrias,
            'reforcos' => $reforcos,
            'esperado' => round($c['troco_inicial'] + $vendasDinheiro + $reforcos - $sangrias - $devolvido, 2),
            'movimentos' => $movimentos,
        ];
    }

    /**
     * Estorno em dinheiro (cancelamento/devolução, só o admin faz) não pode tirar da gaveta mais do que ela deveria ter.
     * $jaContado: parte do estorno que já está gravada na transação (devolução), para não descontar duas vezes.
     */
    public static function conferirEstorno(float $valor, float $jaContado = 0): void {
        $c = self::aberto();
        if (!$c) return;
        $tem = round(self::resumo($c)['esperado'] + $jaContado, 2);
        if (round($valor, 2) > $tem) {
            if ($tem <= 0) throw new DomainException('A gaveta está sem dinheiro pela conta do sistema (esperado ' . moeda($tem) . '): faça um reforço no Caixa antes de devolver ' . moeda($valor) . ' (ou devolva por PIX).');
            throw new DomainException('A gaveta deveria ter só ' . moeda($tem) . ' e o estorno é de ' . moeda($valor) . ': faça um reforço no Caixa antes (ou devolva por PIX).');
        }
    }

    /** Fecha com o dinheiro contado na gaveta. Diferença diferente de zero pede observação. @return array o caixa fechado */
    public static function fechar(string $contado, string $obs): array {
        $v = numero($contado, 'Valor contado inválido: digite só números, ex.: 350,00.');
        $obs = trim($obs);
        if ($contado === '' || $v < 0 || $v > 10000000) throw new DomainException('Informe o dinheiro contado na gaveta, ex.: 350,00 (0 se não sobrou nada).');
        if (mb_strlen($obs) > 200) throw new DomainException('A observação deve ter no máximo 200 caracteres.');
        $pdo = Database::pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $c = self::aberto();
            if (!$c) throw new DomainException('O caixa já está fechado.');
            $agora = date('Y-m-d H:i:s');
            $esperado = self::resumo($c)['esperado'];
            $dif = round($v - $esperado, 2);
            // contagem às cegas (vendedor): fecha com o que contou, sem saber se bateu; a diferença fica para o admin conferir
            if ($dif != 0 && $obs === '' && !temPerfil(['vendedor'])) throw new DomainException('O dinheiro contado (' . moeda($v) . ') não bate com o esperado: há ' . ($dif > 0 ? 'sobra' : 'falta') . ' de ' . moeda(abs($dif)) . '. Confira a contagem ou explique na observação.');
            $pdo->prepare('UPDATE caixas SET fechado_em = ?, fechado_por = ?, contado = ?, esperado = ?, diferenca = ?, observacao = ? WHERE id = ? AND fechado_em IS NULL')
                ->execute([$agora, usuario()['id'] ?? null, round($v, 2), $esperado, $dif, $obs ?: null, $c['id']]);
            Auditoria::registrar('caixa_fechado', "Caixa #{$c['id']}: contado " . moeda($v) . ', esperado ' . moeda($esperado) . ($dif == 0 ? ', sem diferença' : ', ' . ($dif > 0 ? 'sobra' : 'falta') . ' de ' . moeda(abs($dif))));
            $pdo->commit();
            return self::buscar((int) $c['id']);
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
}
