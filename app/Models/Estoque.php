<?php
class Estoque {
    /** registra entrada e soma ao estoque */
    public static function entrada(int $produtoId, int $qtd, string $fornecedor): void {
        if (!Produto::buscar($produtoId)) throw new DomainException('Produto não encontrado.');
        if ($qtd < 1) throw new DomainException('A quantidade deve ser maior que zero.');
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO entradas_estoque (produto_id,quantidade,fornecedor) VALUES (?,?,?)')->execute([$produtoId, $qtd, trim($fornecedor)]);
            $pdo->prepare('UPDATE produtos SET estoque = estoque + ? WHERE id = ?')->execute([$qtd, $produtoId]);
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    }
    public static function historico(int $limite = 15): array {
        return Database::pdo()->query("SELECT e.*, p.nome AS produto FROM entradas_estoque e JOIN produtos p ON p.id=e.produto_id ORDER BY e.id DESC LIMIT " . (int)$limite)->fetchAll();
    }
}
