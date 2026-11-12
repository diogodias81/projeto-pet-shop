# Resultados dos experimentos – PetShop Gestão

Rodada de 12/11/2026, na base de teste: 444 vendas em cerca de 90 dias, 21 produtos, 14 clientes, 16 pets, 78 agendamentos e 78 caixas.
Ambiente: Docker Desktop no Windows 11, contêiner `php:8.4-apache` e SQLite; as medições foram feitas no próprio computador (localhost).
Para repetir, use os comandos indicados em cada seção, a partir da pasta do projeto.

| Exp. | O que mede | Resultado | Meta | Situação |
|---|---|---|---|---|
| E1 | Corretude das regras | 230 de 230 testes aprovados | 100% | Atingida |
| E2 | Segurança | 19 de 19 verificações automáticas; ASVS N1: 14 itens atendem, 3 parciais, 1 não atende | – | Feito; há melhorias |
| E3 | Desempenho | p95 entre 369 e 482 ms nas 11 telas | p95 < 1 s | Atingida |
| E4 | Usabilidade | Material pronto; falta aplicar com 5 participantes | SUS ≥ 68 | Pendente |
| E5 | Backup | Restauração íntegra; 21 de 21 tabelas iguais; 0,04 s | Sem perda | Atingida |

## E1 – Corretude das regras de negócio

Comando: `docker compose exec -T php php scripts/testar.php`

Resultado: **230 verificações, todas aprovadas.** A suíte roda em um banco temporário e cobre:
- venda pelo lote que vence primeiro e bloqueio de lote vencido;
- limites de desconto por perfil;
- cancelamento e devolução;
- caixa: contagem às cegas, limites de sangria e estorno;
- agenda: choque de horário e pagamento separado do andamento;
- carteirinha, permissões, senhas, limites de texto e índices do banco.

## E2 – Segurança

### Verificações automáticas na aplicação rodando

Comando: `bash artigo/experimentos/verificar_seguranca.sh` (saída em `resultado-e2.txt`)

Resultado: **19 ok, 0 falhas.** O script conferiu:
- **Cabeçalhos:** CSP, nosniff, X-Frame-Options, Referrer-Policy e Permissions-Policy.
- **Cookie de sessão:** marcado HttpOnly e SameSite=Lax.
- **Servidor:** não revela a versão do Apache nem do PHP.
- **Arquivos protegidos:** a pasta `storage` está bloqueada e não há listagem de pastas.
- **Telas internas:** sem login, mandam para a tela de login.
- **CSRF:** login sem token é recusado.
- **Sessão:** o identificador muda no login, contra fixação de sessão.
- **Perfil:** o vendedor recebe 403 em 5 telas restritas.
- **Sair:** só funciona por POST.
- **POST sem token:** recusado com 400.

### Lista do OWASP ASVS 4.0.3, nível 1 (itens principais)

| Requisito | Situação | Evidência no sistema |
|---|---|---|
| V2.1.1 Senha com pelo menos 12 caracteres | **Não atende** | O mínimo hoje é 6 caracteres (`Usuario::validarSenha`) |
| Senhas vazadas ou comuns recusadas | Parcial | Recusa uma lista local de senhas comuns, o nome e o e-mail; não consulta uma base de senhas vazadas |
| Sem regras de composição obrigatórias | Atende | Não exige maiúscula, símbolo etc.; orienta usar uma frase |
| V2.2.1 Proteção contra força bruta | Atende | Bloqueio de 15 min após 5 erros por e-mail e IP, ou 100 erros por IP |
| Mensagem de login sem revelar se o e-mail existe | Atende | Mesma mensagem e mesmo tempo de resposta (`HASH_FALSO`) |
| Senha guardada com *hash* lento | Atende | bcrypt com custo 12; é regravada no login se o custo subir |
| Troca da senha provisória | Atende | Obrigatória no primeiro acesso (`trocar_senha`) |
| V3.2.1 Nova sessão no login | Atende | `session_regenerate_id(true)` (verificado pelo script) |
| V3.3.1 Sair invalida a sessão | Atende | `session_destroy` e o cookie é apagado |
| Sessão expira por inatividade | Atende | 30 min (`sessao_inatividade_min`) |
| V3.4.1 a V3.4.3 Cookie Secure, HttpOnly e SameSite | Parcial | HttpOnly e SameSite sempre; Secure só atrás de HTTPS |
| V4 Controle de acesso no servidor | Atende | `exigirPerfil` em toda rota; regras de dono, por exemplo "vendedor vê só as vendas dele" |
| V4.2.2 Proteção contra CSRF | Atende | Token em todo POST (`csrf_validar`) |
| V5.3.4 Consultas parametrizadas | Atende | PDO com `prepare` em todas as consultas que recebem dados do usuário |
| V5 Codificação da saída (XSS) | Atende | `e()` (htmlspecialchars) nas telas, mais uma CSP sem scripts inline |
| V7 Erros sem detalhes técnicos e registro de eventos | Atende | Página de erro genérica; auditoria de logins, vendas, caixa e usuários |
| V9.1.1 Comunicação com TLS (HTTPS) | Parcial | O app escuta só em 127.0.0.1; o HTTPS depende do proxy descrito no README, ainda não instalado |
| V12 Envio de arquivos | Atende | Tipo conferido pelo conteúdo (finfo), tamanho limitado, imagem regravada, pasta sem PHP |

