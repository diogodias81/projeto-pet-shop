<?php
class Usuario {
    const PERFIS = ['admin' => 'Administrador', 'vendedor' => 'Vendedor', 'estoque' => 'Resp. estoque', 'financeiro' => 'Financeiro'];

    public static function autenticar(string $email, string $senha): ?array {
        $st = Database::pdo()->prepare('SELECT * FROM usuarios WHERE email = ?');
        $st->execute([trim($email)]);
        $u = $st->fetch();
        return ($u && password_verify($senha, $u['senha_hash'])) ? $u : null;
    }
    public static function listar(): array {
        return Database::pdo()->query('SELECT id,nome,email,perfil FROM usuarios ORDER BY nome')->fetchAll();
    }
    public static function criar(array $d): void {
        $nome = trim($d['nome'] ?? ''); $email = trim($d['email'] ?? '');
        if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new DomainException('Informe nome e e-mail válidos.');
        if (strlen($d['senha'] ?? '') < 6) throw new DomainException('A senha deve ter ao menos 6 caracteres.');
        if (!isset(self::PERFIS[$d['perfil'] ?? ''])) throw new DomainException('Perfil inválido.');
        try {
            Database::pdo()->prepare('INSERT INTO usuarios (nome,email,senha_hash,perfil) VALUES (?,?,?,?)')
                ->execute([$nome, $email, password_hash($d['senha'], PASSWORD_DEFAULT), $d['perfil']]);
        } catch (PDOException $e) { throw new DomainException('Este e-mail já está cadastrado.'); }
    }
    public static function excluir(int $id): void {
        if ($id === (usuario()['id'] ?? 0)) throw new DomainException('Você não pode excluir seu próprio usuário.');
        try {
            Database::pdo()->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
        } catch (PDOException $e) { throw new DomainException('Usuário possui vendas registradas e não pode ser excluído.'); }
    }
}
