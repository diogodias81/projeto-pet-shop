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


            CREATE TABLE IF NOT EXISTS lotes (

                id INTEGER PRIMARY KEY AUTOINCREMENT,

                produto_id INTEGER NOT NULL
                    REFERENCES produtos(id),

                codigo TEXT NOT NULL,

                validade TEXT,

                quantidade INTEGER NOT NULL
                    CHECK (quantidade >= 0),

                criado_em TEXT NOT NULL
                    DEFAULT (
                        datetime('now', 'localtime')
                    )
            );


            -- De qual lote saiu cada unidade vendida (para devolver ao mesmo lote)
            CREATE TABLE IF NOT EXISTS venda_item_lotes (

                id INTEGER PRIMARY KEY AUTOINCREMENT,

                venda_item_id INTEGER NOT NULL
                    REFERENCES venda_itens(id),

                lote_id INTEGER NOT NULL
                    REFERENCES lotes(id),

                quantidade INTEGER NOT NULL
                    CHECK (quantidade > 0),

                devolvida INTEGER NOT NULL
                    DEFAULT 0
            );


            CREATE TABLE IF NOT EXISTS devolucoes (

                id INTEGER PRIMARY KEY AUTOINCREMENT,

                venda_id INTEGER NOT NULL
                    REFERENCES vendas(id),

                venda_item_id INTEGER NOT NULL
                    REFERENCES venda_itens(id),

                quantidade INTEGER NOT NULL
                    CHECK (quantidade > 0),

                valor REAL NOT NULL,

                motivo TEXT NOT NULL,

                usuario_id INTEGER
                    REFERENCES usuarios(id),

                criado_em TEXT NOT NULL
                    DEFAULT (
                        datetime('now', 'localtime')
                    )
            );
        ");


        // Colunas da Etapa 1. Bancos antigos recebem as colunas
        // e o estoque que já existia vira um lote "INICIAL" sem validade.

        $novas = [
            'produtos' => [
                'codigo_barras' => 'TEXT',
                'foto' => 'TEXT',
                'preco_custo' => 'REAL NOT NULL DEFAULT 0',
            ],
            'entradas_estoque' => [
                'lote_id' => 'INTEGER REFERENCES lotes(id)',
            ],
            'venda_itens' => [
                'custo_unit' => 'REAL NOT NULL DEFAULT 0',
                // Etapa 2
                'quantidade_devolvida' => 'INTEGER NOT NULL DEFAULT 0',
            ],
            // Etapa 2: total = subtotal - desconto (valor cobrado).
            // O que a venda rendeu de fato é total - valor_devolvido, se não estiver cancelada.
            'vendas' => [
                'subtotal' => 'REAL NOT NULL DEFAULT 0',
                'desconto' => 'REAL NOT NULL DEFAULT 0',
                'forma_pagamento' => 'TEXT',
                'valor_recebido' => 'REAL',
                'valor_devolvido' => 'REAL NOT NULL DEFAULT 0',
                'status' => "TEXT NOT NULL DEFAULT 'concluida'",
                'cancelado_em' => 'TEXT',
                'cancelado_por' => 'INTEGER REFERENCES usuarios(id)',
                'motivo_cancelamento' => 'TEXT',
            ],
        ];

        foreach ($novas as $tabela => $colunas) {

            $existentes = array_column(
                $pdo->query("PRAGMA table_info($tabela)")->fetchAll(),
                'name'
            );

            foreach ($colunas as $coluna => $tipo) {

                if (!in_array($coluna, $existentes, true)) {

                    $pdo->exec("ALTER TABLE $tabela ADD COLUMN $coluna $tipo");

                    // Vendas antigas não tinham desconto: o subtotal é o próprio total.
                    if ($coluna === 'subtotal') {

                        $pdo->exec('UPDATE vendas SET subtotal = total');
                    }

                    if ($coluna === 'codigo_barras') {

                        $pdo->exec("
                            INSERT INTO lotes (produto_id, codigo, quantidade)
                            SELECT id, 'INICIAL', estoque
                            FROM produtos
                            WHERE estoque > 0
                        ");
                    }
                }
            }
        }

        $pdo->exec('
            CREATE UNIQUE INDEX IF NOT EXISTS idx_produtos_codigo_barras
                ON produtos (codigo_barras);

            CREATE INDEX IF NOT EXISTS idx_lotes_produto
                ON lotes (produto_id, validade);

            CREATE INDEX IF NOT EXISTS idx_vendas_data
                ON vendas (criado_em);

            CREATE INDEX IF NOT EXISTS idx_venda_itens_venda
                ON venda_itens (venda_id);

            CREATE INDEX IF NOT EXISTS idx_venda_item_lotes_item
                ON venda_item_lotes (venda_item_id);
        ');


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