**Melhorias apontadas pelo experimento:**
1. Subir o tamanho mínimo da senha para 12 caracteres (V2.1.1).
2. Instalar o HTTPS antes de acessar o sistema de outros aparelhos (V9.1.1). Com isso o cookie passa a ser marcado Secure.

## E3 – Desempenho

Comando: `bash artigo/experimentos/medir_desempenho.sh 30` (saída em `resultado-e3.txt`)

São 30 medições por tela, com 1 de aquecimento, usando as contas do perfil de cada tela.

| Tela | Média (ms) | Mediana | p95 | Máx. |
|---|---:|---:|---:|---:|
| Dashboard (mês atual) | 384 | 377 | 409 | 548 |
| Dashboard (90 dias) | 399 | 400 | 425 | 430 |
| Relatórios (90 dias) | 435 | 429 | 482 | 491 |
| Histórico de vendas (90 dias) | 378 | 372 | 411 | 413 |
| Caixa | 356 | 351 | 375 | 466 |
| Despesas | 348 | 349 | 369 | 374 |
| Vendas (PDV) | 367 | 364 | 393 | 398 |
| Clientes | 354 | 353 | 381 | 383 |
| Agenda (hoje) | 353 | 351 | 393 | 437 |
| Produtos | 372 | 373 | 394 | 397 |
| Estoque | 382 | 383 | 405 | 406 |

**Todas as telas ficaram abaixo da meta** (p95 < 1 s). O tempo varia pouco entre telas simples (cerca de 350 ms) e o relatório de 90 dias (cerca de 430 ms). Isso indica que a maior parte do tempo é fixa, vinda do Docker Desktop no Windows, que lê os arquivos da pasta compartilhada, e não das consultas. O teste `scripts/testar.php` também confere, pelo `EXPLAIN QUERY PLAN`, que as consultas principais usam índices.

## E4 – Usabilidade

O material está pronto para imprimir em `teste-usabilidade.pdf`, com 3 páginas:
- roteiro do avaliador;
- ficha com 5 tarefas: venda com troco, agendamento, entrada de lote, sangria e fechamento do caixa, cadastro de cliente e pet;
- questionário SUS.

**Ainda falta aplicar o teste com 5 participantes.** Os indicadores são taxa de conclusão, tempo por tarefa, número de erros e nota SUS (meta: média ≥ 68). Use a base de teste e restaure a cópia do banco entre um participante e outro.

## E5 – Confiabilidade do backup

Comandos:
`docker compose exec -T -u www-data php php scripts/backup.php`
`docker compose exec -T -u www-data php php < artigo/experimentos/testar_restauracao.php` (saída em `resultado-e5.txt`)

Resultado: o backup `2026-11-12_162511` foi restaurado em uma pasta temporária em **0,04 s**. A verificação `integrity_check` deu **ok** e **as 21 tabelas têm o mesmo número de linhas** do banco em uso. O banco restaurado ocupa 0,5 MB contra 4,8 MB do original, porque o `VACUUM INTO` compacta o arquivo.

Limite deste teste: ele não abriu a cópia cifrada no OneDrive (.7z), que depende da senha guardada pelo dono. A restauração completa pelo README já foi testada quando o backup foi implantado (início de novembro de 2026).
