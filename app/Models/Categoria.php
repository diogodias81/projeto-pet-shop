<?php
class Categoria {
    public static function listar(): array {
        return Database::pdo()->query('SELECT c.*, (SELECT COUNT(*) FROM produtos p WHERE p.categoria_id=c.id AND p.ativo=1) AS qtd_produtos FROM categorias c ORDER BY nome')->fetchAll();
    }
    public static function criar(string $nome): void {
        $nome = trim($nome);
        if ($nome === '') throw new DomainException('Informe o nome da categoria.');
        try { Database::pdo()->prepare('INSERT INTO categorias (nome) VALUES (?)')->execute([$nome]); }
        catch (PDOException $e) { throw new DomainException('Categoria já cadastrada.'); }
    }
    public static function excluir(int $id): void {
        $st = Database::pdo()->prepare('SELECT COUNT(*) FROM produtos WHERE categoria_id = ? AND ativo = 1');
        $st->execute([$id]);
        if ($st->fetchColumn() > 0) throw new DomainException('Existem produtos nesta categoria. Mova-os antes de excluir.');
        Database::pdo()->prepare('DELETE FROM categorias WHERE id = ?')->execute([$id]);
    }
}
