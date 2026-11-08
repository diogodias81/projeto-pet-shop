<?php
class Venda {
    public const FORMAS = ['dinheiro' => 'Dinheiro', 'pix' => 'PIX', 'debito' => 'Cartão de débito', 'credito' => 'Cartão de crédito'];
    /** Serviços da agenda por venda: cada um é uma consulta, uma lista enorme travaria o servidor */
    public const MAX_SERVICOS = 20;
    /** Linhas do carrinho por venda (produtos diferentes) e tamanho máximo do JSON enviado pela tela */
    public const MAX_ITENS = 200;
    public const MAX_ITENS_BYTES = 20480;

    /** Ids de agendamento ("1,2,3" ou array) como inteiros positivos, sem repetir */
    public static function idsAgendamentos($v): array {
        return array_values(array_unique(array_filter(array_map('intval', is_array($v) ? $v : explode(',', (string) $v)), fn($i) => $i > 0)));
    }

    /**
     * Registra a venda: refaz no servidor subtotal, desconto e total, baixa o estoque pelos lotes e guarda de qual lote saiu cada unidade.
     * @param array $itens [['produto_id'=>1,'quantidade'=>2], ...]
     * @param array $pag ['desconto_tipo'=>'valor'|'percentual', 'desconto'=>'5,00', 'forma'=>'dinheiro', 'recebido'=>'50,00', 'cliente_id'=>3 (opcional)]
     * @param float $descontoMax fração máxima de desconto permitida a quem vende (1.0 = sem limite)
     * @return array ['id'=>int, 'total'=>float, 'alertas'=>string[]]
     */
    public static function registrar(int $usuarioId, array $itens, array $pag = [], float $descontoMax = 1.0): array {
        if (count($itens) > self::MAX_ITENS) throw new DomainException('Carrinho grande demais: no máximo ' . self::MAX_ITENS . ' linhas.');
        // junta linhas repetidas do mesmo produto para conferir o estoque de uma vez
        $qtds = [];
        foreach ($itens as $it) {
            if (!is_numeric($it['produto_id'] ?? null) || !is_numeric($it['quantidade'] ?? null)) throw new DomainException('Item inválido na venda.');
            $id = (int)($it['produto_id'] ?? 0); $q = (int)($it['quantidade'] ?? 0);
            if ($id < 1 || $q < 1) throw new DomainException('Item inválido na venda.');
            $qtds[$id] = ($qtds[$id] ?? 0) + $q;
        }
        // serviços de banho e tosa marcados na agenda (ids), cobrados junto
        $agendamentos = self::idsAgendamentos($pag['agendamentos'] ?? '');
        if (count($agendamentos) > self::MAX_SERVICOS) throw new DomainException('No máximo ' . self::MAX_SERVICOS . ' serviços por venda.');
        if (!$qtds && !$agendamentos) throw new DomainException('Adicione ao menos um item à venda.');
        $forma = $pag['forma'] ?? '';
        if (!isset(self::FORMAS[$forma])) throw new DomainException('Escolha a forma de pagamento.');
        $clienteId = (int)($pag['cliente_id'] ?? 0) ?: null;
        $clienteInformado = $clienteId;
        $pdo = Database::pdo();
        $pdo->exec('BEGIN IMMEDIATE'); // trava a escrita já no início: duas vendas não leem o mesmo estoque
        try {
            $caixa = Caixa::aberto();
            if (!$caixa) throw new DomainException('O caixa está fechado: abra o caixa (com o troco inicial) antes de vender.');
            if ($clienteId && !Cliente::buscar($clienteId)) throw new DomainException('Cliente não encontrado: ele pode ter sido excluído. Escolha de novo ou venda sem cliente.');
            $pdo->prepare('INSERT INTO vendas (usuario_id, forma_pagamento, cliente_id, caixa_id) VALUES (?,?,?,?)')->execute([$usuarioId, $forma, $clienteId, $caixa['id']]);
            $vendaId = (int)$pdo->lastInsertId();
            $subtotal = 0.0; $alertas = [];
            foreach ($qtds as $produtoId => $qtd) {
                $p = Produto::buscar($produtoId);
                if (!$p) throw new DomainException('Item inválido na venda.');
                if ($p['vendavel'] < $qtd) throw new DomainException("Estoque insuficiente para {$p['nome']} (disponível para venda: {$p['vendavel']}" . ($p['estoque'] > $p['vendavel'] ? ', o restante está vencido' : '') . ').');
                $pdo->prepare('INSERT INTO venda_itens (venda_id,produto_id,quantidade,preco_unit,custo_unit) VALUES (?,?,?,?,?)')->execute([$vendaId, $p['id'], $qtd, $p['preco'], $p['preco_custo']]);
                $itemId = (int)$pdo->lastInsertId();
                foreach (Estoque::baixarVenda((int)$p['id'], $qtd, $vendaId) as $loteId => $q) {
                    $pdo->prepare('INSERT INTO venda_item_lotes (venda_item_id,lote_id,quantidade) VALUES (?,?,?)')->execute([$itemId, $loteId, $q]);
                }
                $subtotal += $qtd * $p['preco'];
                if ($p['estoque'] - $qtd <= $p['estoque_minimo']) {
                    $alertas[] = "{$p['nome']} atingiu o estoque mínimo (restam " . ($p['estoque'] - $qtd) . ").";
                }
            }
            $primeiro = null; // primeiro serviço: os outros têm de ser do mesmo dono
            foreach (Agenda::cobrar($agendamentos, $vendaId) as $sv) {
                if ($clienteInformado && $clienteInformado !== $sv['cliente_id']) throw new DomainException("Este serviço é de outro cliente: {$sv['descricao']} é de {$sv['cliente']}. Escolha esse cliente ou venda sem cliente.");
                if ($primeiro && $primeiro['cliente_id'] !== $sv['cliente_id']) throw new DomainException("Cobre um cliente por venda: {$primeiro['descricao']} é de {$primeiro['cliente']} e {$sv['descricao']} é de {$sv['cliente']}.");
                $primeiro ??= $sv;
                $subtotal += $sv['preco'];
                // venda sem cliente escolhido fica com o dono do pet
                if (!$clienteId) { $clienteId = $sv['cliente_id']; $pdo->prepare('UPDATE vendas SET cliente_id = ? WHERE id = ?')->execute([$clienteId, $vendaId]); }
            }
            $subtotal = round($subtotal, 2);
            $desconto = self::desconto($subtotal, $pag, $descontoMax);
            $total = round($subtotal - $desconto, 2);
            $recebido = null;
            if ($forma === 'dinheiro') {
                $recebido = numero($pag['recebido'] ?? '', 'Valor recebido inválido: digite só números, ex.: 50,00.') ?: $total;
                if ($recebido > 1000000) throw new DomainException('Valor recebido acima do limite de R$ 1.000.000,00.');
                if ($recebido < $total) throw new DomainException('O valor recebido (' . moeda($recebido) . ') é menor que o total (' . moeda($total) . ').');
            }
            $pdo->prepare('UPDATE vendas SET subtotal = ?, desconto = ?, total = ?, valor_recebido = ? WHERE id = ?')->execute([$subtotal, $desconto, $total, $recebido, $vendaId]);
            Auditoria::registrar('venda', "Venda #$vendaId: " . moeda($total) . ' em ' . self::FORMAS[$forma] . ($desconto > 0 ? ', desconto de ' . moeda($desconto) : ''));
            $pdo->commit();
            return ['id' => $vendaId, 'total' => $total, 'alertas' => $alertas];
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    /** Desconto em R$, validado contra o subtotal e o limite de quem vende */
    private static function desconto(float $subtotal, array $pag, float $max): float {
        $v = numero($pag['desconto'] ?? '', 'Desconto inválido: digite só números, ex.: 5,00.');
        if ($v < 0) throw new DomainException('O desconto não pode ser negativo.');
        $desconto = round(($pag['desconto_tipo'] ?? 'valor') === 'percentual' ? $subtotal * $v / 100 : $v, 2);
        if ($desconto >= $subtotal) throw new DomainException('O desconto deve ser menor que o subtotal: a venda não pode ficar em R$ 0,00.');
        if ($desconto > round($subtotal * $max, 2)) {
            throw new DomainException('Desconto acima do permitido para o seu perfil: máximo de ' . round($max * 100) . '% (' . moeda($subtotal * $max) . '). Peça a um administrador.');
        }
        return $desconto;
    }

    /** Venda completa: cabeçalho, itens e devoluções. Null se não existir. */
    public static function buscar(int $id): ?array {
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT v.*, u.nome AS vendedor, c.nome AS cancelado_por_nome, cl.nome AS cliente, cl.telefone AS cliente_telefone FROM vendas v LEFT JOIN usuarios u ON u.id=v.usuario_id LEFT JOIN usuarios c ON c.id=v.cancelado_por LEFT JOIN clientes cl ON cl.id=v.cliente_id WHERE v.id = ?');
        $st->execute([$id]);
        if (!$v = $st->fetch()) return null;
        $st = $pdo->prepare('SELECT vi.*, p.nome, p.codigo_barras FROM venda_itens vi JOIN produtos p ON p.id=vi.produto_id WHERE vi.venda_id = ? ORDER BY vi.id');
        $st->execute([$id]);
        $v['itens'] = $st->fetchAll();
        $st = $pdo->prepare('SELECT d.*, p.nome AS produto, u.nome AS usuario FROM devolucoes d JOIN venda_itens vi ON vi.id=d.venda_item_id JOIN produtos p ON p.id=vi.produto_id LEFT JOIN usuarios u ON u.id=d.usuario_id WHERE d.venda_id = ? ORDER BY d.id');
        $st->execute([$id]);
        $v['devolucoes'] = $st->fetchAll();
        $st = $pdo->prepare('SELECT * FROM venda_servicos WHERE venda_id = ? ORDER BY id');
        $st->execute([$id]);
        $v['servicos'] = $st->fetchAll();
        return $v;
    }

    /**
     * Histórico de vendas com filtros opcionais.
     * @param array $f de, ate (AAAA-MM-DD), produto_id, usuario_id, cliente_id, forma, status ('concluida'|'cancelada')
     */
    public static function listar(array $f = [], int $limite = 50): array {
        $sql = "SELECT v.*, u.nome AS vendedor, CASE WHEN v.status='cancelada' THEN 0 ELSE v.total - v.valor_devolvido END AS liquido,
                (SELECT GROUP_CONCAT(vi.quantidade || 'x ' || p.nome, ', ') FROM venda_itens vi JOIN produtos p ON p.id=vi.produto_id WHERE vi.venda_id=v.id) AS itens,
                (SELECT GROUP_CONCAT(vs.descricao, ', ') FROM venda_servicos vs WHERE vs.venda_id=v.id) AS servicos
                , cl.nome AS cliente FROM vendas v LEFT JOIN usuarios u ON u.id=v.usuario_id LEFT JOIN clientes cl ON cl.id=v.cliente_id
                WHERE 1=1";
        $par = [];
        if (!empty($f['de']))         { $sql .= ' AND v.criado_em >= ?'; $par[] = $f['de']; }
        if (!empty($f['ate']))        { $sql .= " AND v.criado_em <= ? || ' 23:59:59'"; $par[] = $f['ate']; }
        if (!empty($f['usuario_id'])) { $sql .= ' AND v.usuario_id = ?'; $par[] = (int)$f['usuario_id']; }
        if (!empty($f['cliente_id'])) { $sql .= ' AND v.cliente_id = ?'; $par[] = (int)$f['cliente_id']; }
        if (!empty($f['forma']))      { $sql .= ' AND v.forma_pagamento = ?'; $par[] = $f['forma']; }
        if (!empty($f['status']))     { $sql .= ' AND v.status = ?'; $par[] = $f['status']; }
        if (!empty($f['produto_id'])) { $sql .= ' AND EXISTS (SELECT 1 FROM venda_itens x WHERE x.venda_id=v.id AND x.produto_id = ?)'; $par[] = (int)$f['produto_id']; }
        $st = Database::pdo()->prepare($sql . ' ORDER BY v.id DESC LIMIT ' . (int)$limite);
        $st->execute($par);
        // produtos e serviços num texto só para as listas
        return array_map(fn($v) => ['itens' => implode(', ', array_filter([$v['itens'], $v['servicos']]))] + $v, $st->fetchAll());
    }

    /** Lucro bruto da venda (de buscar()): valor líquido menos o custo das unidades que não foram devolvidas */
    public static function lucro(array $v): float {
        if ($v['status'] === 'cancelada') return 0.0;
        $custo = array_sum(array_map(fn($it) => ($it['quantidade'] - $it['quantidade_devolvida']) * $it['custo_unit'], $v['itens']));
        return round($v['total'] - $v['valor_devolvido'] - $custo, 2);
    }

    /** Só o administrador cancela ou devolve. Venda cancelada não muda mais. */
    public static function podeAlterar(array $v, array $usuario): bool {
        if ($v['status'] === 'cancelada') return false;
        return $usuario['perfil'] === 'admin';
    }

    /** Cancela a venda inteira (só o administrador): o que ainda não foi devolvido volta aos lotes. O registro é mantido. */
    public static function cancelar(int $id, array $usuario, string $motivo): void {
        $motivo = trim($motivo);
        if ($motivo === '') throw new DomainException('Informe o motivo do cancelamento.');
        if (mb_strlen($motivo) > 200) throw new DomainException('O motivo deve ter no máximo 200 caracteres.');
        if ($usuario['perfil'] !== 'admin') throw new DomainException('Só o administrador pode cancelar vendas.');
        $pdo = Database::pdo();
        // lê e altera dentro da mesma transação de escrita: dois cancelamentos simultâneos não devolvem o estoque em dobro
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $v = self::buscar($id);
            if (!$v) throw new DomainException('Venda não encontrada.');
            if (!self::podeAlterar($v, $usuario)) throw new DomainException('Você não pode cancelar esta venda.');
            if ($v['forma_pagamento'] === 'dinheiro' && $v['total'] - $v['valor_devolvido'] > 0) {
                if (!Caixa::aberto()) throw new DomainException('Esta venda foi paga em dinheiro: abra o caixa antes de cancelar, para o dinheiro devolvido entrar na conta da gaveta.');
                Caixa::conferirEstorno($v['total'] - $v['valor_devolvido']);
            }
            $st = $pdo->prepare("UPDATE vendas SET status='cancelada', cancelado_em=datetime('now','localtime'), cancelado_por=?, motivo_cancelamento=?, cancelado_caixa_id=? WHERE id=? AND status='concluida'");
            $st->execute([$usuario['id'], $motivo, Caixa::aberto()['id'] ?? null, $id]);
            if ($st->rowCount() !== 1) throw new DomainException('Esta venda já foi cancelada.');
            foreach ($v['itens'] as $it) {
                $resta = $it['quantidade'] - $it['quantidade_devolvida'];
                if ($resta > 0) Estoque::devolverItem((int)$it['id'], (int)$it['produto_id'], $resta, 'cancelamento', $motivo);
            }
            Agenda::estornar($id);
            Auditoria::registrar('venda_cancelada', "Venda #$id de " . moeda($v['total']) . " cancelada. Motivo: $motivo", $usuario);
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    /**
     * Devolução parcial. O valor devolvido de cada item considera o desconto da venda (proporcional).
     * @param array $qtds [venda_item_id => quantidade a devolver]
     * @return float valor total devolvido
     */
    public static function devolver(int $id, array $usuario, array $qtds, string $motivo): float {
        $motivo = trim($motivo);
        if ($motivo === '') throw new DomainException('Informe o motivo da devolução.');
        if (mb_strlen($motivo) > 200) throw new DomainException('O motivo deve ter no máximo 200 caracteres.');
        $pdo = Database::pdo();
        // lê e altera dentro da mesma transação de escrita (ver cancelar)
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $v = self::buscar($id);
            if (!$v) throw new DomainException('Venda não encontrada.');
            if (!self::podeAlterar($v, $usuario)) throw new DomainException('Você não pode registrar devolução nesta venda.');
            $fator = $v['subtotal'] > 0 ? $v['total'] / $v['subtotal'] : 1;
            $caixaId = Caixa::aberto()['id'] ?? null;
            if ($v['forma_pagamento'] === 'dinheiro' && !$caixaId) throw new DomainException('Esta venda foi paga em dinheiro: abra o caixa antes de devolver, para o dinheiro devolvido entrar na conta da gaveta.');
            $soma = 0.0; $unidades = 0;
            foreach ($v['itens'] as $it) {
                $q = (int)($qtds[$it['id']] ?? 0);
                if ($q === 0) continue;
                $unidades += $q;
                $resta = $it['quantidade'] - $it['quantidade_devolvida'];
                if ($q < 0 || $q > $resta) throw new DomainException("Quantidade inválida para {$it['nome']}: só restam $resta para devolver.");
                $st = $pdo->prepare('UPDATE venda_itens SET quantidade_devolvida = quantidade_devolvida + ? WHERE id = ? AND quantidade_devolvida + ? <= quantidade');
                $st->execute([$q, $it['id'], $q]);
                if ($st->rowCount() !== 1) throw new DomainException("Quantidade inválida para {$it['nome']}: só restam $resta para devolver.");
                $valor = round($q * $it['preco_unit'] * $fator, 2);
                Estoque::devolverItem((int)$it['id'], (int)$it['produto_id'], $q, 'devolucao', $motivo);
                $pdo->prepare('INSERT INTO devolucoes (venda_id,venda_item_id,quantidade,valor,motivo,usuario_id,caixa_id) VALUES (?,?,?,?,?,?,?)')->execute([$id, $it['id'], $q, $valor, $motivo, $usuario['id'], $caixaId]);
                $soma += $valor;
            }
            if ($unidades === 0) throw new DomainException('Informe a quantidade de pelo menos um item para devolver.');
            if ($v['forma_pagamento'] === 'dinheiro') Caixa::conferirEstorno($soma, $soma); // as devoluções acima já entraram na conta: desconta de volta
            // devolução de tudo: zera exatamente, sem sobra de centavos por arredondamento
            // (serviço não se devolve: venda com serviço nunca fica "devolvida" por inteiro)
            $pdo->prepare('UPDATE vendas SET valor_devolvido = CASE WHEN (SELECT SUM(quantidade - quantidade_devolvida) FROM venda_itens WHERE venda_id = ?) = 0 AND NOT EXISTS (SELECT 1 FROM venda_servicos WHERE venda_id = ?) THEN total ELSE MIN(total, valor_devolvido + ?) END WHERE id = ?')
                ->execute([$id, $id, $soma, $id]);
            Auditoria::registrar('devolucao', "Venda #$id: $unidades unidade(s) devolvida(s), " . moeda($soma) . " ao cliente. Motivo: $motivo", $usuario);
            $pdo->commit();
            return $soma;
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    /** Rótulo da situação para telas e comprovante */
    public static function situacao(array $v): string {
        if ($v['status'] === 'cancelada') return 'Cancelada';
        if ($v['valor_devolvido'] >= $v['total'] && $v['total'] > 0) return 'Devolvida';
        return $v['valor_devolvido'] > 0 ? 'Devolução parcial' : 'Concluída';
    }
}
