<?php
class Venda {
    public const FORMAS = ['dinheiro' => 'Dinheiro', 'pix' => 'PIX', 'debito' => 'Cartão de débito', 'credito' => 'Cartão de crédito'];

    /**
     * Registra a venda: refaz no servidor subtotal, desconto e total, baixa o estoque pelos lotes e guarda de qual lote saiu cada unidade.
     * @param array $itens [['produto_id'=>1,'quantidade'=>2], ...]
     * @param array $pag ['desconto_tipo'=>'valor'|'percentual', 'desconto'=>'5,00', 'forma'=>'dinheiro', 'recebido'=>'50,00']
     * @param float $descontoMax fração máxima de desconto permitida a quem vende (1.0 = sem limite)
     * @return array ['id'=>int, 'total'=>float, 'alertas'=>string[]]
     */
    public static function registrar(int $usuarioId, array $itens, array $pag = [], float $descontoMax = 1.0): array {
        // junta linhas repetidas do mesmo produto para conferir o estoque de uma vez
        $qtds = [];
        foreach ($itens as $it) {
            $id = (int)($it['produto_id'] ?? 0); $q = (int)($it['quantidade'] ?? 0);
            if ($id < 1 || $q < 1) throw new DomainException('Item inválido na venda.');
            $qtds[$id] = ($qtds[$id] ?? 0) + $q;
        }
        if (!$qtds) throw new DomainException('Adicione ao menos um item à venda.');
        $forma = $pag['forma'] ?? '';
        if (!isset(self::FORMAS[$forma])) throw new DomainException('Escolha a forma de pagamento.');
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO vendas (usuario_id, forma_pagamento) VALUES (?,?)')->execute([$usuarioId, $forma]);
            $vendaId = (int)$pdo->lastInsertId();
            $subtotal = 0.0; $alertas = [];
            foreach ($qtds as $produtoId => $qtd) {
                $p = Produto::buscar($produtoId);
                if (!$p) throw new DomainException('Item inválido na venda.');
                if ($p['vendavel'] < $qtd) throw new DomainException("Estoque insuficiente para {$p['nome']} (disponível para venda: {$p['vendavel']}" . ($p['estoque'] > $p['vendavel'] ? ', o restante está vencido' : '') . ').');
                $pdo->prepare('INSERT INTO venda_itens (venda_id,produto_id,quantidade,preco_unit,custo_unit) VALUES (?,?,?,?,?)')->execute([$vendaId, $p['id'], $qtd, $p['preco'], $p['preco_custo']]);
                $itemId = (int)$pdo->lastInsertId();
                foreach (Estoque::baixarVenda((int)$p['id'], $qtd) as $loteId => $q) {
                    $pdo->prepare('INSERT INTO venda_item_lotes (venda_item_id,lote_id,quantidade) VALUES (?,?,?)')->execute([$itemId, $loteId, $q]);
                }
                $subtotal += $qtd * $p['preco'];
                if ($p['estoque'] - $qtd <= $p['estoque_minimo']) {
                    $alertas[] = "{$p['nome']} atingiu o estoque mínimo (restam " . ($p['estoque'] - $qtd) . ").";
                }
            }
            $subtotal = round($subtotal, 2);
            $desconto = self::desconto($subtotal, $pag, $descontoMax);
            $total = round($subtotal - $desconto, 2);
            $recebido = null;
            if ($forma === 'dinheiro') {
                $recebido = numero($pag['recebido'] ?? '') ?: $total;
                if ($recebido < $total) throw new DomainException('O valor recebido (' . moeda($recebido) . ') é menor que o total (' . moeda($total) . ').');
            }
            $pdo->prepare('UPDATE vendas SET subtotal = ?, desconto = ?, total = ?, valor_recebido = ? WHERE id = ?')->execute([$subtotal, $desconto, $total, $recebido, $vendaId]);
            $pdo->commit();
            return ['id' => $vendaId, 'total' => $total, 'alertas' => $alertas];
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    }

