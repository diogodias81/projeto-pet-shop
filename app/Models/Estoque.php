<?php
class Estoque {
    public const TIPOS = ['entrada' => 'Entrada', 'venda' => 'Venda', 'devolucao' => 'Devolução', 'cancelamento' => 'Cancelamento', 'baixa' => 'Baixa'];
    public const MOTIVOS_BAIXA = ['vencido' => 'Vencido', 'avaria' => 'Avaria / danificado', 'perda' => 'Perda / extravio', 'inventario' => 'Ajuste de inventário'];

    /** Prazos de alerta em dias, do maior para o menor (config 'alertas_validade_dias') */
    public static function prazos(): array {
        $p = array_map('intval', config('alertas_validade_dias') ?: [30]);
        rsort($p);
        return $p;
    }

    /** Registra uma movimentação; quantidade negativa = saída. Roda dentro da transação de quem chama. */
    private static function registrar(int $produtoId, ?int $loteId, string $tipo, int $qtd, ?int $vendaId = null, string $obs = ''): void {
        Database::pdo()->prepare('INSERT INTO movimentacoes_estoque (produto_id,lote_id,tipo,quantidade,venda_id,usuario_id,observacao) VALUES (?,?,?,?,?,?,?)')
            ->execute([$produtoId, $loteId, $tipo, $qtd, $vendaId, usuario()['id'] ?? null, trim($obs) ?: null]);
    }

