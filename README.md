# PetShop Gestão

Sistema de controle de estoque e vendas desenvolvido em **PHP + JavaScript + CSS**, utilizando **SQLite** como banco de dados.

## Como rodar com Docker

### Pré-requisitos

Tenha instalado:

* [Docker](https://www.docker.com/)
* Docker Compose

### 1. Clone o projeto

```bash
git clone https://github.com/diogodias81/projeto-pet-shop.git
cd projeto-pet-shop
```

### 2. Suba o projeto

Execute:

```bash
docker compose up -d --build
```

O Docker irá criar e iniciar os containers necessários para o projeto.

### 3. Acesse o sistema

Abra no navegador:

```text
http://localhost:8000
```

### 4. Parar o projeto

Para parar os containers:

```bash
docker compose down
```

Para iniciar novamente:

```bash
docker compose up -d
```

## Usuários iniciais

Na primeira execução (banco vazio) é criado só o administrador `admin@petshop.com`. A senha inicial é:

* a da variável de ambiente `ADMIN_SENHA`, se definida (ex.: `ADMIN_SENHA='minha-senha' docker compose up -d --build`, ou num arquivo `.env` copiado de `.env.example`, que o Docker Compose lê sozinho e que nunca vai para o git); ou
* uma senha aleatória, mostrada **uma única vez** no log do servidor:

```bash
# Docker: rode logo após o primeiro "docker compose up" (a linha aparece quando o banco é criado, no primeiro acesso à página)
docker compose logs php | grep "senha inicial"
```

Sem Docker (`php -S`), a linha aparece no próprio terminal onde o servidor está rodando:

```bash
php -S 127.0.0.1:8000 -t public
# ... PetShop: senha inicial do admin@petshop.com = 1a2b3c4d5e6f7a8b (troca obrigatória no primeiro acesso)
```

Se a senha se perdeu antes do primeiro acesso, pare o sistema, apague `storage/petshop.sqlite` (só se ainda não há dados) e suba de novo, ou defina `ADMIN_SENHA`.

No primeiro acesso o sistema obriga a troca dessa senha. Os demais usuários são criados pelo administrador na tela **Usuários**, onde ele também pode **redefinir a senha** de quem esqueceu (o usuário troca a senha provisória no próximo acesso).

Em desenvolvimento (`APP_DEBUG=1`) também são criadas contas de demonstração com a senha `senha123` (troca obrigatória no primeiro acesso). Nunca use `APP_DEBUG=1` em produção.

| E-mail                                                  | Perfil              | Acessa                            |
| ------------------------------------------------------- | ------------------- | --------------------------------- |
| [admin@petshop.com](mailto:admin@petshop.com)           | Administrador       | Tudo, inclusive usuários          |
| [vendedor@petshop.com](mailto:vendedor@petshop.com)     | Vendedor (demo)     | Vendas; consulta produtos/estoque |
| [estoque@petshop.com](mailto:estoque@petshop.com)       | Responsável estoque (demo) | Produtos, categorias e estoque |
| [financeiro@petshop.com](mailto:financeiro@petshop.com) | Financeiro (demo)   | Dashboard e relatórios            |

O sistema fica acessível só nesta máquina (`127.0.0.1:8000`). Para usar em rede, coloque um proxy com HTTPS na frente.

## Estrutura do projeto

```text
config/
├── config.php        # Nome do sistema, banco e configurações
└── routes.php        # Rotas e permissões

app/
├── Database.php      # Criação das tabelas SQLite
├── Models/           # Regras de negócio e consultas SQL
├── Pages/            # Páginas do sistema
└── Views/            # Cabeçalho e rodapé comuns

public/
├── assets/
│   ├── css/          # Arquivos CSS
│   └── js/           # Arquivos JavaScript
└── index.php         # Ponto de entrada da aplicação

storage/
└── petshop.sqlite    # Banco de dados SQLite

Dockerfile
docker-compose.yml
```

## Como adicionar uma nova página

1. Crie o arquivo dentro de `app/Pages/`:

```text
app/Pages/minhapagina.php
```

2. Registre a página em `config/routes.php`:

```php
'minhapagina' => ['Título', ['admin']]
```

3. Coloque as regras de negócio e consultas SQL no Model correspondente dentro de:

```text
app/Models/
```

## Banco de dados

O projeto utiliza **SQLite**.

O banco é criado automaticamente em:

```text
storage/petshop.sqlite
```

Durante o desenvolvimento, mantenha atenção a esse arquivo, pois ele contém os dados cadastrados no sistema.

## Mapa de requisitos

```text
RF01–RF03 → Produtos/Categorias
RF04      → Estoque
RF05–RF08 → Vendas
RF09–RF10 → Produtos/Estoque
RF11–RF12 → Vendas/Relatórios
RF13–RF15 → Dashboard
```

## Docker

Os principais comandos utilizados são:

### Criar e iniciar

```bash
docker compose up -d --build
```

### Ver containers

```bash
docker compose ps
```

### Ver os logs

```bash
docker compose logs -f
```

### Parar

```bash
docker compose down
```

### Recriar os containers

```bash
docker compose down
docker compose up -d --build
```

## Em produção

Para utilização em produção:

* Utilize HTTPS.
* Mantenha `debug` desativado.
* Faça backups periódicos do banco `storage/petshop.sqlite`.
* Não utilize as senhas padrão dos usuários.
* Configure corretamente o servidor web e as variáveis de ambiente.