    /** Desconto em R$, validado contra o subtotal e o limite de quem vende */
    private static function desconto(float $subtotal, array $pag, float $max): float {
        $v = numero($pag['desconto'] ?? 0);
        if ($v < 0) throw new DomainException('O desconto não pode ser negativo.');
        $desconto = round(($pag['desconto_tipo'] ?? 'valor') === 'percentual' ? $subtotal * $v / 100 : $v, 2);
        if ($desconto > $subtotal) throw new DomainException('O desconto não pode ser maior que o subtotal.');
        if ($desconto > round($subtotal * $max, 2)) {
            throw new DomainException('Desconto acima do permitido para o seu perfil: máximo de ' . round($max * 100) . '% (' . moeda($subtotal * $max) . '). Peça a um administrador.');
        }
        return $desconto;
    }

    /** Venda completa: cabeçalho, itens e devoluções. Null se não existir. */
    public static function buscar(int $id): ?array {
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT v.*, u.nome AS vendedor, c.nome AS cancelado_por_nome FROM vendas v LEFT JOIN usuarios u ON u.id=v.usuario_id LEFT JOIN usuarios c ON c.id=v.cancelado_por WHERE v.id = ?');
        $st->execute([$id]);
        if (!$v = $st->fetch()) return null;
        $st = $pdo->prepare('SELECT vi.*, p.nome, p.codigo_barras FROM venda_itens vi JOIN produtos p ON p.id=vi.produto_id WHERE vi.venda_id = ? ORDER BY vi.id');
        $st->execute([$id]);
        $v['itens'] = $st->fetchAll();
        $st = $pdo->prepare('SELECT d.*, p.nome AS produto, u.nome AS usuario FROM devolucoes d JOIN venda_itens vi ON vi.id=d.venda_item_id JOIN produtos p ON p.id=vi.produto_id LEFT JOIN usuarios u ON u.id=d.usuario_id WHERE d.venda_id = ? ORDER BY d.id');
        $st->execute([$id]);
        $v['devolucoes'] = $st->fetchAll();
        return $v;
    }

    /**
     * Histórico de vendas com filtros opcionais.
     * @param array $f de, ate (AAAA-MM-DD), produto_id, usuario_id, forma, status ('concluida'|'cancelada')
     */
    public static function listar(array $f = [], int $limite = 50): array {
        $sql = "SELECT v.*, u.nome AS vendedor, CASE WHEN v.status='cancelada' THEN 0 ELSE v.total - v.valor_devolvido END AS liquido,
                (SELECT GROUP_CONCAT(vi.quantidade || 'x ' || p.nome, ', ') FROM venda_itens vi JOIN produtos p ON p.id=vi.produto_id WHERE vi.venda_id=v.id) AS itens
                FROM vendas v LEFT JOIN usuarios u ON u.id=v.usuario_id WHERE 1=1";
        $par = [];
        if (!empty($f['de']))         { $sql .= ' AND date(v.criado_em) >= ?'; $par[] = $f['de']; }
        if (!empty($f['ate']))        { $sql .= ' AND date(v.criado_em) <= ?'; $par[] = $f['ate']; }
        if (!empty($f['usuario_id'])) { $sql .= ' AND v.usuario_id = ?'; $par[] = (int)$f['usuario_id']; }
        if (!empty($f['forma']))      { $sql .= ' AND v.forma_pagamento = ?'; $par[] = $f['forma']; }
        if (!empty($f['status']))     { $sql .= ' AND v.status = ?'; $par[] = $f['status']; }
        if (!empty($f['produto_id'])) { $sql .= ' AND EXISTS (SELECT 1 FROM venda_itens x WHERE x.venda_id=v.id AND x.produto_id = ?)'; $par[] = (int)$f['produto_id']; }
        $st = Database::pdo()->prepare($sql . ' ORDER BY v.id DESC LIMIT ' . (int)$limite);
        $st->execute($par);
        return $st->fetchAll();
    }

