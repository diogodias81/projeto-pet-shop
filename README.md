# PetShop Gestão

Sistema de estoque e vendas para pet shop, feito em **PHP + JavaScript + CSS** com banco **SQLite**, rodando no **Docker**.

Este guia está em etapas, na ordem em que acontecem:

1. [Instalar (uma vez)](#etapa-1--instalar-uma-vez)
2. [Primeiro acesso](#etapa-2--primeiro-acesso)
3. [Configurar o backup (uma vez)](#etapa-3--configurar-o-backup-uma-vez)
4. [Cadastrar categorias e produtos](#etapa-4--cadastrar-categorias-e-produtos)
5. [Dar entrada no estoque](#etapa-5--dar-entrada-no-estoque)
6. [Vender](#etapa-6--vender)
7. [Cancelar venda ou devolver produto](#etapa-7--cancelar-venda-ou-devolver-produto)
8. [Registrar despesas](#etapa-8--registrar-despesas)
9. [Acompanhar resultados](#etapa-9--acompanhar-resultados)
10. [Rotina diária e semanal](#etapa-10--rotina-diária-e-semanal)
11. [Restaurar um backup](#etapa-11--restaurar-um-backup)
12. [Atualizar o sistema](#etapa-12--atualizar-o-sistema)

Depois vêm [Avisos e problemas comuns](#avisos-e-problemas-comuns) e a parte [Para desenvolvedores](#para-desenvolvedores).

## Quem faz o quê

| Perfil | O que acessa | Etapas |
| --- | --- | --- |
| **Administrador** | Tudo, inclusive usuários, auditoria, cancelamentos e devoluções | Todas |
| **Vendedor** | Vendas, o próprio histórico, consulta de produtos e estoque. Desconto de até 10% | 6 |
| **Responsável pelo estoque** | Produtos, categorias e estoque (entradas e baixas) | 4, 5 |
| **Financeiro** | Dashboard, relatórios, histórico de vendas e despesas | 8, 9 |

Todos trocam a própria senha pelo link **Minha senha**, ao lado do **Sair**.

---

## Etapa 1 — Instalar (uma vez)

**Quem faz:** o técnico, no computador da loja.

1. Instale os programas:
   * [Docker Desktop](https://www.docker.com/products/docker-desktop/). Nas configurações dele, marque **Start Docker Desktop when you sign in** (abrir com o Windows): o sistema e o backup das 22:00 dependem disso.
   * [Git](https://git-scm.com/).
   * [7-Zip](https://www.7-zip.org/) (cifra o backup que vai para o OneDrive). Pelo PowerShell: `winget install 7zip.7zip`.
   * OneDrive conectado à conta da loja (já vem no Windows).
2. Baixe o sistema. No PowerShell:

   ```powershell
   cd C:\Users\<usuário>
   git clone https://github.com/diogodias81/projeto-pet-shop.git
   cd projeto-pet-shop
   ```

   **Todos os comandos deste guia rodam dentro dessa pasta** (`cd C:\Users\<usuário>\projeto-pet-shop` antes).

3. Crie o arquivo de configuração `.env` a partir do modelo:

   ```powershell
   copy .env.example .env
   notepad .env
   ```

   Preencha:
   * `ADMIN_SENHA`: senha inicial do administrador (opcional; vazia = o sistema sorteia uma, veja a Etapa 2).
   * `BACKUP_SENHA`: senha da cifra dos backups no OneDrive, **com pelo menos 12 caracteres**.
   * `APP_DEBUG=0` (nunca `1` na loja).

   > **Guarde a `BACKUP_SENHA` fora deste computador** (gerenciador de senhas ou papel em lugar seguro). Se o computador quebrar, os backups do OneDrive só abrem com ela. O `.env` nunca vai para o git nem para o Docker.

4. Suba o sistema:

   ```powershell
   docker compose up -d --build
   ```

5. Abra no navegador: **http://localhost:8000**

O sistema só é acessível neste computador (`127.0.0.1:8000`). Para usar em outros computadores da rede, coloque um proxy com HTTPS na frente (ex.: Caddy ou nginx); não troque a porta para `0.0.0.0`.

## Etapa 2 — Primeiro acesso

**Quem faz:** o administrador.

1. Entre com **admin@petshop.com** e a senha inicial:
   * a do `ADMIN_SENHA` do `.env`, se você preencheu; ou
   * a sorteada, que aparece **uma única vez** no log. Abra a página do sistema uma vez e rode:

     ```powershell
     docker compose logs php | findstr "senha inicial"
     ```

   Perdeu a senha antes do primeiro acesso? Pare o sistema (`docker compose down`), apague `storage\petshop.sqlite` (**só se ainda não há dados**) e suba de novo.
2. O sistema obriga a **trocar a senha** no primeiro acesso. Depois, apague a linha `ADMIN_SENHA` do `.env` (a senha passa a ficar só no banco).
3. Em **Usuários**, crie uma conta para cada pessoa, com o perfil certo (tabela acima) e uma senha provisória. Cada um troca a senha no primeiro acesso.
   * Esqueceu a senha: o administrador usa **Redefinir senha** e passa uma nova senha provisória.
   * Saiu da loja: use **Desativar** (a pessoa perde o acesso na hora, e o histórico dela fica). **Reativar** devolve o acesso.
   * O sistema nunca deixa ficar sem nenhum administrador ativo.

## Etapa 3 — Configurar o backup (uma vez)

**Quem faz:** o técnico.

Todo dia às 22:00 o Windows roda `scripts\backup.ps1`, que:

1. faz o backup dentro do sistema: cópia segura do banco (mesmo com gente vendendo) conferida contra defeitos, mais as fotos dos produtos, em `storage\backups\AAAA-MM-DD_HHMMSS\`. A pasta só ganha esse nome quando tudo deu certo;
2. apaga os backups com mais de 30 dias, mas **sempre mantém os 7 mais novos**;
3. cria um arquivo **cifrado** (7-Zip, AES-256, com a `BACKUP_SENHA`) de cada backup novo em `OneDrive\Backups PetShop\AAAA-MM-DD_HHMMSS.7z`, que sobe para a nuvem sozinho. Lá vale a mesma regra de 30 dias / 7 mais novos. Quem entrar na conta do OneDrive não consegue abrir os arquivos sem a senha.

Se o computador estiver desligado às 22:00, a tarefa roda quando ele ligar e espera até 10 minutos o Docker Desktop abrir. Se o sistema estiver parado, a tarefa o sobe.

**Para agendar**, abra o PowerShell e cole os comandos abaixo, **trocando `C:\caminho\do\projeto-pet-shop` pela pasta do sistema** (ex.: `C:\Users\loja\projeto-pet-shop`):

```powershell
# Criar a tarefa (uma vez; se já existe uma antiga, apague antes com: Unregister-ScheduledTask 'PetShop Backup' -Confirm:$false)
$acao = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument '-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File "C:\caminho\do\projeto-pet-shop\scripts\backup.ps1"'
Register-ScheduledTask -TaskName 'PetShop Backup' -Action $acao -Trigger (New-ScheduledTaskTrigger -Daily -At 22:00) -Settings (New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Hours 1))

# Testar agora (faz um backup completo)
Start-ScheduledTask -TaskName 'PetShop Backup'
```

**Para conferir se funcionou:** abra `storage\backups\backup.log` (a última linha deve ser `OK`) e veja se apareceu um arquivo `.7z` novo em `OneDrive\Backups PetShop`. O Dashboard do administrador avisa quando algo dá errado (veja [Avisos e problemas comuns](#avisos-e-problemas-comuns)).

## Etapa 4 — Cadastrar categorias e produtos

**Quem faz:** administrador ou responsável pelo estoque.

1. Em **Categorias**, crie os grupos (ex.: Ração, Petiscos, Higiene, Acessórios).
2. Em **Produtos**, cadastre cada item:
   * **Nome** e **categoria**;
   * **Código de barras** (4 a 14 números): leia com o leitor ou digite. É ele que acha o produto na hora da venda;
   * **Preço de custo** e **preço de venda**: a lista mostra o lucro por unidade e a **margem** (lucro ÷ preço de venda); em **Relatórios → Rentabilidade** aparece também o **acréscimo** (lucro ÷ custo). Sem preço de custo, os relatórios contam o lucro com custo zero;
   * **Estoque mínimo**: abaixo dele o produto aparece em alerta;
   * **Foto** (opcional, até 2 MB).
3. A quantidade em estoque **não** é digitada aqui: ela vem das entradas da Etapa 5.

## Etapa 5 — Dar entrada no estoque

**Quem faz:** administrador ou responsável pelo estoque. O vendedor só consulta.

1. Em **Estoque → Registrar entrada**: escolha o produto (ou leia o código de barras), a quantidade, o **lote** e a **validade**. Deixe a validade vazia para produtos que não vencem.
2. Na venda, o sistema tira primeiro do lote que vence antes e **não vende de lote vencido**.
3. **Lotes e validade** mostra os lotes vencidos e os que vencem em até 30, 15 e 7 dias. Venda ou dê destino a eles primeiro.
4. Produto vencido, avariado ou perdido: **Dar baixa**, com o motivo. A baixa sai do estoque e fica registrada.
5. **Movimentações de estoque** mostra tudo o que entrou e saiu, com data, usuário e motivo.

## Etapa 6 — Vender

**Quem faz:** vendedor ou administrador.

1. Em **Vendas**, leia o código de barras ou digite o nome do produto. O produto entra no carrinho; ajuste a quantidade se precisar.
2. **Desconto** (opcional), em valor (R$) ou em %. O vendedor pode dar até **10%**; acima disso, só o administrador.
3. Escolha a **forma de pagamento**: dinheiro, PIX, cartão de débito ou de crédito. No dinheiro, digite o valor recebido e o sistema calcula o **troco**.
4. Clique em **Finalizar**. Abre o **comprovante**: use **Imprimir / Salvar PDF** se o cliente quiser.
5. Em **Histórico**, veja as vendas (o vendedor vê só as dele) e abra o comprovante de qualquer uma.

## Etapa 7 — Cancelar venda ou devolver produto

**Quem faz:** só o administrador (o vendedor chama um administrador).

1. Em **Histórico**, abra a venda.
2. **Cancelar venda**: anula a venda inteira, com motivo. Os produtos voltam para o estoque.
3. **Devolver**: escolha os itens e as quantidades devolvidas, com motivo (ex.: produto com defeito). O valor devolvido sai do faturamento.
4. Tudo fica registrado em **Auditoria**.

## Etapa 8 — Registrar despesas

**Quem faz:** administrador ou financeiro.

Em **Despesas → Registrar despesa**: descrição (ex.: conta de luz de outubro), categoria, valor e data (não aceita data futura). As despesas entram no **resultado** do Dashboard. Lançou errado? Use **Excluir** na lista.

## Etapa 9 — Acompanhar resultados

**Quem faz:** administrador ou financeiro.

* **Dashboard**: faturamento de hoje, dos últimos 7 dias e do mês; para o período escolhido, vendas, ticket médio, lucro bruto, despesas e **resultado** (lucro bruto − despesas), mais gráficos de faturamento e dos mais vendidos. O administrador também vê aqui os avisos de backup.
* **Relatórios**: vendas do período, mais vendidos, **rentabilidade por produto**, posição do estoque, movimentações, despesas por categoria e devoluções. Use **Exportar planilha** (CSV, abre no Excel) ou **Imprimir / Salvar PDF**.
* **Auditoria** (só administrador): quem fez o quê e quando (logins, vendas, cancelamentos, alterações de usuários, etc.).

## Etapa 10 — Rotina diária e semanal

| Quando | O quê | Quem |
| --- | --- | --- |
| Ao abrir a loja | Ligue o computador; confira se o Docker Desktop abriu (ícone da baleia) e se o sistema abre em http://localhost:8000 | Quem abre |
| Ao abrir a loja | Olhe o **Dashboard**: sem aviso amarelo ou vermelho de backup = tudo certo | Administrador |
| Todo dia | Vendas e entradas de estoque conforme acontecem | Vendedor / estoque |
| Ao fechar a loja | **Deixe o computador ligado** até depois das 22:00 (hora do backup), ou ele faz o backup ao ligar no dia seguinte | Quem fecha |
| Toda semana | **Lotes e validade**: tire da prateleira ou dê baixa no que venceu | Estoque |
| Toda semana | Confira se há arquivos `.7z` recentes em `OneDrive\Backups PetShop` | Administrador |
| Todo mês | **Relatórios** e despesas do mês | Financeiro |

## Etapa 11 — Restaurar um backup

**Quem faz:** o técnico, quando o banco se perdeu ou estragou. **Tudo o que foi registrado depois do horário do backup se perde.**

1. **Escolha o backup.**
   * Neste computador: em `storage\backups\backup.log`, a pasta precisa aparecer numa linha `OK` (ex.: `2026-11-02 22:00:05 OK 2026-11-02_220005: Backup ok em ...`). Pasta sem linha `OK` não serve.
   * No OneDrive (computador perdido ou pasta `storage` apagada): pegue o `.7z` mais recente em `OneDrive\Backups PetShop` e abra com o 7-Zip (botão direito → 7-Zip → Extrair aqui), digitando a `BACKUP_SENHA`. Ou pelo PowerShell:

     ```powershell
     & "C:\Program Files\7-Zip\7z.exe" x "$env:OneDrive\Backups PetShop\2026-11-02_220005.7z" -oC:\restaurar
     ```

     Dentro estão o `petshop.sqlite` e a pasta `fotos`. Todo `.7z` no OneDrive é de um backup que deu certo.
2. Pare o sistema: `docker compose down`
3. Guarde o banco atual por segurança: renomeie `storage\petshop.sqlite` para `storage\petshop-antes-da-restauracao.sqlite`. Se existir `storage\petshop.sqlite-journal`, apague (é de uma gravação interrompida e estragaria o banco restaurado).
4. Copie o `petshop.sqlite` do backup para `storage\petshop.sqlite`.
5. Copie as fotos da pasta `fotos` do backup para `public\uploads\produtos\`.
6. Suba de novo: `docker compose up -d`
7. Entre como administrador e confira em **Usuários**: quem trocou de senha depois do backup volta a entrar com a senha antiga, e quem foi desativado depois do backup volta ativo. Redefina essas senhas e desative de novo quem não deve entrar.

## Etapa 12 — Atualizar o sistema

**Quem faz:** o técnico.

```powershell
git pull
docker compose up -d --build
```

O `--build` é obrigatório: o código do sistema fica dentro da imagem do Docker, e sem ele o sistema continua na versão antiga. O banco, as fotos e os backups (pasta `storage` e `public\uploads`) não são afetados.

Outros comandos úteis:

| Para | Comando |
| --- | --- |
| Parar o sistema | `docker compose down` |
| Ligar o sistema | `docker compose up -d` |
| Ver se está rodando | `docker compose ps` |
| Ver o log (erros) | `docker compose logs -f` |

---

## Avisos e problemas comuns

Os avisos de backup aparecem no topo do **Dashboard**, só para o administrador.

| O que aparece | O que significa | O que fazer |
| --- | --- | --- |
| **Vermelho:** "O último backup falhou" | O backup não foi feito | Confira se o Docker Desktop está aberto. O próximo roda às 22:00; para fazer agora, rode `Start-ScheduledTask -TaskName 'PetShop Backup'`. O motivo fica na última linha `FALHA` de `storage\backups\backup.log` |
| **Amarelo:** "foi feito e está guardado neste computador, mas a cópia para o OneDrive falhou" | Backup local existe; a nuvem não recebeu | Confira se o OneDrive está conectado e com espaço |
| **Amarelo:** "...mas o 7-Zip não está instalado" | Falta o 7-Zip | `winget install 7zip.7zip` |
| **Amarelo:** "...mas a senha do backup (BACKUP_SENHA no .env) não está configurada" | Falta a senha da cifra ou ela tem menos de 12 caracteres | Preencha `BACKUP_SENHA` no `.env` (Etapa 1) |
| **Amarelo:** "...mas não foi possível apagar os backups antigos" | Algum backup antigo está preso (arquivo aberto, permissão) | Feche programas que estejam com arquivos de `storage\backups` abertos; se continuar, chame o técnico |
| **Amarelo:** "O último backup foi em ... (há N horas/dias)" | Passou mais de 26 horas sem backup | O computador estava desligado ou o Docker fechado às 22:00. Deixe ligado ou rode o backup agora |
| **Vermelho:** "registro do último backup está ilegível ou com data inválida" | O arquivo de status do backup estragou ou o relógio está errado | Acerte a data e a hora do Windows e rode o backup agora |
| **Amarelo:** "Nenhum backup foi feito ainda" | O backup nunca rodou | Faça a Etapa 3 |
| O sistema não abre no navegador | O Docker Desktop está fechado ou o sistema parado | Abra o Docker Desktop e rode `docker compose up -d` |
| "Muitas tentativas" no login | Senha errada várias vezes; o acesso fica bloqueado por alguns minutos | Espere o tempo indicado na tela, ou peça ao administrador para redefinir a senha |
| "Sua sessão foi encerrada" | A senha foi trocada/redefinida ou o usuário foi desativado | Entre de novo com a senha nova, ou fale com o administrador |
| Desconto recusado na venda | Acima de 10% para o vendedor | Chame um administrador |

---

## Para desenvolvedores

### Rodar sem Docker

```bash
php -S 127.0.0.1:8000 -t public
```

Precisa das extensões `pdo_sqlite` e `gd`. A senha inicial do admin aparece no próprio terminal. Com `APP_DEBUG=1` são criadas contas de demonstração (`vendedor@`, `estoque@` e `financeiro@petshop.com`, senha `senha123`, troca obrigatória); nunca use isso na loja.

### Estrutura

```text
config/
├── config.php        # Nome do sistema, banco, limites (desconto do vendedor, alertas de validade)
└── routes.php        # Páginas e perfis que acessam cada uma

app/
├── Database.php      # Criação das tabelas SQLite
├── Models/           # Regras de negócio e consultas SQL (Backup, Venda, Estoque, Produto...)
├── Pages/            # Páginas do sistema
└── Views/            # Cabeçalho, rodapé, gráficos e alertas comuns

public/
├── assets/           # CSS, JavaScript e Bootstrap (local)
├── uploads/produtos/ # Fotos dos produtos
└── index.php         # Ponto de entrada

scripts/
├── backup.php        # Backup (roda dentro do container)
└── backup.ps1        # Agendado no Windows: chama o backup.php e cifra para o OneDrive

storage/
├── petshop.sqlite    # Banco de dados (criado sozinho)
└── backups/          # Backups locais, backup.log e ultimo.json (status lido pelo Dashboard)
```

`storage/`, `.env` e as fotos ficam fora do git.

### Nova página

1. Crie `app/Pages/minhapagina.php`.
2. Registre em `config/routes.php`: `'minhapagina' => ['Título no menu', ['admin']]` (título `null` = fora do menu).
3. Coloque as regras e o SQL num Model em `app/Models/`.

### Mapa de requisitos

```text
RF01–RF03 → Produtos/Categorias
RF04      → Estoque
RF05–RF08 → Vendas
RF09–RF10 → Produtos/Estoque
RF11–RF12 → Vendas/Relatórios
RF13–RF15 → Dashboard
```

### Segurança

* Acesso só local (`127.0.0.1`); para rede, proxy com HTTPS.
* `APP_DEBUG=0` em produção.
* Senhas com bcrypt; troca obrigatória da senha provisória; login bloqueado após muitas tentativas.
* Backup no OneDrive cifrado (AES-256, nomes dos arquivos também escondidos). A senha fica no `.env` deste computador: guarde uma cópia fora dele.
