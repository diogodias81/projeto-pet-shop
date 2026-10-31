<?php
class Despesa {
    const CATEGORIAS = ['aluguel' => 'Aluguel', 'energia' => 'Energia', 'agua' => 'Água', 'salarios' => 'Salários', 'fornecedores' => 'Fornecedores', 'impostos' => 'Impostos e taxas', 'manutencao' => 'Manutenção', 'outras' => 'Outras'];

    public static function criar(array $d, int $usuarioId): void {
        $descricao = trim($d['descricao'] ?? '');
        $categoria = $d['categoria'] ?? '';
        $valor = numero($d['valor'] ?? '');
        $data = trim($d['data'] ?? '');
        if ($descricao === '' || mb_strlen($descricao) > 120) throw new DomainException('Informe a descrição da despesa (até 120 caracteres).');
        if (!isset(self::CATEGORIAS[$categoria])) throw new DomainException('Escolha a categoria da despesa.');
        if ($valor <= 0) throw new DomainException('Informe um valor válido, ex.: 1.250,00');
        if ($valor > 10000000) throw new DomainException('Valor acima do limite de R$ 10.000.000,00.');
        $dt = DateTime::createFromFormat('!Y-m-d', $data);
        if (!$dt || $dt->format('Y-m-d') !== $data) throw new DomainException('Informe a data da despesa.');
        // Despesa futura ficaria fora do período padrão da lista (até hoje) e pareceria perdida
        if ($data > date('Y-m-d')) throw new DomainException('A data da despesa não pode ser no futuro.');
        Database::pdo()->prepare('INSERT INTO despesas (descricao,categoria,valor,data,usuario_id) VALUES (?,?,?,?,?)')
            ->execute([$descricao, $categoria, round($valor, 2), $data, $usuarioId]);
        Auditoria::registrar('despesa_criada', "$descricao (" . self::CATEGORIAS[$categoria] . "): " . moeda($valor) . ' em ' . date('d/m/Y', strtotime($data)));
    }

    public static function excluir(int $id): void {
        $st = Database::pdo()->prepare('SELECT descricao, valor FROM despesas WHERE id = ?');
        $st->execute([$id]);
        if (!($d = $st->fetch())) throw new DomainException('Despesa não encontrada.');
        Database::pdo()->prepare('DELETE FROM despesas WHERE id = ?')->execute([$id]);
        Auditoria::registrar('despesa_excluida', $d['descricao'] . ': ' . moeda($d['valor']));
    }

    public static function listar(string $de, string $ate, string $categoria = ''): array {
        $sql = 'SELECT d.*, u.nome AS usuario FROM despesas d LEFT JOIN usuarios u ON u.id = d.usuario_id WHERE d.data BETWEEN ? AND ?';
        $par = [$de, $ate];
        if (isset(self::CATEGORIAS[$categoria])) { $sql .= ' AND d.categoria = ?'; $par[] = $categoria; }
        $st = Database::pdo()->prepare($sql . ' ORDER BY d.data DESC, d.id DESC');
        $st->execute($par);
        return $st->fetchAll();
    }

    /** [categoria => total] no período, da maior para a menor */
    public static function porCategoria(string $de, string $ate): array {
        $st = Database::pdo()->prepare('SELECT categoria, SUM(valor) FROM despesas WHERE data BETWEEN ? AND ? GROUP BY categoria ORDER BY 2 DESC');
        $st->execute([$de, $ate]);
        return array_map('floatval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
    }
}