    /** Admin altera qualquer venda; vendedor só as próprias, no mesmo dia. Venda cancelada não muda mais. */
    public static function podeAlterar(array $v, array $usuario): bool {
        if ($v['status'] === 'cancelada') return false;
        if ($usuario['perfil'] === 'admin') return true;
        return $usuario['perfil'] === 'vendedor' && (int)$v['usuario_id'] === (int)$usuario['id'] && substr($v['criado_em'], 0, 10) === date('Y-m-d');
    }

    /** Cancela a venda inteira: o que ainda não foi devolvido volta aos lotes. O registro é mantido. */
    public static function cancelar(int $id, array $usuario, string $motivo): void {
        $motivo = trim($motivo);
        if ($motivo === '') throw new DomainException('Informe o motivo do cancelamento.');
        $v = self::buscar($id);
        if (!$v) throw new DomainException('Venda não encontrada.');
        if (!self::podeAlterar($v, $usuario)) throw new DomainException('Você não pode cancelar esta venda.');
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            foreach ($v['itens'] as $it) {
                $resta = $it['quantidade'] - $it['quantidade_devolvida'];
                if ($resta > 0) Estoque::devolverItem((int)$it['id'], (int)$it['produto_id'], $resta);
            }
            $pdo->prepare("UPDATE vendas SET status='cancelada', cancelado_em=datetime('now','localtime'), cancelado_por=?, motivo_cancelamento=? WHERE id=?")
                ->execute([$usuario['id'], $motivo, $id]);
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    }

    /**
     * Devolução parcial. O valor devolvido de cada item considera o desconto da venda (proporcional).
     * @param array $qtds [venda_item_id => quantidade a devolver]
     * @return float valor total devolvido
     */
    public static function devolver(int $id, array $usuario, array $qtds, string $motivo): float {
        $motivo = trim($motivo);
        if ($motivo === '') throw new DomainException('Informe o motivo da devolução.');
        $v = self::buscar($id);
        if (!$v) throw new DomainException('Venda não encontrada.');
        if (!self::podeAlterar($v, $usuario)) throw new DomainException('Você não pode registrar devolução nesta venda.');
        $fator = $v['subtotal'] > 0 ? $v['total'] / $v['subtotal'] : 1;
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $soma = 0.0; $unidades = 0;
            foreach ($v['itens'] as $it) {
                $q = (int)($qtds[$it['id']] ?? 0);
                if ($q === 0) continue;
                $unidades += $q;
                $resta = $it['quantidade'] - $it['quantidade_devolvida'];
                if ($q < 0 || $q > $resta) throw new DomainException("Quantidade inválida para {$it['nome']}: só restam $resta para devolver.");
                $valor = round($q * $it['preco_unit'] * $fator, 2);
                Estoque::devolverItem((int)$it['id'], (int)$it['produto_id'], $q);
                $pdo->prepare('UPDATE venda_itens SET quantidade_devolvida = quantidade_devolvida + ? WHERE id = ?')->execute([$q, $it['id']]);
                $pdo->prepare('INSERT INTO devolucoes (venda_id,venda_item_id,quantidade,valor,motivo,usuario_id) VALUES (?,?,?,?,?,?)')->execute([$id, $it['id'], $q, $valor, $motivo, $usuario['id']]);
                $soma += $valor;
            }
            if ($unidades === 0) throw new DomainException('Informe a quantidade de pelo menos um item para devolver.');
            // devolução de tudo: zera exatamente, sem sobra de centavos por arredondamento
            $pdo->prepare('UPDATE vendas SET valor_devolvido = CASE WHEN (SELECT SUM(quantidade - quantidade_devolvida) FROM venda_itens WHERE venda_id = ?) = 0 THEN total ELSE MIN(total, valor_devolvido + ?) END WHERE id = ?')
                ->execute([$id, $soma, $id]);
            $pdo->commit();
            return $soma;
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    }

    /** Rótulo da situação para telas e comprovante */
    public static function situacao(array $v): string {
        if ($v['status'] === 'cancelada') return 'Cancelada';
        if ($v['valor_devolvido'] >= $v['total'] && $v['total'] > 0) return 'Devolvida';
        return $v['valor_devolvido'] > 0 ? 'Devolução parcial' : 'Concluída';
    }
}
