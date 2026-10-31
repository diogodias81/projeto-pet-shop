<?php
class Usuario {
    const PERFIS = ['admin' => 'Administrador', 'vendedor' => 'Vendedor', 'estoque' => 'Resp. estoque', 'financeiro' => 'Financeiro'];

    /** Hash de uma senha aleatória: conferido quando o e-mail não existe, para o tempo de resposta não revelar quais e-mails existem */
    private const HASH_FALSO = '$2y$12$7kxjyA9bt0SzYxF3LNi.YO1T9hhXVqZMvf3u6MRT6FJQNI8oK1AuS';

    /**
     * Bloqueia por 15 minutos após 5 erros no mesmo e-mail vindos do mesmo IP, ou 100 erros no mesmo IP.
     * O limite por IP é alto de propósito: na loja todos saem pelo mesmo roteador (no Docker, pelo IP do gateway),
     * então ele só pega ataque em massa contra vários e-mails, não colegas errando a senha.
     * shortcut: usa REMOTE_ADDR; atrás de proxy reverso todos viram o IP do proxy — aí passe o IP real (X-Forwarded-For só do proxy confiável).
     */
    public static function autenticar(string $email, string $senha, string $ip = ''): ?array {
        $email = trim($email);
        // 254 = maior e-mail válido; senha gigante só serve para encher o banco/gastar CPU
        if (strlen($email) > 254 || mb_strlen($senha) > 1024) throw new DomainException('E-mail ou senha incorretos.');
        $chave = mb_strtolower($email);
        $pdo = Database::pdo();
        // Conta e registra a tentativa numa transação só: requisições em paralelo não passam do limite
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $st = $pdo->prepare("SELECT COALESCE(SUM(email = ? AND ip = ?), 0), COALESCE(SUM(ip = ?), 0) FROM tentativas_login WHERE criado_em > datetime('now', '-15 minutes')");
            $st->execute([$chave, $ip, $ip]);
            [$porEmail, $porIp] = $st->fetch(PDO::FETCH_NUM);
            $bloqueado = $porEmail >= 5 || $porIp >= 100;
            if ($bloqueado) {
                $detalhes = $porIp >= 100 ? 'Excesso de tentativas pelo IP' : "E-mail $chave";
                // Uma linha por bloqueio a cada 15 min, não uma por requisição bloqueada
                $st = $pdo->prepare("SELECT 1 FROM auditoria WHERE acao = 'login_bloqueado' AND ip = ? AND detalhes = ? AND criado_em > datetime('now', 'localtime', '-15 minutes')");
                $st->execute([$ip, $detalhes]);
                if (!$st->fetchColumn()) {
                    error_log('Login bloqueado por excesso de tentativas: ' . preg_replace('/[\x00-\x1F\x7F]/', '', "$detalhes ip=$ip"));
                    Auditoria::registrar('login_bloqueado', $detalhes);
                }
                // Libera quando a tentativa mais antiga da janela sai dos 15 min
                $st = $pdo->prepare("SELECT (strftime('%s', MIN(criado_em), '+15 minutes') - strftime('%s', 'now') + 59) / 60 FROM tentativas_login WHERE criado_em > datetime('now', '-15 minutes') AND ip = ?" . ($porIp >= 100 ? '' : ' AND email = ?'));
                $st->execute($porIp >= 100 ? [$ip] : [$ip, $chave]);
                $minutos = max(1, (int)$st->fetchColumn());

            } else {
                $pdo->prepare('INSERT INTO tentativas_login (email, ip) VALUES (?, ?)')->execute([$chave, $ip]);
                $pdo->exec("DELETE FROM tentativas_login WHERE criado_em < datetime('now', '-1 day')");
            }
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        if ($bloqueado) throw new DomainException("Muitas tentativas de acesso. Aguarde $minutos minuto" . ($minutos > 1 ? 's' : '') . ' e tente novamente.');

        $st = $pdo->prepare('SELECT * FROM usuarios WHERE lower(email) = ?');
        $st->execute([$chave]);
        $u = $st->fetch();
        if (password_verify($senha, $u['senha_hash'] ?? self::HASH_FALSO) && $u) {
            $pdo->prepare('DELETE FROM tentativas_login WHERE email = ? AND ip = ?')->execute([$chave, $ip]);
            Auditoria::registrar('login', $u['email'], $u);
            return $u;
        }
        return null;
    }
    /** Conta caracteres (não bytes), igual ao minlength/maxlength do formulário */
    private static function validarSenha(string $senha): void {
        if (trim($senha) === '') throw new DomainException('A senha não pode ser só espaços.');
        if (mb_strlen($senha) < 6 || mb_strlen($senha) > 1024) throw new DomainException('A senha deve ter entre 6 e 1024 caracteres.');
    }
    /** Primeiro acesso com a senha inicial: o usuário escolhe a própria antes de usar o sistema */
    public static function trocarSenha(int $id, string $senha, string $confirmacao): void {
        self::validarSenha($senha);
        if ($senha !== $confirmacao) throw new DomainException('A confirmação não confere com a nova senha.');
        $st = Database::pdo()->prepare('SELECT senha_hash FROM usuarios WHERE id = ?');
        $st->execute([$id]);
        if (password_verify($senha, (string) $st->fetchColumn())) throw new DomainException('A nova senha deve ser diferente da senha inicial.');
        Database::pdo()->prepare('UPDATE usuarios SET senha_hash = ?, trocar_senha = 0 WHERE id = ?')->execute([password_hash($senha, PASSWORD_DEFAULT), $id]);
        Auditoria::registrar('senha_alterada');
    }
    /** Tela "Minha senha": pede a atual para ninguém trocar a senha num computador esquecido logado */
    public static function alterarSenha(int $id, string $atual, string $nova, string $confirmacao): void {
        $st = Database::pdo()->prepare('SELECT senha_hash FROM usuarios WHERE id = ?');
        $st->execute([$id]);
        if (mb_strlen($atual) > 1024 || !password_verify($atual, (string) $st->fetchColumn())) throw new DomainException('A senha atual está incorreta.');
        self::validarSenha($nova);
        if ($nova !== $confirmacao) throw new DomainException('A confirmação não confere com a nova senha.');
        if ($nova === $atual) throw new DomainException('A nova senha deve ser diferente da atual.');
        Database::pdo()->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?')->execute([password_hash($nova, PASSWORD_DEFAULT), $id]);
        Auditoria::registrar('senha_alterada');
    }
    /** Admin define uma senha provisória (ex.: o usuário esqueceu a dele); o usuário troca no próximo acesso */
    public static function redefinirSenha(int $id, string $senha): void {
        self::validarSenha($senha);
        $st = Database::pdo()->prepare('SELECT nome, email FROM usuarios WHERE id = ?');
        $st->execute([$id]);
        if (!($alvo = $st->fetch())) throw new DomainException('Usuário não encontrado.');
        Database::pdo()->prepare('UPDATE usuarios SET senha_hash = ?, trocar_senha = 1 WHERE id = ?')->execute([password_hash($senha, PASSWORD_DEFAULT), $id]);
        Auditoria::registrar('senha_redefinida', "{$alvo['nome']} <{$alvo['email']}>");
    }
    public static function listar(): array {
        return Database::pdo()->query('SELECT id,nome,email,perfil FROM usuarios ORDER BY nome')->fetchAll();
    }
    public static function criar(array $d): void {
        $nome = trim($d['nome'] ?? ''); $email = mb_strtolower(trim($d['email'] ?? ''));
        if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new DomainException('Informe nome e e-mail válidos.');
        self::validarSenha($d['senha'] ?? '');
        if (!isset(self::PERFIS[$d['perfil'] ?? ''])) throw new DomainException('Perfil inválido.');
        // e-mails antigos podem ter maiúsculas: compara sem diferenciar
        $st = Database::pdo()->prepare('SELECT 1 FROM usuarios WHERE lower(email) = ?');
        $st->execute([$email]);
        if ($st->fetchColumn()) throw new DomainException('Este e-mail já está cadastrado.');
        try {
            Database::pdo()->prepare('INSERT INTO usuarios (nome,email,senha_hash,perfil,trocar_senha) VALUES (?,?,?,?,1)')
                ->execute([$nome, $email, password_hash($d['senha'], PASSWORD_DEFAULT), $d['perfil']]);
            Auditoria::registrar('usuario_criado', "$nome <$email>, perfil " . self::PERFIS[$d['perfil']]);
        } catch (PDOException $e) { throw new DomainException('Este e-mail já está cadastrado.'); }
    }
    public static function excluir(int $id): void {
        if ($id === (usuario()['id'] ?? 0)) throw new DomainException('Você não pode excluir seu próprio usuário.');
        $st = Database::pdo()->prepare('SELECT nome, email FROM usuarios WHERE id = ?');
        $st->execute([$id]);
        if (!($alvo = $st->fetch())) throw new DomainException('Usuário não encontrado.');
        try {
            Database::pdo()->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
            Auditoria::registrar('usuario_excluido', "{$alvo['nome']} <{$alvo['email']}>");
        } catch (PDOException $e) { throw new DomainException('Usuário possui vendas ou movimentações de estoque registradas e não pode ser excluído.'); }
    }
}
