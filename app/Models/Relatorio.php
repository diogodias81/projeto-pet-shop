<?php
class Relatorio {
    private static function um(string $sql, array $par = []) {
        $st = Database::pdo()->prepare($sql); $st->execute($par); return $st->fetchColumn();
    }
    /** RF13: indicadores do dashboard */
    public static function resumo(): array {
        $hoje = date('Y-m-d'); $mes = date('Y-m');
        return [
            'faturamento_hoje' => (float)self::um("SELECT COALESCE(SUM(total),0) FROM vendas WHERE date(criado_em)=?", [$hoje]),
            'vendas_hoje'      => (int)self::um("SELECT COUNT(*) FROM vendas WHERE date(criado_em)=?", [$hoje]),
            'faturamento_mes'  => (float)self::um("SELECT COALESCE(SUM(total),0) FROM vendas WHERE strftime('%Y-%m',criado_em)=?", [$mes]),
            'baixo_estoque'    => count(Produto::baixoEstoque()),
        ];
    }
    /** RF14: produtos mais vendidos no período */
    public static function maisVendidos(int $limite = 5, ?string $de = null, ?string $ate = null): array {
        $st = Database::pdo()->prepare("SELECT p.nome, SUM(vi.quantidade) AS qtd, SUM(vi.quantidade*vi.preco_unit) AS receita
            FROM venda_itens vi JOIN vendas v ON v.id=vi.venda_id JOIN produtos p ON p.id=vi.produto_id
            WHERE date(v.criado_em) BETWEEN ? AND ? GROUP BY p.id ORDER BY qtd DESC LIMIT " . (int)$limite);
        $st->execute([$de ?? '0000-01-01', $ate ?? '9999-12-31']);
        return $st->fetchAll();
    }
    /** Faturamento dos últimos N dias (preenche dias sem venda com zero) */
    public static function faturamentoPorDia(int $dias = 7): array {
        $st = Database::pdo()->prepare("SELECT date(criado_em) AS dia, SUM(total) AS total FROM vendas WHERE date(criado_em) >= ? GROUP BY dia");
        $st->execute([date('Y-m-d', strtotime('-' . ($dias - 1) . ' days'))]);
        $mapa = array_column($st->fetchAll(), 'total', 'dia');
        $r = [];
        for ($i = $dias - 1; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i days")); $r[$d] = (float)($mapa[$d] ?? 0); }
        return $r;
    }
}