    /**
     * Registra entrada em um lote e soma ao estoque. Lote com mesmo código e validade recebe a quantidade.
     * Lote vazio vira "L" + data de hoje. Validade vazia = produto sem validade (ex.: brinquedos).
     */
    public static function entrada(int $produtoId, int $qtd, string $fornecedor, string $lote = '', string $validade = ''): void {
        if (!($produto = Produto::buscar($produtoId))) throw new DomainException('Produto não encontrado.');
        if ($qtd < 1) throw new DomainException('A quantidade deve ser maior que zero.');
        if ($qtd > 100000) throw new DomainException('Quantidade acima do limite de 100.000 unidades por entrada.');
        $lote = mb_strtoupper(trim($lote)) ?: 'L' . date('Ymd');
        $validade = trim($validade) ?: null;
        if ($validade !== null) {
            $dt = DateTime::createFromFormat('!Y-m-d', $validade);
            if (!$dt || $dt->format('Y-m-d') !== $validade) throw new DomainException('Data de validade inválida.');
            if ($validade < date('Y-m-d')) throw new DomainException('A validade informada já passou. Produto vencido não pode entrar no estoque.');
        }
        $pdo = Database::pdo();
        $propria = !$pdo->inTransaction();
        if ($propria) $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT id FROM lotes WHERE produto_id = ? AND codigo = ? AND validade IS ?');
            $st->execute([$produtoId, $lote, $validade]);
            if ($loteId = (int)$st->fetchColumn()) {
                $pdo->prepare('UPDATE lotes SET quantidade = quantidade + ? WHERE id = ?')->execute([$qtd, $loteId]);
            } else {
                $pdo->prepare('INSERT INTO lotes (produto_id,codigo,validade,quantidade) VALUES (?,?,?,?)')->execute([$produtoId, $lote, $validade, $qtd]);
                $loteId = (int)$pdo->lastInsertId();
            }
            $pdo->prepare('INSERT INTO entradas_estoque (produto_id,quantidade,fornecedor,lote_id) VALUES (?,?,?,?)')->execute([$produtoId, $qtd, trim($fornecedor), $loteId]);
            $pdo->prepare('UPDATE produtos SET estoque = estoque + ? WHERE id = ?')->execute([$qtd, $produtoId]);
            self::registrar($produtoId, $loteId, 'entrada', $qtd, null, trim($fornecedor) ? 'Fornecedor: ' . trim($fornecedor) : '');
            Auditoria::registrar('estoque_entrada', "{$produto['nome']}: +$qtd no lote $lote" . ($validade ? ', validade ' . date('d/m/Y', strtotime($validade)) : ''));
            if ($propria) $pdo->commit();
        } catch (Throwable $e) { if ($propria) $pdo->rollBack(); throw $e; }
    }

    /**
     * Baixa a quantidade vendida dos lotes, primeiro o que vence antes (lotes sem validade por último).
     * Lotes vencidos nunca são usados. Deve rodar dentro da transação da venda.
     * @return array [lote_id => quantidade tirada]
     */
    public static function baixarVenda(int $produtoId, int $qtd, ?int $vendaId = null): array {
        $pdo = Database::pdo();
        $st = $pdo->prepare("SELECT id, quantidade FROM lotes WHERE produto_id = ? AND quantidade > 0 AND (validade IS NULL OR validade >= date('now','localtime')) ORDER BY validade IS NULL, validade, id");
        $st->execute([$produtoId]);
        $upd = $pdo->prepare('UPDATE lotes SET quantidade = quantidade - ? WHERE id = ?');
        $falta = $qtd;
        $usados = [];
        foreach ($st->fetchAll() as $l) {
            if ($falta === 0) break;
            $tira = min($falta, (int)$l['quantidade']);
            $upd->execute([$tira, $l['id']]);
            self::registrar($produtoId, (int)$l['id'], 'venda', -$tira, $vendaId);
            $usados[(int)$l['id']] = $tira;
            $falta -= $tira;
        }
        if ($falta > 0) throw new DomainException('Estoque válido insuficiente: há unidades vencidas que não podem ser vendidas.');
        $pdo->prepare('UPDATE produtos SET estoque = estoque - ? WHERE id = ?')->execute([$qtd, $produtoId]);
        return $usados;
    }

    /**
     * Devolve ao estoque unidades de um item vendido, nos mesmos lotes de onde saíram
     * (começando pelo último usado). Vendas anteriores à Etapa 2 não guardavam o lote:
     * essas unidades voltam para o lote "DEVOLUCAO", sem validade. Roda dentro da transação do chamador.
     */
    public static function devolverItem(int $vendaItemId, int $produtoId, int $qtd, string $tipo = 'devolucao', string $obs = ''): void {
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT venda_id FROM venda_itens WHERE id = ?');
        $st->execute([$vendaItemId]);
        $vendaId = (int)$st->fetchColumn() ?: null;
        $st = $pdo->prepare('SELECT id, lote_id, quantidade - devolvida AS resta FROM venda_item_lotes WHERE venda_item_id = ? AND quantidade > devolvida ORDER BY id DESC');
        $st->execute([$vendaItemId]);
        $falta = $qtd;
        foreach ($st->fetchAll() as $r) {
            if ($falta === 0) break;
            $volta = min($falta, (int)$r['resta']);
            $pdo->prepare('UPDATE venda_item_lotes SET devolvida = devolvida + ? WHERE id = ?')->execute([$volta, $r['id']]);
            $pdo->prepare('UPDATE lotes SET quantidade = quantidade + ? WHERE id = ?')->execute([$volta, $r['lote_id']]);
            self::registrar($produtoId, (int)$r['lote_id'], $tipo, $volta, $vendaId, $obs);
            $falta -= $volta;
        }
        if ($falta > 0) {
            $st = $pdo->prepare("SELECT id FROM lotes WHERE produto_id = ? AND codigo = 'DEVOLUCAO' AND validade IS NULL");
            $st->execute([$produtoId]);
            if ($loteId = (int)$st->fetchColumn()) $pdo->prepare('UPDATE lotes SET quantidade = quantidade + ? WHERE id = ?')->execute([$falta, $loteId]);
            else { $pdo->prepare("INSERT INTO lotes (produto_id,codigo,quantidade) VALUES (?,'DEVOLUCAO',?)")->execute([$produtoId, $falta]); $loteId = (int)$pdo->lastInsertId(); }
            self::registrar($produtoId, $loteId, $tipo, $falta, $vendaId, $obs);
        }
        $pdo->prepare('UPDATE produtos SET estoque = estoque + ? WHERE id = ?')->execute([$qtd, $produtoId]);
    }

    /** Lotes com saldo, do vencimento mais próximo para o mais distante */
    public static function lotes(string $busca = ''): array {
        $sql = 'SELECT l.*, p.nome AS produto, p.codigo_barras FROM lotes l JOIN produtos p ON p.id = l.produto_id WHERE p.ativo = 1 AND l.quantidade > 0';
        $par = [];
        if ($busca !== '') { $sql .= ' AND (p.nome LIKE ? OR p.codigo_barras = ? OR l.codigo LIKE ?)'; array_push($par, "%$busca%", $busca, "%$busca%"); }
        $st = Database::pdo()->prepare($sql . ' ORDER BY l.validade IS NULL, l.validade, p.nome');
        $st->execute($par);
        return $st->fetchAll();
    }

    /** Dias até o vencimento (negativo = vencido). Null quando não tem validade. */
    public static function diasParaVencer(?string $validade): ?int {
        if (!$validade) return null;
        return (int)(new DateTime(date('Y-m-d')))->diff(new DateTime($validade))->format('%r%a');
    }

    /** 'vencido', 'vencendo' (dentro do maior prazo de alerta), 'ok' ou 'sem' (sem validade) */
    public static function situacao(?string $validade): string {
        $dias = self::diasParaVencer($validade);
        if ($dias === null) return 'sem';
        if ($dias < 0) return 'vencido';
        return $dias <= self::prazos()[0] ? 'vencendo' : 'ok';
    }

    /** Menor prazo de alerta que já foi atingido (ex.: 7, 15 ou 30). Null se vencido ou fora dos prazos. */
    public static function faixa(int $dias): ?int {
        if ($dias < 0) return null;
        $faixa = null;
        foreach (self::prazos() as $p) if ($dias <= $p) $faixa = $p;
        return $faixa;
    }

    /** Lotes com saldo vencidos ou dentro do maior prazo de alerta, do que vence antes para o que vence depois */
    public static function alertasValidade(): array {
        $st = Database::pdo()->prepare("SELECT l.*, p.nome AS produto FROM lotes l JOIN produtos p ON p.id = l.produto_id WHERE p.ativo = 1 AND l.quantidade > 0 AND l.validade IS NOT NULL AND l.validade <= ? ORDER BY l.validade, p.nome");
        $st->execute([date('Y-m-d', strtotime('+' . self::prazos()[0] . ' days'))]);
        $lotes = $st->fetchAll();
        foreach ($lotes as &$l) $l['dias'] = self::diasParaVencer($l['validade']);
        return $lotes;
    }

    /** Quantos alertas (lotes vencidos ou vencendo + produtos com estoque baixo) para o aviso no menu */
    public static function contarAlertas(): int {
        return count(self::alertasValidade()) + count(Produto::baixoEstoque());
    }

    /** Retira unidades de um lote (vencido, avaria, perda, inventário) e registra o motivo */
    public static function baixa(int $loteId, int $qtd, string $motivo, string $obs = ''): void {
        if (!isset(self::MOTIVOS_BAIXA[$motivo])) throw new DomainException('Escolha o motivo da baixa.');
        if ($qtd < 1) throw new DomainException('A quantidade deve ser maior que zero.');
        $pdo = Database::pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $st = $pdo->prepare('SELECT l.*, p.nome FROM lotes l JOIN produtos p ON p.id = l.produto_id WHERE l.id = ? AND p.ativo = 1');
            $st->execute([$loteId]);
            $l = $st->fetch();
            if (!$l) throw new DomainException('Lote não encontrado.');
            if ($qtd > $l['quantidade']) throw new DomainException("O lote {$l['codigo']} de {$l['nome']} só tem {$l['quantidade']} unidade(s).");
            $pdo->prepare('UPDATE lotes SET quantidade = quantidade - ? WHERE id = ?')->execute([$qtd, $loteId]);
            $pdo->prepare('UPDATE produtos SET estoque = estoque - ? WHERE id = ?')->execute([$qtd, $l['produto_id']]);
            self::registrar((int)$l['produto_id'], $loteId, 'baixa', -$qtd, null, self::MOTIVOS_BAIXA[$motivo] . (trim($obs) ? ': ' . trim($obs) : ''));
            Auditoria::registrar('estoque_baixa', "{$l['nome']}: -$qtd do lote {$l['codigo']} (" . self::MOTIVOS_BAIXA[$motivo] . ')');
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    }

    /** Últimas movimentações, filtradas pela mesma busca da tela (produto, código de barras ou lote) */
    public static function movimentacoes(string $busca = '', int $limite = 30): array {
        $sql = 'SELECT m.*, p.nome AS produto, l.codigo AS lote, l.validade, u.nome AS usuario FROM movimentacoes_estoque m JOIN produtos p ON p.id = m.produto_id LEFT JOIN lotes l ON l.id = m.lote_id LEFT JOIN usuarios u ON u.id = m.usuario_id';
        $par = [];
        if ($busca !== '') { $sql .= ' WHERE (p.nome LIKE ? OR p.codigo_barras = ? OR l.codigo LIKE ?)'; array_push($par, "%$busca%", $busca, "%$busca%"); }
        $st = Database::pdo()->prepare($sql . ' ORDER BY m.id DESC LIMIT ' . (int)$limite);
        $st->execute($par);
        return $st->fetchAll();
    }
}
