<?php

class Database
{
    private static ?PDO $pdo = null;


    /**
     * Retorna a conexão com o banco de dados.
     */
    public static function pdo(): PDO
    {
        if (!self::$pdo) {

            $caminho = config('db_path');

            $diretorio = dirname($caminho);

            if (!is_dir($diretorio)) {

                mkdir(
                    $diretorio,
                    0775,
                    true
                );
            }

            self::$pdo = new PDO(
                'sqlite:' . $caminho,
                null,
                null,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,

                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );

            self::$pdo->exec(
                'PRAGMA foreign_keys = ON'
            );
        }

        return self::$pdo;
    }


    /**
     * Cria as tabelas, caso não existam,
     * e insere os dados iniciais.
     */
    public static function migrar(): void
    {
        $pdo = self::pdo();

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS usuarios (

                id INTEGER PRIMARY KEY AUTOINCREMENT,

                nome TEXT NOT NULL,

                email TEXT NOT NULL UNIQUE,

                senha_hash TEXT NOT NULL,

                perfil TEXT NOT NULL
                    CHECK (
                        perfil IN (
                            'admin',
                            'vendedor',
                            'estoque',
                            'financeiro'
                        )
                    )
            );


            CREATE TABLE IF NOT EXISTS categorias (

                id INTEGER PRIMARY KEY AUTOINCREMENT,

                nome TEXT NOT NULL UNIQUE
            );


            CREATE TABLE IF NOT EXISTS produtos (

                id INTEGER PRIMARY KEY AUTOINCREMENT,

                nome TEXT NOT NULL,

                categoria_id INTEGER
                    REFERENCES categorias(id),

                preco REAL NOT NULL
                    CHECK (preco >= 0),

                estoque INTEGER NOT NULL
                    DEFAULT 0,

                estoque_minimo INTEGER NOT NULL
                    DEFAULT 5,

                ativo INTEGER NOT NULL
                    DEFAULT 1
            );


            CREATE TABLE IF NOT EXISTS entradas_estoque (

                id INTEGER PRIMARY KEY AUTOINCREMENT,

                produto_id INTEGER NOT NULL
                    REFERENCES produtos(id),

                quantidade INTEGER NOT NULL
                    CHECK (quantidade > 0),

                fornecedor TEXT,

                criado_em TEXT NOT NULL
                    DEFAULT (
                        datetime('now', 'localtime')
                    )
            );


            CREATE TABLE IF NOT EXISTS vendas (

                id INTEGER PRIMARY KEY AUTOINCREMENT,

                usuario_id INTEGER
                    REFERENCES usuarios(id),

                total REAL NOT NULL
                    DEFAULT 0,

                criado_em TEXT NOT NULL
                    DEFAULT (
                        datetime('now', 'localtime')
                    )
            );


            CREATE TABLE IF NOT EXISTS venda_itens (

                id INTEGER PRIMARY KEY AUTOINCREMENT,

                venda_id INTEGER NOT NULL
                    REFERENCES vendas(id),

                produto_id INTEGER NOT NULL
                    REFERENCES produtos(id),

                quantidade INTEGER NOT NULL
                    CHECK (quantidade > 0),

                preco_unit REAL NOT NULL
            );
        ");


        // Insere os dados iniciais apenas
        // quando ainda não existem usuários.

        $quantidadeUsuarios = (int) $pdo
            ->query(
                'SELECT COUNT(*) FROM usuarios'
            )
            ->fetchColumn();


        if ($quantidadeUsuarios === 0) {

            $ins = $pdo->prepare(
                '
                INSERT INTO usuarios
                    (nome, email, senha_hash, perfil)
                VALUES
                    (?, ?, ?, ?)
                '
            );


            $usuarios = [
                ['Administrador', 'admin'],
                ['Vendedor', 'vendedor'],
                ['Estoquista', 'estoque'],
                ['Financeiro', 'financeiro'],
            ];


            foreach ($usuarios as [$nome, $perfil]) {

                $ins->execute([
                    $nome,
                    "$perfil@petshop.com",
                    password_hash(
                        'senha123',
                        PASSWORD_DEFAULT
                    ),
                    $perfil,
                ]);
            }


            // Categorias iniciais

            $categorias = [
                'Rações',
                'Higiene',
                'Brinquedos',
                'Acessórios',
                'Medicamentos',
            ];


            foreach ($categorias as $categoria) {

                $pdo
                    ->prepare(
                        'INSERT INTO categorias (nome) VALUES (?)'
                    )
                    ->execute([$categoria]);
            }
        }
    }
}
