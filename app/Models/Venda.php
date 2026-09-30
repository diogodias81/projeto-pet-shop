<?php
class Venda {
    /**
     * RF05/RF06/RF07/RF08: registra a venda, calcula o total no servidor e baixa o estoque.
     * @param array $itens [['produto_id'=>1,'quantidade'=>2], ...]
     * @return array ['id'=>int, 'total'=>float, 'alertas'=>string[]]
     */
    public static function registrar(int $usuarioId, array $itens): array {
        if (!$itens) throw new DomainException('Adicione ao menos um item à venda.');
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO vendas (usuario_id) VALUES (?)')->execute([$usuarioId]);
            $vendaId = (int)$pdo->lastInsertId();
            $total = 0.0; $alertas = [];
            foreach ($itens as $it) {
                $qtd = (int)($it['quantidade'] ?? 0);
                $p = Produto::buscar((int)($it['produto_id'] ?? 0));
                if (!$p || $qtd < 1) throw new DomainException('Item inválido na venda.');
                if ($p['estoque'] < $qtd) throw new DomainException("Estoque insuficiente para {$p['nome']} (disponível: {$p['estoque']}).");
                $pdo->prepare('INSERT INTO venda_itens (venda_id,produto_id,quantidade,preco_unit) VALUES (?,?,?,?)')->execute([$vendaId, $p['id'], $qtd, $p['preco']]);
                $pdo->prepare('UPDATE produtos SET estoque = estoque - ? WHERE id = ?')->execute([$qtd, $p['id']]);
                $total += $qtd * $p['preco'];
                if ($p['estoque'] - $qtd <= $p['estoque_minimo']) {
                    $alertas[] = "{$p['nome']} atingiu o estoque mínimo (restam " . ($p['estoque'] - $qtd) . ").";
                }
            }
            $pdo->prepare('UPDATE vendas SET total = ? WHERE id = ?')->execute([$total, $vendaId]);
            $pdo->commit();
            return ['id' => $vendaId, 'total' => $total, 'alertas' => $alertas];
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    }

    /** RF11/RF12: histórico de vendas, com filtro opcional por período (AAAA-MM-DD) */
    public static function listar(?string $de = null, ?string $ate = null, int $limite = 50): array {
        $sql = "SELECT v.*, u.nome AS vendedor,
                (SELECT GROUP_CONCAT(vi.quantidade || 'x ' || p.nome, ', ') FROM venda_itens vi JOIN produtos p ON p.id=vi.produto_id WHERE vi.venda_id=v.id) AS itens
                FROM vendas v LEFT JOIN usuarios u ON u.id=v.usuario_id WHERE 1=1";
        $par = [];
        if ($de)  { $sql .= ' AND date(v.criado_em) >= ?'; $par[] = $de; }
        if ($ate) { $sql .= ' AND date(v.criado_em) <= ?'; $par[] = $ate; }
        $st = Database::pdo()->prepare($sql . ' ORDER BY v.id DESC LIMIT ' . (int)$limite);
        $st->execute($par);
        return $st->fetchAll();
    }
}
