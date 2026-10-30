<?php
class Relatorio {
    /** Quanto a venda rendeu de fato: canceladas valem zero e devoluções são descontadas */
    private const LIQUIDO = "CASE WHEN status='cancelada' THEN 0 ELSE total - valor_devolvido END";

    private static function um(string $sql, array $par = []) {
        $st = Database::pdo()->prepare($sql); $st->execute($par); return $st->fetchColumn();
    }

    /** Faturamento líquido entre duas datas (inclusive) */
    public static function faturamento(string $de, string $ate): float {
        return (float)self::um("SELECT COALESCE(SUM(" . self::LIQUIDO . "),0) FROM vendas WHERE date(criado_em) BETWEEN ? AND ?", [$de, $ate]);
    }

    /**
     * Indicadores do período. Custo = custo das unidades vendidas e não devolvidas (custo 0 quando não foi cadastrado).
     * Lucro bruto = faturamento - custo; resultado = lucro bruto - despesas.
     */
    public static function periodo(string $de, string $ate): array {
        $fat = self::faturamento($de, $ate);
        $vendas = (int)self::um("SELECT COUNT(*) FROM vendas WHERE status='concluida' AND NOT (total > 0 AND valor_devolvido >= total) AND date(criado_em) BETWEEN ? AND ?", [$de, $ate]);
        $custo = (float)self::um("SELECT COALESCE(SUM((vi.quantidade - vi.quantidade_devolvida) * vi.custo_unit),0) FROM venda_itens vi JOIN vendas v ON v.id=vi.venda_id WHERE v.status='concluida' AND date(v.criado_em) BETWEEN ? AND ?", [$de, $ate]);
        $despesas = (float)self::um('SELECT COALESCE(SUM(valor),0) FROM despesas WHERE data BETWEEN ? AND ?', [$de, $ate]);
        $lucro = round($fat - $custo, 2);
        return [
            'faturamento' => $fat,
            'vendas' => $vendas,
            'ticket' => $vendas ? $fat / $vendas : 0,
            'custo' => $custo,
            'lucro' => $lucro,
            'margem' => $fat > 0 ? $lucro / $fat * 100 : null,
            'despesas' => $despesas,
            'resultado' => round($lucro - $despesas, 2),
        ];
    }

    /**
     * Produtos vendidos no período (sem vendas canceladas e sem unidades devolvidas), do mais vendido para o menos.
     * Receita já com o desconto da venda; custo pelo preço de custo gravado no momento da venda.
     */
    public static function maisVendidos(int $limite = 5, ?string $de = null, ?string $ate = null): array {
        $st = Database::pdo()->prepare("SELECT p.nome, SUM(vi.quantidade - vi.quantidade_devolvida) AS qtd,
                SUM((vi.quantidade - vi.quantidade_devolvida) * vi.preco_unit * CASE WHEN v.subtotal > 0 THEN v.total / v.subtotal ELSE 1 END) AS receita,
                SUM((vi.quantidade - vi.quantidade_devolvida) * vi.custo_unit) AS custo,
                MIN(vi.custo_unit) = 0 AS sem_custo
            FROM venda_itens vi JOIN vendas v ON v.id=vi.venda_id JOIN produtos p ON p.id=vi.produto_id
            WHERE v.status = 'concluida' AND date(v.criado_em) BETWEEN ? AND ? GROUP BY p.id HAVING qtd > 0 ORDER BY qtd DESC, receita DESC LIMIT " . (int)$limite);
        $st->execute([$de ?? '0000-01-01', $ate ?? '9999-12-31']);
        return $st->fetchAll();
    }

    /** Faturamento por dia no período; acima de 62 dias agrupa por mês. [rótulo => total], períodos sem venda valem zero. */
    public static function serie(string $de, string $ate): array {
        $porMes = (strtotime($ate) - strtotime($de)) / 86400 > 62;
        $fmt = $porMes ? '%Y-%m' : '%Y-%m-%d';
        $st = Database::pdo()->prepare("SELECT strftime('$fmt', criado_em) AS k, SUM(" . self::LIQUIDO . ") FROM vendas WHERE date(criado_em) BETWEEN ? AND ? GROUP BY k");
        $st->execute([$de, $ate]);
        $mapa = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        $r = [];
        for ($t = strtotime($porMes ? date('Y-m-01', strtotime($de)) : $de); $t <= strtotime($ate); $t = strtotime($porMes ? '+1 month' : '+1 day', $t)) {
            $k = date($porMes ? 'Y-m' : 'Y-m-d', $t);
            $r[date($porMes ? 'm/Y' : 'd/m', $t)] = (float)($mapa[$k] ?? 0);
        }
        return $r;
    }

    /** Movimentações de estoque do período, da mais recente para a mais antiga */
    public static function movimentacoes(string $de, string $ate): array {
        $st = Database::pdo()->prepare('SELECT m.*, p.nome AS produto, l.codigo AS lote, u.nome AS usuario FROM movimentacoes_estoque m JOIN produtos p ON p.id = m.produto_id LEFT JOIN lotes l ON l.id = m.lote_id LEFT JOIN usuarios u ON u.id = m.usuario_id WHERE date(m.criado_em) BETWEEN ? AND ? ORDER BY m.id DESC');
        $st->execute([$de, $ate]);
        return $st->fetchAll();
    }

    /** Período pronto pelo atalho (hoje, semana, mes) ou pelas datas digitadas. Datas inválidas viram o mês atual. */
    public static function datas(array $q): array {
        $hoje = date('Y-m-d');
        $atalhos = ['hoje' => [$hoje, $hoje], 'semana' => [date('Y-m-d', strtotime('-6 days')), $hoje], 'mes' => [date('Y-m-01'), $hoje]];
        if (isset($atalhos[$q['periodo'] ?? ''])) return $atalhos[$q['periodo']];
        $valida = fn($d) => is_string($d) && ($dt = DateTime::createFromFormat('!Y-m-d', $d)) && $dt->format('Y-m-d') === $d;
        return [$valida($q['de'] ?? null) ? $q['de'] : date('Y-m-01'), $valida($q['ate'] ?? null) ? $q['ate'] : $hoje];
    }
}
