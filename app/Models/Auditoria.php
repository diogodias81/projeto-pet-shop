<?php
/** Registro das operações importantes: quem fez, o quê, quando e de qual IP. Nunca é alterado nem apagado pela tela. */
class Auditoria {
    const ACOES = [
        'login' => 'Entrada no sistema', 'login_bloqueado' => 'Login bloqueado',
        'venda' => 'Venda', 'venda_cancelada' => 'Cancelamento de venda', 'devolucao' => 'Devolução',
        'produto_criado' => 'Produto cadastrado', 'produto_alterado' => 'Produto alterado', 'preco_alterado' => 'Alteração de preço', 'produto_excluido' => 'Produto excluído',
        'estoque_entrada' => 'Entrada de estoque', 'estoque_baixa' => 'Baixa de estoque',
        'usuario_criado' => 'Usuário criado', 'usuario_excluido' => 'Usuário excluído', 'usuario_desativado' => 'Usuário desativado', 'usuario_reativado' => 'Usuário reativado','senha_alterada' => 'Senha alterada', 'senha_redefinida' => 'Senha redefinida',
        'categoria_criada' => 'Categoria criada', 'categoria_excluida' => 'Categoria excluída',
        'despesa_criada' => 'Despesa registrada', 'despesa_excluida' => 'Despesa excluída',
    ];

    /** Grava junto da operação (dentro da mesma transação, quando houver). Guarda o nome do usuário para o registro sobreviver à exclusão dele. */
    public static function registrar(string $acao, string $detalhes = '', ?array $usuario = null): void {
        $usuario ??= usuario();
        Database::pdo()->prepare('INSERT INTO auditoria (usuario_id, usuario_nome, acao, detalhes, ip) VALUES (?,?,?,?,?)')
            ->execute([$usuario['id'] ?? null, $usuario['nome'] ?? null, $acao, mb_substr($detalhes, 0, 500), $_SERVER['REMOTE_ADDR'] ?? '']);
    }

    public static function listar(string $de, string $ate, string $acao = '', int $usuarioId = 0, int $limite = 500): array {
        $sql = 'SELECT * FROM auditoria WHERE date(criado_em) BETWEEN ? AND ?';
        $par = [$de, $ate];
        if (isset(self::ACOES[$acao])) { $sql .= ' AND acao = ?'; $par[] = $acao; }
        if ($usuarioId) { $sql .= ' AND usuario_id = ?'; $par[] = $usuarioId; }
        $st = Database::pdo()->prepare($sql . ' ORDER BY id DESC LIMIT ' . (int)$limite);
        $st->execute($par);
        return $st->fetchAll();
    }
}
