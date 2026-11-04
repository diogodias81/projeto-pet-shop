<?php
class Despesa {
    const CATEGORIAS = ['aluguel' => 'Aluguel', 'energia' => 'Energia', 'agua' => 'Água', 'salarios' => 'Salários', 'fornecedores' => 'Fornecedores', 'impostos' => 'Impostos e taxas', 'manutencao' => 'Manutenção', 'outras' => 'Outras'];
    /** Data mais antiga aceita numa despesa (servidor e min do formulário) */
    const DATA_MINIMA = '2000-01-01';
    /** Ícone (Bootstrap Icons) de cada categoria, para as telas */
    const ICONES = ['aluguel' => 'house-door', 'energia' => 'lightning-charge', 'agua' => 'droplet', 'salarios' => 'people', 'fornecedores' => 'truck', 'impostos' => 'bank', 'manutencao' => 'tools', 'outras' => 'three-dots'];

    /**
     * Cria (sem id) ou edita (com id). Quem registrou a despesa continua o mesmo ao editar.
     * @return bool se algo mudou (edição sem mudança não grava nem vai para a auditoria)
     */
    public static function salvar(array $d, int $usuarioId): bool {
        $id = (int) ($d['id'] ?? 0);
        if (!$id) { self::criar($d, $usuarioId); return true; }
        [$descricao, $categoria, $valor, $data] = self::validar($d);
        $pdo = Database::pdo();
        // O antes é lido na mesma transação do UPDATE: duas edições ao mesmo tempo não deixam um antes errado na auditoria
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $st = $pdo->prepare('SELECT descricao, categoria, valor, data FROM despesas WHERE id = ?');
            $st->execute([$id]);
            $antes = $st->fetch();
            $st->closeCursor(); // ver excluir
            if (!$antes) throw new DomainException('Despesa não encontrada.');
            $mudou = $antes['descricao'] !== $descricao || $antes['categoria'] !== $categoria || round((float) $antes['valor'], 2) !== round($valor, 2) || $antes['data'] !== $data;
            if ($mudou) {
                $pdo->prepare('UPDATE despesas SET descricao = ?, categoria = ?, valor = ?, data = ? WHERE id = ?')
                    ->execute([$descricao, $categoria, round($valor, 2), $data, $id]);
                // o antes e o depois de tudo: valor de despesa mexe no resultado do mês
                Auditoria::registrar('despesa_alterada', "$descricao (#$id): " . moeda($antes['valor']) . ' em ' . date('d/m/Y', strtotime($antes['data']))
                    . ' → ' . moeda($valor) . ' em ' . date('d/m/Y', strtotime($data))
                    . ($antes['categoria'] !== $categoria ? ', categoria ' . self::CATEGORIAS[$antes['categoria']] . ' → ' . self::CATEGORIAS[$categoria] : '')
                    . ($antes['descricao'] !== $descricao ? ', descrição "' . $antes['descricao'] . '" → "' . $descricao . '"' : ''));
            }
            $pdo->commit();
            return $mudou;
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    }

    public static function criar(array $d, int $usuarioId): void {
        [$descricao, $categoria, $valor, $data] = self::validar($d);
        Database::pdo()->prepare('INSERT INTO despesas (descricao,categoria,valor,data,usuario_id) VALUES (?,?,?,?,?)')
            ->execute([$descricao, $categoria, round($valor, 2), $data, $usuarioId]);
        Auditoria::registrar('despesa_criada', "$descricao (" . self::CATEGORIAS[$categoria] . "): " . moeda($valor) . ' em ' . date('d/m/Y', strtotime($data)));
    }

    /** [descrição, categoria, valor, data] já validados (mesmas regras no cadastro e na edição) */
    private static function validar(array $d): array {
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
        // Ano digitado errado (0025, 1925) some dos períodos; nenhuma conta da loja é anterior a 2000
        if ($data < self::DATA_MINIMA) throw new DomainException('A data da despesa não pode ser anterior a 01/01/2000.');
        return [$descricao, $categoria, $valor, $data];
    }

    public static function buscar(int $id): ?array {
        $st = Database::pdo()->prepare('SELECT * FROM despesas WHERE id = ?');
        $st->execute([$id]);
        $d = $st->fetch();
        $st->closeCursor(); // ver excluir
        return $d ?: null;
    }

    public static function excluir(int $id): void {
        $st = Database::pdo()->prepare('SELECT descricao, valor FROM despesas WHERE id = ?');
        $st->execute([$id]);
        $d = $st->fetch();
        $st->closeCursor(); // cursor aberto faz a gravação seguinte falhar na hora se outro processo grava (ver bootstrap)
        if (!$d) throw new DomainException('Despesa não encontrada.');
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
