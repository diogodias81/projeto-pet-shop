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

## Backup

Todo dia às 22:00 o Agendador de Tarefas do Windows roda `scripts/backup.ps1`, que:

1. faz o backup dentro do container (`scripts/backup.php`): cópia segura do banco com o sistema aberto (`VACUUM INTO`), conferida com `integrity_check`, mais as fotos dos produtos, em `storage/backups/AAAA-MM-DD_HHMMSS/` (a pasta só ganha esse nome quando tudo deu certo; se algo falhar, a pasta é apagada; se o backup for interrompido no meio, como num desligamento, a pasta `.tmp` que sobrou é apagada no dia seguinte);
2. apaga os backups com mais de 30 dias, mas sempre mantém os 7 mais novos (mesmo se o relógio do computador estiver errado);
3. copia `storage/backups` para `OneDrive\Backups PetShop` (sobe para a nuvem sozinho). A cópia só acrescenta: apagar uma pasta em `storage/backups` não apaga a do OneDrive. Lá vale a mesma regra de 30 dias / 7 mais novos.

Se o computador estiver desligado às 22:00, a tarefa roda assim que ele ligar. O Docker precisa estar ligado. Cada execução fica em `storage/backups/backup.log`, e o **Dashboard avisa o administrador** se o último backup falhou (inclusive a cópia para o OneDrive), tem mais de 2 dias ou tem registro ilegível. O motivo da falha fica no `backup.log`.

> **Atenção: o backup vai para o OneDrive sem criptografia.** O banco tem os dados das vendas, os e-mails dos usuários, a auditoria (com IPs) e as senhas (só o hash bcrypt, não a senha em si). Quem entrar na conta do OneDrive tem acesso a tudo isso. Use senha forte e verificação em duas etapas na conta e não compartilhe a pasta `Backups PetShop`. Cifrar antes de enviar está pendente de decisão (qual ferramenta usar e onde guardar a senha).

```powershell
# Criar a tarefa (uma vez, num computador novo)
$acao = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument '-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File "C:\caminho\do\projeto-pet-shop\scripts\backup.ps1"'
Register-ScheduledTask -TaskName 'PetShop Backup' -Action $acao -Trigger (New-ScheduledTaskTrigger -Daily -At 22:00) -Settings (New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Hours 1))

# Fazer um backup agora
Start-ScheduledTask -TaskName 'PetShop Backup'
```

### Restaurar um backup

1. Escolha o backup: no `storage/backups/backup.log`, a pasta precisa aparecer numa linha `OK` (ex.: `2026-11-02 22:00:05 OK 2026-11-02_220005: Backup ok: ...`). Pasta sem linha `OK` não serve.
2. Pare o sistema: `docker compose down`
3. Guarde o banco atual por segurança: renomeie `storage/petshop.sqlite` para `storage/petshop-antes-da-restauracao.sqlite`. Se existir `storage/petshop.sqlite-journal`, apague (é de uma gravação interrompida do banco antigo e estragaria o restaurado).
4. Copie o `petshop.sqlite` da pasta do backup escolhido (em `storage/backups` ou no OneDrive) para `storage/petshop.sqlite`
5. Copie as fotos da pasta `fotos` do backup para `public/uploads/produtos/`
6. Suba de novo: `docker compose up -d`
7. Entre como administrador e confira em **Usuários**: quem trocou de senha depois do backup volta a entrar com a senha antiga, e usuários desativados depois do backup voltam ativos. Redefina essas senhas e desative de novo quem não deve entrar.

Tudo o que foi registrado depois do horário do backup se perde: confira a data no nome da pasta.

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
