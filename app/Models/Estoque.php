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
        if (mb_strlen($lote) > 100) throw new DomainException('O lote deve ter no máximo 100 caracteres.');
        if (mb_strlen(trim($fornecedor)) > 100) throw new DomainException('O fornecedor deve ter no máximo 100 caracteres.');
        $validade = trim($validade) ?: null;
        if ($validade !== null) {
            $dt = DateTime::createFromFormat('!Y-m-d', $validade);
            if (!$dt || $dt->format('Y-m-d') !== $validade) throw new DomainException('Data de validade inválida.');
            if ($validade < date('Y-m-d')) throw new DomainException('A validade informada já passou. Produto vencido não pode entrar no estoque.');
        }
        $pdo = Database::pdo();
        $propria = !$pdo->inTransaction();
        // IMMEDIATE: transação comum que lê antes de gravar dá "database is locked" na hora se outro processo grava
        if ($propria) $pdo->exec('BEGIN IMMEDIATE');
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
        } catch (Throwable $e) { if ($propria && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
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
        if (mb_strlen(trim($obs)) > 200) throw new DomainException('A observação deve ter no máximo 200 caracteres.');
        $pdo = Database::pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $st = $pdo->prepare('SELECT l.*, p.nome FROM lotes l JOIN produtos p ON p.id = l.produto_id WHERE l.id = ? AND p.ativo = 1');
            $st->execute([$loteId]);
            $l = $st->fetch();
            if (!$l) throw new DomainException('Lote não encontrado.');
            if ($qtd > $l['quantidade']) throw new DomainException("O lote {$l['codigo']} de {$l['nome']} só tem {$l['quantidade']} " . ((int) $l['quantidade'] === 1 ? 'unidade' : 'unidades') . '.');
            $pdo->prepare('UPDATE lotes SET quantidade = quantidade - ? WHERE id = ?')->execute([$qtd, $loteId]);
            $pdo->prepare('UPDATE produtos SET estoque = estoque - ? WHERE id = ?')->execute([$qtd, $l['produto_id']]);
            self::registrar((int)$l['produto_id'], $loteId, 'baixa', -$qtd, null, self::MOTIVOS_BAIXA[$motivo] . (trim($obs) ? ': ' . trim($obs) : ''));
            Auditoria::registrar('estoque_baixa', "{$l['nome']}: -$qtd do lote {$l['codigo']} (" . self::MOTIVOS_BAIXA[$motivo] . ')');
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    /**
     * Lista de compras: produtos no mínimo ou abaixo e os que chegam nele em até 14 dias pela venda dos últimos 30.
     * Sugestão = 30 dias de venda + mínimo − estoque, e pelo menos o que falta para o dobro do mínimo (produto parado).
     * Mínimo 0 = não controla reposição (mesma regra de Produto::precisaRepor). Estoque = o que dá para vender (sem lote vencido), como no caixa.
     */
    public static function listaCompras(): array {
        $st = Database::pdo()->prepare("SELECT p.id, p.nome, " . Produto::VENDAVEL . " AS estoque, p.estoque_minimo, p.preco_custo,
                COALESCE((SELECT -SUM(m.quantidade) FROM movimentacoes_estoque m
                    WHERE m.produto_id = p.id AND m.tipo IN ('venda', 'devolucao', 'cancelamento') AND m.criado_em >= ?), 0) AS vendidos30,
                (SELECT e.fornecedor FROM entradas_estoque e WHERE e.produto_id = p.id AND TRIM(e.fornecedor) <> '' ORDER BY e.id DESC LIMIT 1) AS fornecedor
            FROM produtos p WHERE p.ativo = 1 AND p.estoque_minimo > 0 ORDER BY p.nome");
        $st->execute([date('Y-m-d', strtotime('-30 days'))]);
        $lista = [];
        foreach ($st->fetchAll() as $p) {
            $vendidos = max(0, (int) $p['vendidos30']); // 30 dias de venda: é a própria cobertura da sugestão
            $folga = $p['estoque'] - $p['estoque_minimo'];
            if ($folga > 0 && ($vendidos === 0 || $folga * 30 / $vendidos > 14)) continue;
            $p['por_semana'] = round($vendidos * 7 / 30, 1);
            $p['sugestao'] = max($vendidos + $p['estoque_minimo'] - $p['estoque'], 2 * $p['estoque_minimo'] - $p['estoque'], 1);
            $lista[] = $p;
        }
        return $lista;
    }

    /**
     * Fornecedores já usados nas entradas, um por nome (sem diferenciar maiúsculas nem espaços), para sugerir no formulário
     * e dar nome ao pedido. Chave = nome em minúsculas; valor = o jeito escrito mais usado (um erro de digitação não troca o nome).
     */
    public static function fornecedores(): array {
        $contagem = Database::pdo()->query("SELECT TRIM(fornecedor) AS nome, COUNT(*) AS vezes, MAX(id) AS ultimo FROM entradas_estoque
            WHERE TRIM(fornecedor) <> '' GROUP BY TRIM(fornecedor) ORDER BY vezes DESC, ultimo DESC LIMIT 500")->fetchAll();
        $nomes = [];
        foreach ($contagem as $c) $nomes[mb_strtolower(preg_replace('/\s+/u', ' ', $c['nome']) ?? $c['nome'])] ??= $c['nome'];
        natcasesort($nomes);
        return $nomes;
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
