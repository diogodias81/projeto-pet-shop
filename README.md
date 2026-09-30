# PetShop Gestão

Sistema de controle de estoque e vendas em PHP + JS + CSS. Requer PHP 8.0+ com extensões `pdo_sqlite` e `sqlite3` (já vêm na maioria das instalações).

## Como rodar
```
php -S localhost:8000 -t public
```
Abra http://localhost:8000

## Usuários iniciais (senha: senha123 — troque após o primeiro acesso)
| E-mail                  | Perfil                 | Acessa                              |
|-------------------------|------------------------|-------------------------------------|
| admin@petshop.com       | Administrador          | tudo, inclusive usuários            |
| vendedor@petshop.com    | Vendedor               | vendas; consulta produtos/estoque   |
| estoque@petshop.com     | Responsável estoque    | produtos, categorias, estoque       |
| financeiro@petshop.com  | Financeiro             | dashboard e relatórios              |

## Estrutura (onde mexer)
```
config/config.php   Nome do sistema, caminho do banco, modo debug
config/routes.php   Páginas do menu e quais perfis acessam cada uma
app/Database.php    Criação das tabelas (SQLite via PDO)
app/Models/         Regras de negócio e SQL (Produto, Venda, Estoque, Relatorio...)
app/Pages/          Uma página por arquivo (recebe formulário + monta HTML)
app/Views/          Cabeçalho e rodapé comuns
public/assets/      CSS (variáveis no topo) e JS
public/index.php    Único ponto de entrada (roteador)
storage/            Banco SQLite (criado automaticamente; faça backup deste arquivo)
```

## Como adicionar uma nova página
1. Crie `app/Pages/minhapagina.php`.
2. Registre em `config/routes.php`: `'minhapagina' => ['Título', ['admin']]`.
3. Coloque SQL/regras em um Model em `app/Models/`.

## Mapa de requisitos
RF01–RF03 → Produtos/Categorias · RF04 → Estoque · RF05–RF08 → Vendas (`Venda::registrar`) ·
RF09–RF10 → Produtos/Estoque · RF11–RF12 → Vendas/Relatórios · RF13–RF15 → Dashboard

## Em produção
- Use HTTPS e aponte o servidor web para a pasta `public/`.
- Mantenha `debug` = false e faça backup periódico de `storage/petshop.sqlite`.
