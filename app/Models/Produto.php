<?php
class Produto {
    /** RF09/RF10: lista e pesquisa produtos ativos por nome ou categoria */
    public static function listar(string $busca = '', int $categoriaId = 0): array {
        $sql = 'SELECT p.*, c.nome AS categoria FROM produtos p LEFT JOIN categorias c ON c.id = p.categoria_id WHERE p.ativo = 1';
        $par = [];
        if ($busca !== '')   { $sql .= ' AND p.nome LIKE ?'; $par[] = "%$busca%"; }
        if ($categoriaId > 0){ $sql .= ' AND p.categoria_id = ?'; $par[] = $categoriaId; }
        $st = Database::pdo()->prepare($sql . ' ORDER BY p.nome');
        $st->execute($par);
        return $st->fetchAll();
    }
    public static function buscar(int $id): ?array {
        $st = Database::pdo()->prepare('SELECT * FROM produtos WHERE id = ? AND ativo = 1');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }
    /** RF01/RF02: cria (sem id) ou edita (com id). O estoque só muda por entrada/venda, exceto no cadastro inicial. */
    public static function salvar(array $d): void {
        $nome = trim($d['nome'] ?? '');
        $preco = numero($d['preco'] ?? 0);
        $min = (int)($d['estoque_minimo'] ?? 0);
        $cat = (int)($d['categoria_id'] ?? 0) ?: null;
        if ($nome === '') throw new DomainException('Informe o nome do produto.');
        if ($preco < 0)   throw new DomainException('O preço não pode ser negativo.');
        if ($min < 0)     throw new DomainException('O estoque mínimo não pode ser negativo.');
        $pdo = Database::pdo();
        if (!empty($d['id'])) {
            $pdo->prepare('UPDATE produtos SET nome=?, categoria_id=?, preco=?, estoque_minimo=? WHERE id=?')
                ->execute([$nome, $cat, $preco, $min, (int)$d['id']]);
        } else {
            $inicial = max(0, (int)($d['estoque'] ?? 0));
            $pdo->prepare('INSERT INTO produtos (nome,categoria_id,preco,estoque,estoque_minimo) VALUES (?,?,?,?,?)')
                ->execute([$nome, $cat, $preco, $inicial, $min]);
        }
    }
    /** Exclusão lógica: preserva o histórico de vendas */
    public static function excluir(int $id): void {
        Database::pdo()->prepare('UPDATE produtos SET ativo = 0 WHERE id = ?')->execute([$id]);
    }
    /** RF08/RF15: produtos com estoque igual ou abaixo do mínimo */
    public static function baixoEstoque(): array {
        return Database::pdo()->query('SELECT p.*, c.nome AS categoria FROM produtos p LEFT JOIN categorias c ON c.id=p.categoria_id WHERE p.ativo=1 AND p.estoque <= p.estoque_minimo ORDER BY p.estoque')->fetchAll();
    }
}
