<?php
class Usuario {
    // Ordem dos cartões em Usuários: o perfil de mais poder por último (era o primeiro e o mais fácil de marcar sem querer)
    const SENHA_MIN = 12;
    const PERFIS = ['vendedor' => 'Vendedor', 'estoque' => 'Resp. estoque', 'financeiro' => 'Financeiro', 'admin' => 'Administrador'];

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
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        if ($bloqueado) throw new DomainException("Muitas tentativas de acesso. Aguarde $minutos minuto" . ($minutos > 1 ? 's' : '') . ' e tente novamente.');

        $st = $pdo->prepare('SELECT * FROM usuarios WHERE lower(email) = ?');
        $st->execute([$chave]);
        $u = $st->fetch();
        // Cursor de uma linha fica aberto até o closeCursor; aberto, a gravação seguinte dá "database is locked" na hora se outro processo grava (ver bootstrap)
        $st->closeCursor();
        if (password_verify($senha, $u['senha_hash'] ?? self::HASH_FALSO) && $u) {
            // Só quem sabe a senha descobre que a conta foi desativada
            if (!$u['ativo']) throw new DomainException('Este usuário foi desativado. Fale com o administrador.');
            $pdo->prepare('DELETE FROM tentativas_login WHERE email = ? AND ip = ?')->execute([$chave, $ip]);
            // Hash de custo mais baixo que o padrão do PHP (atualização do PHP, senha antiga): regrava com o custo atual.
            // Além de mais forte, o login de quem existe volta a levar o mesmo tempo do HASH_FALSO (sem revelar e-mails).
            // Não mexe na sessao_versao: é a mesma senha, ninguém precisa ser deslogado.
            if (password_needs_rehash($u['senha_hash'], PASSWORD_DEFAULT)) {
                $u['senha_hash'] = password_hash($senha, PASSWORD_DEFAULT);
                $pdo->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?')->execute([$u['senha_hash'], $u['id']]);
            }
            Auditoria::registrar('login', $u['email'], $u);
            return $u;
        }
        return null;
    }
    /** Conta caracteres (não bytes), igual ao minlength/maxlength do formulário */
    private static function validarSenha(string $senha): void {
        if (trim($senha) === '') throw new DomainException('A senha não pode ser só espaços.');
        // 12 = mínimo do OWASP ASVS (V2.1.1); vale para senhas novas, as já gravadas continuam entrando
        if (mb_strlen($senha) < self::SENHA_MIN || mb_strlen($senha) > 1024) throw new DomainException('A senha deve ter entre ' . self::SENHA_MIN . ' e 1024 caracteres.');
    }
    /** Senhas das listas de vazamentos que são tentadas primeiro (comparadas em minúsculas) */
    private const SENHAS_OBVIAS = ['123456', '1234567', '12345678', '123456789', '1234567890', '654321', '123123', '112233', '121212', '102030',
        '000000', '111111', '666666', '888888', 'abc123', 'abcdef', 'qwerty', 'qwerty123', 'asdfgh', 'password', 'senha', 'senha1', 'senha12',
        'senha123', 'senha1234', 'mudar123', 'trocar123', 'admin', 'admin123', 'teste', 'teste123', 'petshop', 'petshop123', 'pet123', 'brasil',
        'iloveyou', 'amor123', 'flamengo', 'corinthians', 'palmeiras', 'gremio', 'vasco'];
    /** Senha definitiva (a que o próprio usuário escolhe): não aceita as que um invasor tenta primeiro */
    private static function validarSenhaDefinitiva(string $senha, int $id): void {
        self::validarSenha($senha);
        // compara sem espaços (" 123456", "123 456"); a senha gravada continua exatamente a digitada
        $s = mb_strtolower(preg_replace('/\s+/u', '', $senha) ?? trim($senha));
        $st = Database::pdo()->prepare('SELECT email, nome FROM usuarios WHERE id = ?');
        $st->execute([$id]);
        $u = $st->fetch() ?: ['email' => '', 'nome' => ''];
        $st->closeCursor(); // ver autenticar
        $email = mb_strtolower((string) $u['email']);
        // Só as letras, sem acento: "admin1", "Senha@2026", "maria.uc99" e "João2026" viram "admin", "senha", "mariauc" e "joao"
        $acentos = ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n'];
        $letras = fn(string $t): string => preg_replace('/[^\p{L}]+/u', '', strtr(mb_strtolower($t), $acentos)) ?? '';
        $l = $letras($senha);
        // palavras que um invasor tenta: as da lista, a parte do e-mail antes do @ (inteira e cada pedaço: joao.uc -> joaouc, joao)
        // e cada nome do usuário; pedaços com 3+ letras
        $local = (string) strstr($email, '@', true);
        $palavras = array_merge(array_map($letras, self::SENHAS_OBVIAS), [$letras($local)],
            array_filter(array_map($letras, preg_split('/[^\p{L}]+/u', $local . ' ' . $u['nome']) ?: []), fn($p) => mb_strlen($p) >= 3));
        // um caractere repetido (aaaaaa, 999999), da lista, o e-mail, ou só uma dessas palavras com números/símbolos em volta
        if (in_array($s, self::SENHAS_OBVIAS, true) || preg_match('/^(.)\1*$/su', $s) || ($email !== '' && $s === $email) || ($l !== '' && in_array($l, $palavras, true)))
            throw new DomainException('Essa senha é fácil de adivinhar (lista de senhas comuns, ou seu nome ou e-mail com números). Escolha outra: uma frase curta com palavras que só você associa funciona bem.');
    }
    /** Primeiro acesso com a senha inicial: o usuário escolhe a própria antes de usar o sistema */
    public static function trocarSenha(int $id, string $senha, string $confirmacao): void {
        self::validarSenhaDefinitiva($senha, $id);
        if ($senha !== $confirmacao) throw new DomainException('A confirmação não confere com a nova senha.');
        $st = Database::pdo()->prepare('SELECT senha_hash FROM usuarios WHERE id = ?');
        $st->execute([$id]);
        $hash = (string) $st->fetchColumn();
        $st->closeCursor(); // ver autenticar
        if (password_verify($senha, $hash)) throw new DomainException('A nova senha deve ser diferente da senha provisória.');
        Database::pdo()->prepare('UPDATE usuarios SET senha_hash = ?, trocar_senha = 0, sessao_versao = sessao_versao + 1 WHERE id = ?')->execute([password_hash($senha, PASSWORD_DEFAULT), $id]);
        self::manterSessaoAtual($id);
        Auditoria::registrar('senha_alterada', 'Primeiro acesso: trocou a senha provisória');
    }
    /** A senha mudou e o bootstrap derruba as sessões com a versão antiga; a sessão de quem fez a troca continua */
    private static function manterSessaoAtual(int $id): void {
        if ($id === (usuario()['id'] ?? 0)) $_SESSION['user']['sessao_versao'] = ($_SESSION['user']['sessao_versao'] ?? 0) + 1;
    }
    /**
     * Tela "Minha senha": pede a atual para ninguém trocar a senha num computador esquecido logado.
     * Bloqueia por 15 minutos após 5 senhas atuais erradas do mesmo usuário (em tentativas_login, chave "MINHA_SENHA:<id>").
     */
    public static function alterarSenha(int $id, string $atual, string $nova, string $confirmacao): void {
        $pdo = Database::pdo();
        // Maiúsculas de propósito: o login grava o e-mail em minúsculas, então nenhum login cai nesta chave
        $chave = "MINHA_SENHA:$id";
        // Conta e registra na mesma transação, igual ao login: requisições em paralelo não passam do limite
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $st = $pdo->prepare("SELECT COUNT(*), (strftime('%s', MIN(criado_em), '+15 minutes') - strftime('%s', 'now') + 59) / 60 FROM tentativas_login WHERE email = ? AND criado_em > datetime('now', '-15 minutes')");
            $st->execute([$chave]);
            [$erros, $minutos] = $st->fetch(PDO::FETCH_NUM);
            if ($erros < 5) $pdo->prepare('INSERT INTO tentativas_login (email, ip) VALUES (?, ?)')->execute([$chave, $_SERVER['REMOTE_ADDR'] ?? '']);
            else {
                // Uma linha por bloqueio a cada 15 min, não uma por requisição bloqueada
                $st = $pdo->prepare("SELECT 1 FROM auditoria WHERE acao = 'senha_bloqueada' AND usuario_id = ? AND criado_em > datetime('now', 'localtime', '-15 minutes')");
                $st->execute([$id]);
                if (!$st->fetchColumn()) Auditoria::registrar('senha_bloqueada', 'Senha atual errada 5 vezes em "Minha senha"');
            }
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        if ($erros >= 5) {
            $minutos = max(1, (int) $minutos);
            throw new DomainException("Muitas tentativas com a senha atual errada. Aguarde $minutos minuto" . ($minutos > 1 ? 's' : '') . ' e tente novamente.');
        }
        $st = $pdo->prepare('SELECT senha_hash FROM usuarios WHERE id = ?');
        $st->execute([$id]);
        $hash = (string) $st->fetchColumn();
        $st->closeCursor(); // ver autenticar
        if (mb_strlen($atual) > 1024 || !password_verify($atual, $hash)) throw new DomainException('A senha atual está incorreta.');
        $pdo->prepare('DELETE FROM tentativas_login WHERE email = ?')->execute([$chave]);
        self::validarSenhaDefinitiva($nova, $id);
        if ($nova !== $confirmacao) throw new DomainException('A confirmação não confere com a nova senha.');
        if ($nova === $atual) throw new DomainException('A nova senha deve ser diferente da atual.');
        $pdo->prepare('UPDATE usuarios SET senha_hash = ?, sessao_versao = sessao_versao + 1 WHERE id = ?')->execute([password_hash($nova, PASSWORD_DEFAULT), $id]);
        self::manterSessaoAtual($id);
        Auditoria::registrar('senha_alterada', 'Pela tela "Minha senha"');
    }
    /** Admin define uma senha provisória (ex.: o usuário esqueceu a dele); o usuário troca no próximo acesso */
    public static function redefinirSenha(int $id, string $senha): void {
        // A própria senha só em "Minha senha", que pede a atual e bloqueia após 5 erros
        if ($id === (usuario()['id'] ?? 0)) throw new DomainException('Para trocar a sua própria senha use "Minha senha", que pede a senha atual.');
        self::validarSenha($senha);
        $st = Database::pdo()->prepare('SELECT nome, email FROM usuarios WHERE id = ?');
        $st->execute([$id]);
        $alvo = $st->fetch();
        $st->closeCursor(); // ver autenticar
        if (!$alvo) throw new DomainException('Usuário não encontrado.');
        // Derruba as sessões abertas do usuário: quem roubou uma não chega na tela de trocar a senha provisória
        Database::pdo()->prepare('UPDATE usuarios SET senha_hash = ?, trocar_senha = 1, sessao_versao = sessao_versao + 1 WHERE id = ?')->execute([password_hash($senha, PASSWORD_DEFAULT), $id]);
        Auditoria::registrar('senha_redefinida', "{$alvo['nome']} <{$alvo['email']}>");
    }
    public static function listar(): array {
        return Database::pdo()->query('SELECT id,nome,email,perfil,ativo FROM usuarios ORDER BY ativo DESC, nome')->fetchAll();
    }
    public static function criar(array $d): void {
        $nome = trim($d['nome'] ?? ''); $email = mb_strtolower(trim($d['email'] ?? ''));
        if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new DomainException('Informe nome e e-mail válidos.');
        if (mb_strlen($nome) > 100) throw new DomainException('O nome deve ter no máximo 100 caracteres.');
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
    public static function buscar(int $id): ?array {
        $st = Database::pdo()->prepare('SELECT id,nome,email,perfil,ativo FROM usuarios WHERE id = ?');
        $st->execute([$id]);
        $u = $st->fetch();
        $st->closeCursor(); // ver autenticar
        return $u ?: null;
    }
    /**
     * Admin corrige nome, e-mail e perfil. A senha não muda aqui (ver redefinirSenha).
     * O próprio perfil não muda (evita o admin se rebaixar sem querer) e o sistema nunca fica sem admin ativo.
     * O perfil novo vale na próxima página: o bootstrap relê o usuário a cada requisição.
     * @return bool se algo mudou (sem mudança não grava nem vai para a auditoria)
     */
    public static function editar(int $id, array $d): bool {
        $nome = trim($d['nome'] ?? ''); $email = mb_strtolower(trim($d['email'] ?? ''));
        $perfil = $d['perfil'] ?? '';
        if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new DomainException('Informe nome e e-mail válidos.');
        if (mb_strlen($nome) > 100) throw new DomainException('O nome deve ter no máximo 100 caracteres.');
        if (!isset(self::PERFIS[$perfil])) throw new DomainException('Perfil inválido.');
        $pdo = Database::pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            // Lido dentro da transação: outro admin não muda o alvo entre a leitura e a gravação
            $antes = self::buscar($id);
            if (!$antes) throw new DomainException('Usuário não encontrado.');
            if ($id === (usuario()['id'] ?? 0) && $perfil !== $antes['perfil']) throw new DomainException('Você não pode mudar o seu próprio perfil. Peça a outro administrador.');
            $st = $pdo->prepare('SELECT 1 FROM usuarios WHERE lower(email) = ? AND id <> ?');
            $st->execute([$email, $id]);
            $duplicado = $st->fetchColumn();
            $st->closeCursor(); // ver autenticar
            if ($duplicado) throw new DomainException('Este e-mail já está cadastrado.');
            // Sempre que o perfil final não é admin: o SQL de exigirOutroAdmin já confere se o alvo é admin ativo
            if ($perfil !== 'admin') self::exigirOutroAdmin($id);
            $mudou = array_filter([
                $antes['nome'] !== $nome ? "nome {$antes['nome']} → $nome" : null,
                $antes['email'] !== $email ? "e-mail {$antes['email']} → $email" : null,
                $antes['perfil'] !== $perfil ? 'perfil ' . self::PERFIS[$antes['perfil']] . ' → ' . self::PERFIS[$perfil] : null,
            ]);
            if ($mudou) {
                $pdo->prepare('UPDATE usuarios SET nome = ?, email = ?, perfil = ? WHERE id = ?')->execute([$nome, $email, $perfil, $id]);
                Auditoria::registrar('usuario_alterado', "$nome <$email>: " . implode(', ', $mudou));
            }
            $pdo->commit();
            return (bool) $mudou;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw new DomainException('Este e-mail já está cadastrado.');
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
    public static function excluir(int $id): void {
        if ($id === (usuario()['id'] ?? 0)) throw new DomainException('Você não pode excluir seu próprio usuário.');
        $st = Database::pdo()->prepare('SELECT nome, email FROM usuarios WHERE id = ?');
        $st->execute([$id]);
        $alvo = $st->fetch();
        $st->closeCursor(); // cursor aberto faz o BEGIN IMMEDIATE falhar na hora em vez de esperar (ver bootstrap)
        if (!$alvo) throw new DomainException('Usuário não encontrado.');
        $pdo = Database::pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            self::exigirOutroAdmin($id);
            $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
            Auditoria::registrar('usuario_excluido', "{$alvo['nome']} <{$alvo['email']}>");
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw new DomainException('Usuário possui vendas ou movimentações de estoque registradas e não pode ser excluído. Use "Desativar" para bloquear o acesso.');
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
    /** Chamar dentro de BEGIN IMMEDIATE: dois admins agindo ao mesmo tempo não deixam o sistema sem administrador ativo */
    private static function exigirOutroAdmin(int $id): void {
        $st = Database::pdo()->prepare("SELECT (SELECT perfil = 'admin' AND ativo = 1 FROM usuarios WHERE id = ?) AND (SELECT COUNT(*) FROM usuarios WHERE perfil = 'admin' AND ativo = 1) <= 1");
        $st->execute([$id]);
        if ($st->fetchColumn()) throw new DomainException('Este é o único administrador ativo: o sistema não pode ficar sem administrador.');
    }
    /** Funcionário que saiu: perde o acesso na hora, mas continua no histórico de vendas e relatórios */
    public static function definirAtivo(int $id, bool $ativo): void {
        if ($id === (usuario()['id'] ?? 0)) throw new DomainException('Você não pode desativar seu próprio usuário.');
        $st = Database::pdo()->prepare('SELECT nome, email FROM usuarios WHERE id = ?');
        $st->execute([$id]);
        $alvo = $st->fetch();
        $st->closeCursor(); // ver excluir
        if (!$alvo) throw new DomainException('Usuário não encontrado.');
        $pdo = Database::pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            if (!$ativo) self::exigirOutroAdmin($id);
            $pdo->prepare('UPDATE usuarios SET ativo = ? WHERE id = ?')->execute([(int) $ativo, $id]);
            Auditoria::registrar($ativo ? 'usuario_reativado' : 'usuario_desativado', "{$alvo['nome']} <{$alvo['email']}>");
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
}
