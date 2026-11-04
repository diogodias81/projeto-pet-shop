---
name: protocolo-de-revisao
description: Protocolo de Revisão do PetShop Gestão. Roda, em ordem, os agentes Hacker Blue Team, Engenheiro de Segurança e Usuário Comum, com o Coordenador de Agentes aprovando cada etapa. Use quando o usuário pedir "Protocolo de Revisão" e, por conta própria, depois de alterações importantes (novas telas, regras de venda/estoque, login, permissões, upload, banco).
---

# Protocolo de Revisão

Você (a sessão principal) conduz o protocolo. Os agentes ficam em `.claude/agents/`: `blue-team`, `engenheiro-seguranca`, `usuario-comum`, `coordenador`.
Avise o usuário em português no início e a cada etapa concluída.

## Passo 0 — Preparar o ambiente
1. Backup do banco: `cp storage/petshop.sqlite "$TEMP/protocolo-backup.sqlite"` (se existir).
2. Subir o app em 127.0.0.1:8099 em segundo plano. O PHP local não carrega o SQLite sozinho:
   `php -d extension_dir="$(dirname "$(which php)")/ext" -d extension=pdo_sqlite -d extension=sqlite3 -d extension=gd -S 127.0.0.1:8099 -t public`
3. Confirmar que `curl http://127.0.0.1:8099/index.php` responde 302 para o login.
4. Anotar `git status --short` e `git rev-parse HEAD` (base para o diff do Engenheiro).

Em todo pedido a um agente, passe: URL `http://127.0.0.1:8099/index.php`, o que mudou desde o último commit (se for revisão de uma alteração) e o relatório da etapa anterior quando houver.

## Passo 1 — Hacker Blue Team
1. Rodar o agente `blue-team`.
2. Rodar o `coordenador` com o relatório. Se REPROVADO, mandar as pendências de volta ao `blue-team` (SendMessage para o mesmo agente) e repetir até APROVADO.

## Passo 2 — Engenheiro de Segurança
Só começa com o relatório do Blue Team aprovado. Se não houver brechas, pule para o Passo 3.
1. Rodar o `engenheiro-seguranca` com o relatório completo do Blue Team.
2. Rodar o `coordenador` com o relatório do Engenheiro + o do Blue Team. Repetir até APROVADO.
3. Rodar o `blue-team` de novo só para reteste das brechas corrigidas (e procurar regressões). Se algo reabrir, voltar ao item 1.

## Passo 3 — Usuário Comum
Pode rodar em paralelo ao Passo 1 (não altera arquivos), mas precisa ser **refeito** se o Passo 2 mudou código.
1. Rodar o `usuario-comum`.
2. Rodar o `coordenador` com o relatório. Repetir até APROVADO.
3. Bugs encontrados: **não corrija por conta própria**. Liste para o usuário e pergunte quais corrigir.

## Passo 4 — Encerrar
1. Parar o servidor PHP e restaurar o banco: `cp "$TEMP/protocolo-backup.sqlite" storage/petshop.sqlite`.
2. Apagar arquivos de teste que os agentes criaram em `public/uploads/` (compare com o início).
3. Entregar ao usuário um resumo: brechas (encontradas / corrigidas / pendentes), bugs de uso, arquivos alterados e o veredito final do coordenador.
4. **Nunca** faça commit ou push: pergunte ao usuário antes.
