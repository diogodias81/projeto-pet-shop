<?php
class Categoria {
    public static function listar(): array {
        return Database::pdo()->query('SELECT c.*, (SELECT COUNT(*) FROM produtos p WHERE p.categoria_id=c.id AND p.ativo=1) AS qtd_produtos FROM categorias c ORDER BY nome COLLATE NOCASE')->fetchAll();
    }
    public static function criar(string $nome): void {
        $nome = trim($nome);
        if ($nome === '') throw new DomainException('Informe o nome da categoria.');
        // NOCASE do SQLite só ignora maiúsculas em ASCII; acentos (Ç, Ã) comparados aqui no PHP
        $existentes = array_map('mb_strtolower', Database::pdo()->query('SELECT nome FROM categorias')->fetchAll(PDO::FETCH_COLUMN));
        if (in_array(mb_strtolower($nome), $existentes, true)) throw new DomainException('Categoria já cadastrada.');
        try { Database::pdo()->prepare('INSERT INTO categorias (nome) VALUES (?)')->execute([$nome]); Auditoria::registrar('categoria_criada', $nome); }
        catch (PDOException $e) { throw new DomainException('Categoria já cadastrada.'); }
    }
    public static function excluir(int $id): void {
        $st = Database::pdo()->prepare('SELECT COUNT(*) FROM produtos WHERE categoria_id = ? AND ativo = 1');
        $st->execute([$id]);
        $emUso = $st->fetchColumn();
        $st->closeCursor(); // cursor aberto faz a gravação seguinte falhar na hora se outro processo grava (ver bootstrap)
        if ($emUso > 0) throw new DomainException('Existem produtos nesta categoria. Mova-os antes de excluir.');
        // produtos excluídos (ativo=0) continuam na tabela apontando para a categoria e travariam a chave estrangeira
        Database::pdo()->prepare('UPDATE produtos SET categoria_id = NULL WHERE categoria_id = ? AND ativo = 0')->execute([$id]);
        $st = Database::pdo()->prepare('SELECT nome FROM categorias WHERE id = ?');
        $st->execute([$id]);
        $nome = $st->fetchColumn();
        $st->closeCursor();
        if (!$nome) throw new DomainException('Categoria não encontrada.');
        Database::pdo()->prepare('DELETE FROM categorias WHERE id = ?')->execute([$id]);
        Auditoria::registrar('categoria_excluida', $nome);
    }
}
