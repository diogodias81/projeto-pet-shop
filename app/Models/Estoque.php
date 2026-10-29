<?php
class Estoque {
    /** Dias antes do vencimento em que o lote passa a ser destacado */
    public const DIAS_AVISO_VALIDADE = 30;

    /**
     * Registra entrada em um lote e soma ao estoque. Lote com mesmo código e validade recebe a quantidade.
     * Lote vazio vira "L" + data de hoje. Validade vazia = produto sem validade (ex.: brinquedos).
     */
    public static function entrada(int $produtoId, int $qtd, string $fornecedor, string $lote = '', string $validade = ''): void {
        if (!Produto::buscar($produtoId)) throw new DomainException('Produto não encontrado.');
        if ($qtd < 1) throw new DomainException('A quantidade deve ser maior que zero.');
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
            if ($propria) $pdo->commit();
        } catch (Throwable $e) { if ($propria) $pdo->rollBack(); throw $e; }
    }

    /**
     * Baixa a quantidade vendida dos lotes, primeiro o que vence antes (lotes sem validade por último).
     * Lotes vencidos nunca são usados. Deve rodar dentro da transação da venda.
     * @return array [lote_id => quantidade tirada]
     */
    public static function baixarVenda(int $produtoId, int $qtd): array {
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
    public static function devolverItem(int $vendaItemId, int $produtoId, int $qtd): void {
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT id, lote_id, quantidade - devolvida AS resta FROM venda_item_lotes WHERE venda_item_id = ? AND quantidade > devolvida ORDER BY id DESC');
        $st->execute([$vendaItemId]);
        $falta = $qtd;
        foreach ($st->fetchAll() as $r) {
            if ($falta === 0) break;
            $volta = min($falta, (int)$r['resta']);
            $pdo->prepare('UPDATE venda_item_lotes SET devolvida = devolvida + ? WHERE id = ?')->execute([$volta, $r['id']]);
            $pdo->prepare('UPDATE lotes SET quantidade = quantidade + ? WHERE id = ?')->execute([$volta, $r['lote_id']]);
            $falta -= $volta;
        }
        if ($falta > 0) {
            $st = $pdo->prepare("SELECT id FROM lotes WHERE produto_id = ? AND codigo = 'DEVOLUCAO' AND validade IS NULL");
            $st->execute([$produtoId]);
            if ($loteId = (int)$st->fetchColumn()) $pdo->prepare('UPDATE lotes SET quantidade = quantidade + ? WHERE id = ?')->execute([$falta, $loteId]);
            else $pdo->prepare("INSERT INTO lotes (produto_id,codigo,quantidade) VALUES (?,'DEVOLUCAO',?)")->execute([$produtoId, $falta]);
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

    /** 'vencido', 'vencendo' (até DIAS_AVISO_VALIDADE), 'ok' ou 'sem' (sem validade) */
    public static function situacao(?string $validade): string {
        if (!$validade) return 'sem';
        $hoje = date('Y-m-d');
        if ($validade < $hoje) return 'vencido';
        return $validade <= date('Y-m-d', strtotime('+' . self::DIAS_AVISO_VALIDADE . ' days')) ? 'vencendo' : 'ok';
    }

    public static function historico(int $limite = 15): array {
        return Database::pdo()->query("SELECT e.*, p.nome AS produto, l.codigo AS lote, l.validade FROM entradas_estoque e JOIN produtos p ON p.id=e.produto_id LEFT JOIN lotes l ON l.id=e.lote_id ORDER BY e.id DESC LIMIT " . (int)$limite)->fetchAll();
    }
}
