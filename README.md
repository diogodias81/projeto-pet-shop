# PetShop Gestão — Instalação e execução

Sistema de vendas, estoque, caixa, clientes e banho e tosa para pet shop. Este guia explica **como instalar, rodar, fazer backup e atualizar**.

> **O que o sistema faz e como usar cada tela:** veja [FUNCIONALIDADES.md](FUNCIONALIDADES.md).

**Tecnologias:** PHP 8.4 (Apache) + JavaScript + CSS, Bootstrap 5.3.8 e Bootstrap Icons 1.13.1 (arquivos locais, funciona sem internet), banco **SQLite**, tudo rodando no **Docker**.

Sumário:

1. [Requisitos](#1-requisitos)
2. [Instalar](#2-instalar)
3. [Primeiro acesso](#3-primeiro-acesso)
4. [Configurar o backup](#4-configurar-o-backup)
5. [Comandos do dia a dia](#5-comandos-do-dia-a-dia)
6. [Atualizar o sistema](#6-atualizar-o-sistema)
7. [Restaurar um backup](#7-restaurar-um-backup)
8. [Problemas comuns](#8-problemas-comuns)
9. [Para desenvolvedores](#9-para-desenvolvedores)

---

## 1. Requisitos

| Programa | Para quê | Como instalar |
| --- | --- | --- |
| [Docker Desktop](https://www.docker.com/products/docker-desktop/) | Roda o sistema (PHP, Apache e SQLite já vêm dentro da imagem) | Baixe do site. Nas configurações, marque **Start Docker Desktop when you sign in**: o sistema e o backup das 22:00 dependem disso |
| [Git](https://git-scm.com/) | Baixar e atualizar o sistema | `winget install Git.Git` |
| [7-Zip](https://www.7-zip.org/) | Cifrar o backup enviado ao OneDrive | `winget install 7zip.7zip` |
| OneDrive | Guardar a cópia do backup na nuvem | Já vem no Windows; conecte à conta da loja |
| Navegador | Usar o sistema | Chrome ou Edge (a leitura de código de barras pela câmera funciona melhor neles) |

Não é preciso instalar PHP nem banco de dados no computador: tudo vem na imagem do Docker.

## 2. Instalar

**Quem faz:** o técnico, no computador da loja. Todos os comandos são no **PowerShell**.

1. Baixe o sistema:

   ```powershell
   cd C:\Users\<usuário>
   git clone https://github.com/diogodias81/projeto-pet-shop.git
   cd projeto-pet-shop
   ```

   **Todos os comandos deste guia rodam dentro dessa pasta.**

2. Crie o arquivo de configuração `.env` a partir do modelo:

   ```powershell
   copy .env.example .env
   notepad .env
   ```

   | Variável | O que colocar |
   | --- | --- |
   | `ADMIN_SENHA` | Senha inicial do administrador. Opcional: vazia = o sistema sorteia uma (veja o passo 3) |
   | `BACKUP_SENHA` | Senha da cifra dos backups no OneDrive, **com pelo menos 12 caracteres** |
   | `APP_DEBUG` | `0` na loja. Nunca `1` (mostra erros na tela e cria contas de demonstração) |

   > **Guarde a `BACKUP_SENHA` fora deste computador** (gerenciador de senhas ou papel em lugar seguro). Se o computador quebrar, os backups do OneDrive só abrem com ela. O `.env` nunca vai para o git nem para a imagem do Docker.

3. Ajuste os dados da loja em `config/config.php` (aparecem no comprovante e nas mensagens de WhatsApp):

   ```php
   'loja' => [
       'nome' => 'Nome da Loja',
       'cnpj' => '00.000.000/0001-00',
       'endereco' => 'Rua, número - Bairro - Cidade/UF',
       'telefone' => '(00) 0000-0000',
   ],
   ```

   No mesmo arquivo ficam os limites que a loja pode mudar: desconto máximo do vendedor (10%), sangria do vendedor por dia (R$ 500), atendimentos de banho e tosa ao mesmo tempo (1), horário da agenda (08:00 às 19:00), largura do papel do cupom (80 ou 58 mm), aviso de vacina/vermífugo (7 dias antes), alertas de validade (30, 15 e 7 dias) e tempo de inatividade para sair sozinho (30 min).

4. Suba o sistema (a primeira vez demora alguns minutos):

   ```powershell
   docker compose up -d --build
   ```

5. Abra no navegador: **http://localhost:8000**

O sistema só é acessível **neste computador** (`127.0.0.1:8000`). Para usar em outros computadores ou celulares da rede, coloque um proxy com **HTTPS** na frente (ex.: Caddy ou nginx); não troque a porta para `0.0.0.0`. A câmera do celular (leitor de código de barras) também só funciona com HTTPS.

O banco (`storage/petshop.sqlite`) é criado sozinho no primeiro acesso. O container volta sozinho quando o Docker Desktop abre (`restart: unless-stopped`).

## 3. Primeiro acesso

**Quem faz:** o administrador.

1. Entre com **admin@petshop.com** e a senha inicial:
   * a do `ADMIN_SENHA` do `.env`, se você preencheu; ou
   * a sorteada, que aparece **uma única vez** no log. Abra a página do sistema uma vez e rode:

     ```powershell
     docker compose logs php | findstr "senha inicial"
     ```

   Perdeu a senha antes do primeiro acesso? Pare o sistema (`docker compose down`), apague `storage\petshop.sqlite` (**só se ainda não há dados**) e suba de novo.
2. O sistema obriga a **trocar a senha** no primeiro acesso. Depois, apague a linha `ADMIN_SENHA` do `.env` (a senha passa a ficar só no banco).
3. Em **Usuários**, crie uma conta para cada pessoa, com o perfil certo (administrador, vendedor, estoque ou financeiro; veja [FUNCIONALIDADES.md](FUNCIONALIDADES.md#perfis-de-acesso)) e uma senha provisória. Cada um troca a senha no primeiro acesso.

## 4. Configurar o backup

**Quem faz:** o técnico, uma vez.

Todo dia às 22:00 o Windows roda `scripts\backup.ps1`, que:

1. faz o backup dentro do sistema: cópia segura do banco (mesmo com gente vendendo), conferida contra defeitos, mais as fotos dos produtos, em `storage\backups\AAAA-MM-DD_HHMMSS\`. A pasta só ganha esse nome quando tudo deu certo;
2. apaga os backups com mais de 30 dias, mas **sempre mantém os 7 mais novos**;
3. cria um arquivo **cifrado** (7-Zip, AES-256, com a `BACKUP_SENHA`) de cada backup novo em `OneDrive\Backups PetShop\AAAA-MM-DD_HHMMSS.7z`, que sobe para a nuvem sozinho. Lá vale a mesma regra de 30 dias / 7 mais novos.

Se o computador estiver desligado às 22:00, a tarefa roda quando ele ligar e espera até 10 minutos o Docker Desktop abrir. Se o sistema estiver parado, a tarefa o sobe.

**Para agendar**, cole no PowerShell **trocando `C:\caminho\do\projeto-pet-shop` pela pasta do sistema**:

```powershell
# Criar a tarefa (se já existe uma antiga, apague antes com: Unregister-ScheduledTask 'PetShop Backup' -Confirm:$false)
$acao = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument '-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File "C:\caminho\do\projeto-pet-shop\scripts\backup.ps1"'
Register-ScheduledTask -TaskName 'PetShop Backup' -Action $acao -Trigger (New-ScheduledTaskTrigger -Daily -At 22:00) -Settings (New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Hours 1))

# Testar agora (faz um backup completo)
Start-ScheduledTask -TaskName 'PetShop Backup'
```

**Para conferir se funcionou:** abra `storage\backups\backup.log`. A última linha deve ser `OK`. Cada execução começa com uma linha `INICIO`; um `INICIO` sem `OK` ou `FALHA` depois quer dizer que o computador desligou ou dormiu no meio do backup. Veja também se apareceu um `.7z` novo em `OneDrive\Backups PetShop`. O Dashboard do administrador avisa quando algo dá errado ([problemas comuns](#8-problemas-comuns)).

## 5. Comandos do dia a dia

| Para | Comando |
| --- | --- |
| Ligar o sistema | `docker compose up -d` |
| Parar o sistema | `docker compose down` |
| Ver se está rodando | `docker compose ps` |
| Ver o log (erros) | `docker compose logs -f` |
| Fazer um backup agora | `Start-ScheduledTask -TaskName 'PetShop Backup'` |

**Ao abrir a loja:** ligue o computador, confira se o Docker Desktop abriu (ícone da baleia) e se http://localhost:8000 abre. **Ao fechar:** deixe o computador ligado até depois das 22:00 (hora do backup), ou ele faz o backup ao ligar no dia seguinte.

## 6. Atualizar o sistema

```powershell
git pull
docker compose up -d --build
```

O `--build` é obrigatório: o código fica dentro da imagem do Docker, e sem ele o sistema continua na versão antiga. As tabelas novas são criadas sozinhas no próximo acesso. O banco, as fotos e os backups (`storage` e `public\uploads`) não são afetados.

## 7. Restaurar um backup

**Quem faz:** o técnico, quando o banco se perdeu ou estragou. **Tudo o que foi registrado depois do horário do backup se perde.**

1. **Escolha o backup.**
   * Neste computador: em `storage\backups\backup.log`, a pasta precisa aparecer numa linha `OK` (ex.: `2026-11-02 22:00:05 OK 2026-11-02_220005: Backup ok em ...`). Pasta sem linha `OK` não serve.
   * No OneDrive (computador perdido ou pasta `storage` apagada): abra o `.7z` mais recente de `OneDrive\Backups PetShop` com o 7-Zip (botão direito → 7-Zip → Extrair aqui), digitando a `BACKUP_SENHA`. Ou:

     ```powershell
     & "C:\Program Files\7-Zip\7z.exe" x "$env:OneDrive\Backups PetShop\2026-11-02_220005.7z" -oC:\restaurar
     ```

     Dentro estão o `petshop.sqlite` e a pasta `fotos`.
2. Pare o sistema: `docker compose down`
3. Guarde o banco atual: renomeie `storage\petshop.sqlite` para `storage\petshop-antes-da-restauracao.sqlite`. Se existir `storage\petshop.sqlite-journal`, apague (é de uma gravação interrompida e estragaria o banco restaurado).
4. Copie o `petshop.sqlite` do backup para `storage\petshop.sqlite`.
5. Copie as fotos da pasta `fotos` do backup para `public\uploads\produtos\`.
6. Suba de novo: `docker compose up -d`
7. Entre como administrador e confira **Usuários**: quem trocou de senha depois do backup volta a entrar com a senha antiga, e quem foi desativado depois do backup volta ativo. Redefina essas senhas e desative de novo quem não deve entrar.

## 8. Problemas comuns

Os avisos de backup aparecem no topo do **Dashboard**, só para o administrador.

| O que aparece | O que significa | O que fazer |
| --- | --- | --- |
| O sistema não abre no navegador | Docker Desktop fechado ou sistema parado | Abra o Docker Desktop e rode `docker compose up -d` |
| Mudei o código e nada mudou | A imagem não foi refeita | `docker compose up -d --build` |
| **Vermelho:** "O último backup falhou" | O backup não foi feito | Confira se o Docker Desktop está aberto e rode o backup agora. O motivo fica na última linha `FALHA` de `storage\backups\backup.log` |
| **Amarelo:** "...a cópia para o OneDrive falhou" | Backup local existe; a nuvem não recebeu | Confira se o OneDrive está conectado e com espaço |
| **Amarelo:** "...o 7-Zip não está instalado" | Falta o 7-Zip | `winget install 7zip.7zip` |
| **Amarelo:** "...a senha do backup (BACKUP_SENHA no .env) não está configurada" | Falta a senha ou ela tem menos de 12 caracteres | Preencha `BACKUP_SENHA` no `.env` |
| **Amarelo:** "...não foi possível apagar os backups antigos" | Algum backup antigo está preso (arquivo aberto, permissão) | Feche programas com arquivos de `storage\backups` abertos |
| **Amarelo:** "O último backup foi em ... (há N horas/dias)" | Mais de 26 horas sem backup | Computador desligado ou Docker fechado às 22:00. Deixe ligado ou rode o backup agora |
| **Vermelho:** "registro do último backup está ilegível ou com data inválida" | Arquivo de status estragado ou relógio errado | Acerte a data e a hora do Windows e rode o backup agora |
| **Amarelo:** "Nenhum backup foi feito ainda" | O backup nunca rodou | Faça o passo [4](#4-configurar-o-backup) |
| "Muitas tentativas" no login | Senha errada várias vezes | Espere o tempo indicado na tela, ou peça ao administrador para redefinir a senha |
| "Sua sessão foi encerrada" | Senha trocada/redefinida, usuário desativado ou 30 min sem usar | Entre de novo, ou fale com o administrador |
| A câmera não lê o código de barras no celular | Sem HTTPS a câmera não abre | Use leitor USB ou configure HTTPS ([passo 2](#2-instalar)) |

---

## 9. Para desenvolvedores

### Rodar sem Docker

```bash
php -S 127.0.0.1:8000 -t public
```

Precisa de PHP 8.4 com as extensões `pdo_sqlite`, `sqlite3` e `gd`. No Windows, se o PHP não carregar as extensões sozinho:

```bash
php -d extension_dir="$(dirname "$(which php)")/ext" -d extension=pdo_sqlite -d extension=sqlite3 -d extension=gd -S 127.0.0.1:8000 -t public
```

A senha inicial do admin aparece no próprio terminal. Com `APP_DEBUG=1` são criadas contas de demonstração (`vendedor@`, `estoque@` e `financeiro@petshop.com`, senha `senha123`, troca obrigatória); nunca use isso na loja.

### Testes

Testes das regras principais (lotes e validade, descontos, cancelamento e devolução, caixa às cegas, sangria, agenda, carteirinha, limites). Usam um banco temporário, apagado no fim: o banco real nunca é aberto.

```powershell
docker compose up -d --build
docker compose exec -T php php scripts/testar.php
```

### Dados de teste

**Apaga os dados** (menos as 4 contas principais) e cria dados de exemplo seguindo as regras do sistema: produtos, lotes vencendo, 90 dias de vendas, clientes, pets, carteirinha, agenda, um caixa por dia e despesas. Antes guarda uma cópia em `storage/antes-popular-AAAAMMDD-HHMMSS.sqlite`.

```powershell
docker compose exec -T php php scripts/popular.php --apagar-tudo
```

Nunca rode na loja com dados reais.

### Estrutura

```text
config/
├── config.php        # Dados da loja, banco e limites (desconto, sangria, agenda, cupom, alertas)
└── routes.php        # Páginas e perfis que acessam cada uma

app/
├── Database.php      # Criação e migração das tabelas SQLite
├── Models/           # Regras de negócio e SQL: Venda, Caixa, Estoque, Produto, Cliente, Agenda, Relatorio, Backup...
├── Pages/            # Uma página por tela
└── Views/            # Cabeçalho, rodapé, gráficos e quadros reaproveitados

public/
├── assets/
│   ├── css/          # Tema por cima do Bootstrap (menu lateral no PC, barra inferior no celular, cupom térmico)
│   ├── js/app.js     # JavaScript das telas
│   └── vendor/       # Bootstrap, Bootstrap Icons e ZXing (câmera no iPhone), locais, sem CDN
├── uploads/produtos/ # Fotos dos produtos
└── index.php         # Ponto de entrada (front controller)

scripts/
├── backup.php        # Backup (roda dentro do container)
├── backup.ps1        # Agendado no Windows: chama o backup.php e cifra para o OneDrive
├── popular.php       # Dados de teste (apaga os dados atuais)
└── testar.php        # Testes das regras (banco temporário)

storage/
├── petshop.sqlite    # Banco de dados (criado sozinho)
└── backups/          # Backups locais, backup.log e ultimo.json (status lido pelo Dashboard)
```

`storage/`, `.env` e as fotos ficam fora do git.

### Nova página

1. Crie `app/Pages/minhapagina.php`.
2. Registre em `config/routes.php`: `'minhapagina' => ['Título no menu', ['admin']]` (título `null` = fora do menu).
3. Coloque as regras e o SQL num Model em `app/Models/`.
4. Toda gravação: formulário com `csrf_campo()`, transação `BEGIN IMMEDIATE`, erro de regra como `DomainException` e registro em `Auditoria::registrar`.

### Segurança

* Acesso só local (`127.0.0.1`); para rede, proxy com HTTPS.
* `APP_DEBUG=0` em produção.
* Senhas com bcrypt; troca obrigatória da senha provisória; login bloqueado após muitas tentativas; sessão expira após 30 min sem uso e cai na hora ao trocar a senha ou desativar o usuário.
* Todos os formulários com token CSRF; permissões conferidas no servidor em cada página e ação.
* Backup no OneDrive cifrado (AES-256, nomes dos arquivos também escondidos). A senha fica no `.env` deste computador: guarde uma cópia fora dele.
