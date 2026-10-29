<?php
class Relatorio {
    /** Quanto a venda rendeu de fato: canceladas valem zero e devoluções são descontadas */
    private const LIQUIDO = "CASE WHEN status='cancelada' THEN 0 ELSE total - valor_devolvido END";

    private static function um(string $sql, array $par = []) {
        $st = Database::pdo()->prepare($sql); $st->execute($par); return $st->fetchColumn();
    }
    //indicadores do dashboard */
    public static function resumo(): array {
        $hoje = date('Y-m-d'); $mes = date('Y-m');
        return [
            'faturamento_hoje' => (float)self::um("SELECT COALESCE(SUM(" . self::LIQUIDO . "),0) FROM vendas WHERE date(criado_em)=?", [$hoje]),
            'vendas_hoje'      => (int)self::um("SELECT COUNT(*) FROM vendas WHERE status='concluida' AND NOT (total > 0 AND valor_devolvido >= total) AND date(criado_em)=?", [$hoje]),
            'faturamento_mes'  => (float)self::um("SELECT COALESCE(SUM(" . self::LIQUIDO . "),0) FROM vendas WHERE strftime('%Y-%m',criado_em)=?", [$mes]),
            'baixo_estoque'    => count(Produto::baixoEstoque()),
        ];
    }
    /**  produtos mais vendidos no período (sem vendas canceladas e sem unidades devolvidas; receita já com o desconto da venda) */
    public static function maisVendidos(int $limite = 5, ?string $de = null, ?string $ate = null): array {
        $st = Database::pdo()->prepare("SELECT p.nome, SUM(vi.quantidade - vi.quantidade_devolvida) AS qtd,
                SUM((vi.quantidade - vi.quantidade_devolvida) * vi.preco_unit * CASE WHEN v.subtotal > 0 THEN v.total / v.subtotal ELSE 1 END) AS receita
            FROM venda_itens vi JOIN vendas v ON v.id=vi.venda_id JOIN produtos p ON p.id=vi.produto_id
            WHERE v.status = 'concluida' AND date(v.criado_em) BETWEEN ? AND ? GROUP BY p.id HAVING qtd > 0 ORDER BY qtd DESC LIMIT " . (int)$limite);
        $st->execute([$de ?? '0000-01-01', $ate ?? '9999-12-31']);
        return $st->fetchAll();
    }
    /** Faturamento dos últimos N dias (preenche dias sem venda com zero) */
    public static function faturamentoPorDia(int $dias = 7): array {
        $st = Database::pdo()->prepare("SELECT date(criado_em) AS dia, SUM(" . self::LIQUIDO . ") AS total FROM vendas WHERE date(criado_em) >= ? GROUP BY dia");
        $st->execute([date('Y-m-d', strtotime('-' . ($dias - 1) . ' days'))]);
        $mapa = array_column($st->fetchAll(), 'total', 'dia');
        $r = [];
        for ($i = $dias - 1; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i days")); $r[$d] = (float)($mapa[$d] ?? 0); }
        return $r;
    }
}
