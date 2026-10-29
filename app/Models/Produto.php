<?php
class Produto {
    /** Quantidade que pode ser vendida: soma dos lotes sem validade ou ainda não vencidos */
    private const VENDAVEL = "(SELECT COALESCE(SUM(l.quantidade),0) FROM lotes l WHERE l.produto_id = p.id AND (l.validade IS NULL OR l.validade >= date('now','localtime')))";

    /** Lista e pesquisa produtos ativos por nome, código de barras ou categoria */
    public static function listar(string $busca = '', int $categoriaId = 0): array {
        $sql = 'SELECT p.*, c.nome AS categoria, ' . self::VENDAVEL . ' AS vendavel FROM produtos p LEFT JOIN categorias c ON c.id = p.categoria_id WHERE p.ativo = 1';
        $par = [];
        if ($busca !== '')   { $sql .= ' AND (p.nome LIKE ? OR p.codigo_barras = ?)'; $par[] = "%$busca%"; $par[] = $busca; }
        if ($categoriaId > 0){ $sql .= ' AND p.categoria_id = ?'; $par[] = $categoriaId; }
        $st = Database::pdo()->prepare($sql . ' ORDER BY p.nome');
        $st->execute($par);
        return $st->fetchAll();
    }
    public static function buscar(int $id): ?array {
        $st = Database::pdo()->prepare('SELECT p.*, ' . self::VENDAVEL . ' AS vendavel FROM produtos p WHERE p.id = ? AND p.ativo = 1');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }
    /**
     * Cria (sem id) ou edita (com id). O estoque só muda por entrada/venda, exceto no cadastro inicial,
     * que vira o primeiro lote do produto.
     * @param array|null $foto item de $_FILES, opcional
     */
    public static function salvar(array $d, ?array $foto = null): void {
        $nome = trim($d['nome'] ?? '');
        $codigo = trim($d['codigo_barras'] ?? '') ?: null;
        $preco = numero($d['preco'] ?? 0);
        $custo = numero($d['preco_custo'] ?? 0);
        $min = (int)($d['estoque_minimo'] ?? 0);
        $cat = (int)($d['categoria_id'] ?? 0) ?: null;
        $id = (int)($d['id'] ?? 0);
        if ($nome === '') throw new DomainException('Informe o nome do produto.');
        if ($codigo !== null && !preg_match('/^\d{4,14}$/', $codigo)) throw new DomainException('O código de barras deve ter de 4 a 14 números.');
        if ($preco <= 0)  throw new DomainException('Informe um preço de venda válido, ex.: 143,99');
        if ($custo < 0)   throw new DomainException('O preço de custo não pode ser negativo.');
        if ($min < 0)     throw new DomainException('O estoque mínimo não pode ser negativo.');
        $pdo = Database::pdo();
        if ($codigo !== null) {
            $st = $pdo->prepare('SELECT nome FROM produtos WHERE codigo_barras = ? AND id <> ?');
            $st->execute([$codigo, $id]);
            if ($outro = $st->fetchColumn()) throw new DomainException("O código de barras $codigo já pertence a \"$outro\".");
        }
        $arquivo = $foto && $foto['error'] !== UPLOAD_ERR_NO_FILE ? self::guardarFoto($foto) : null;
        $pdo->beginTransaction();
        try {
            if ($id) {
                $antiga = $arquivo ? (self::buscar($id)['foto'] ?? null) : null;
                $pdo->prepare('UPDATE produtos SET nome=?, codigo_barras=?, categoria_id=?, preco=?, preco_custo=?, estoque_minimo=?, foto=COALESCE(?, foto) WHERE id=?')
                    ->execute([$nome, $codigo, $cat, $preco, $custo, $min, $arquivo, $id]);
            } else {
                $inicial = max(0, (int)($d['estoque'] ?? 0));
                $pdo->prepare('INSERT INTO produtos (nome,codigo_barras,categoria_id,preco,preco_custo,estoque,estoque_minimo,foto) VALUES (?,?,?,?,?,0,?,?)')
                    ->execute([$nome, $codigo, $cat, $preco, $custo, $min, $arquivo]);
                if ($inicial > 0) Estoque::entrada((int)$pdo->lastInsertId(), $inicial, '', $d['lote'] ?? '', $d['validade'] ?? '');
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            if ($arquivo) @unlink(self::pastaFotos() . '/' . $arquivo);
            throw $e;
        }
        if (!empty($antiga)) @unlink(self::pastaFotos() . '/' . $antiga);
    }
    /** Exclusão lógica: preserva o histórico de vendas */
    public static function excluir(int $id): void {
        Database::pdo()->prepare('UPDATE produtos SET ativo = 0 WHERE id = ?')->execute([$id]);
    }
    /** Produtos com estoque igual ou abaixo do mínimo */
    public static function baixoEstoque(): array {
        return Database::pdo()->query('SELECT p.*, c.nome AS categoria FROM produtos p LEFT JOIN categorias c ON c.id=p.categoria_id WHERE p.ativo=1 AND p.estoque <= p.estoque_minimo ORDER BY p.estoque')->fetchAll();
    }
    /** Margem sobre o preço de venda, em %. Null quando o custo não foi informado. */
    public static function margem(array $p): ?float {
        return $p['preco_custo'] > 0 && $p['preco'] > 0 ? ($p['preco'] - $p['preco_custo']) / $p['preco'] * 100 : null;
    }
    public static function urlFoto(?string $foto): ?string {
        return $foto ? 'uploads/produtos/' . rawurlencode($foto) : null;
    }
    private static function pastaFotos(): string {
        return BASE_PATH . '/public/uploads/produtos';
    }
    /** Valida a imagem enviada e grava com nome aleatório. Retorna o nome do arquivo. */
    private static function guardarFoto(array $f): string {
        if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || $f['size'] > 2 * 1024 * 1024)
            throw new DomainException('A foto deve ter no máximo 2 MB.');
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new DomainException('Não foi possível enviar a foto.');
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name'])] ?? null;
        if (!$ext) throw new DomainException('A foto deve ser JPG, PNG ou WEBP.');
        if (!is_dir(self::pastaFotos())) mkdir(self::pastaFotos(), 0775, true);
        $nome = bin2hex(random_bytes(12)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], self::pastaFotos() . '/' . $nome)) throw new DomainException('Não foi possível salvar a foto.');
        return $nome;
    }
}
