# PetShop Gestão — Como funciona

Este documento explica **o que o sistema faz, quem usa cada parte e quais regras ele segue**. Para instalar e rodar, veja o [README.md](README.md).

Sumário:

* [Visão geral](#visão-geral)
* [Perfis de acesso](#perfis-de-acesso)
* [Telas e funcionalidades](#telas-e-funcionalidades)
  * [Dashboard](#dashboard)
  * [Caixa](#caixa)
  * [Vendas](#vendas)
  * [Comprovante e cupom](#comprovante-e-cupom)
  * [Histórico: cancelar e devolver](#histórico-cancelar-e-devolver)
  * [Clientes e pets](#clientes-e-pets)
  * [Carteirinha: vacinas, vermífugos e antipulgas](#carteirinha-vacinas-vermífugos-e-antipulgas)
  * [Agenda de banho e tosa](#agenda-de-banho-e-tosa)
  * [Produtos e categorias](#produtos-e-categorias)
  * [Estoque, lotes e validade](#estoque-lotes-e-validade)
  * [Despesas](#despesas)
  * [Relatórios](#relatórios)
  * [Usuários, senhas e auditoria](#usuários-senhas-e-auditoria)
  * [Backup](#backup)
* [Rotina da loja](#rotina-da-loja)
* [Usando no celular](#usando-no-celular)

---

## Visão geral

O PetShop Gestão é um sistema web para o dia a dia de um pet shop. Ele roda no computador da loja e é usado pelo navegador, no computador ou no celular.

| Área | O que resolve |
| --- | --- |
| **Vendas e caixa** | Vender produtos e serviços, controlar o dinheiro da gaveta, imprimir comprovante |
| **Clientes e pets** | Ficha do cliente com pets, compras, banhos e carteirinha de vacinas |
| **Banho e tosa** | Agenda com horários, andamento do atendimento, cobrança e lembrete por WhatsApp |
| **Estoque** | Entradas por lote e validade, baixa de vencidos e perdas, alertas de reposição |
| **Financeiro** | Faturamento, lucro, despesas, resultado e relatórios exportáveis |
| **Segurança** | Perfis de acesso, senhas, auditoria de tudo e backup diário cifrado |

## Perfis de acesso

Cada pessoa entra com o próprio e-mail e senha, e vê só as telas do seu perfil.

| Tela | Administrador | Vendedor | Estoque | Financeiro |
| --- | :---: | :---: | :---: | :---: |
| Dashboard | ✔ | | | ✔ |
| Vendas | ✔ | ✔ | | |
| Caixa | ✔ | ✔ (conta às cegas) | | ✔ (só consulta) |
| Histórico de vendas | ✔ (cancela e devolve) | ✔ (só as dele) | | ✔ |
| Clientes e pets | ✔ | ✔ | | |
| Agenda | ✔ (cadastra serviços) | ✔ | | |
| Produtos | ✔ | consulta | ✔ | |
| Estoque | ✔ | consulta | ✔ | |
| Categorias | ✔ | | ✔ | |
| Despesas | ✔ | | | ✔ |
| Relatórios | ✔ | | | ✔ |
| Usuários e Auditoria | ✔ | | | |

Todos trocam a própria senha em **Minha senha**, ao lado de **Sair**.

---

## Telas e funcionalidades

### Dashboard

A primeira tela do administrador e do financeiro.

* **Para fazer hoje:** lista o que precisa de atenção, com atalho para resolver: avisos de backup, lotes vencidos ou vencendo, produtos para repor, doses da carteirinha vencidas ou vencendo.
* **Faturamento** de hoje, dos últimos 7 dias e do mês, comparados com o período anterior.
* **Desempenho** do período escolhido (hoje, 7 dias, este mês ou datas livres): faturamento, número de vendas, ticket médio, lucro bruto estimado, despesas e **resultado** (lucro bruto − despesas).
* Gráfico de faturamento por dia e lista dos mais vendidos.
* **Alertas de estoque:** lotes vencidos, vencendo em até 7, 15 e 30 dias e estoque baixo.
* **Vacinas, vermífugos e antipulgas:** próximas doses, com botão para lembrar o dono pelo WhatsApp.

### Caixa

Controla o dinheiro da gaveta, do começo ao fim do dia. **Só dá para vender com o caixa aberto.**

1. **Abrir caixa:** conte o troco que está na gaveta e informe o valor.
2. **Sangria / reforço:** retirada de dinheiro da gaveta (ex.: levar ao banco) ou entrada (ex.: mais troco), com motivo.
   * O vendedor pode fazer sangrias de até **R$ 500,00 somadas no dia**; acima disso, só o administrador.
   * Nenhum movimento passa de R$ 100.000,00.
3. **Fechar caixa:** conte todo o dinheiro da gaveta e informe o valor contado.
   * **Contagem às cegas:** enquanto o caixa está aberto, o vendedor **não vê** quanto deveria ter na gaveta. Assim ele conta de verdade, sem "acertar" o número.
   * Depois de fechado, o sistema mostra o **esperado** (troco + vendas em dinheiro + reforços − sangrias − estornos em dinheiro), o **contado** e se **bateu**, sobrou ou faltou.
   * O administrador e o financeiro veem o resumo por forma de pagamento (vendas, cancelado/devolvido e líquido).
4. **Caixas fechados:** os últimos 10 aparecem na tela; **Ver mais** mostra os últimos 60. Cada um abre com o detalhe dos movimentos.

### Vendas

A tela de venda (PDV).

1. **Adicionar produtos:** leia o código de barras (leitor USB ou câmera do celular) ou digite o nome. A busca ignora acento e maiúsculas ("racao" acha "Ração").
2. **Ajustar a quantidade** ou remover itens. O carrinho fica guardado no navegador se a página recarregar.
3. **Cliente (opcional):** busque por nome ou telefone, ou cadastre um novo sem perder o carrinho.
4. **Serviços de banho e tosa** do cliente chegam da Agenda pelo botão **Cobrar no caixa** e entram na mesma venda.
5. **Desconto** em R$ ou %. O vendedor pode dar até **10%**; acima disso, só o administrador.
6. **Forma de pagamento:** dinheiro, PIX, cartão de débito ou crédito. No dinheiro, informe o valor recebido e o sistema calcula o **troco**.
7. **Finalizar venda:** baixa o estoque (do lote que vence primeiro) e abre o comprovante.

Regras importantes:
* Não vende de lote vencido nem mais do que há em estoque.
* Clicar duas vezes em Finalizar ou recarregar a página não grava a venda em dobro.
* Enter nos campos de quantidade, desconto ou valor recebido não finaliza a venda: só o botão finaliza.

### Comprovante e cupom

Depois da venda aparece **Venda concluída**, com o valor e o troco.

* **Imprimir cupom:** formatado para impressora térmica de 80 mm ou 58 mm (configurável).
* **Nova venda:** volta ao PDV vazio.
* **Folha A4 / PDF:** comprovante em folha comum ou salvo em PDF.
* O comprovante traz os dados da loja, itens, serviços, desconto, forma de pagamento e troco. Não é documento fiscal.

### Histórico: cancelar e devolver

Lista as vendas com filtro por data. O vendedor vê só as dele.

* **Cancelar venda** (só administrador): anula a venda inteira, com motivo. Os produtos voltam ao estoque e os serviços voltam para a agenda sem pagamento.
* **Devolver** (só administrador): escolha os itens e as quantidades devolvidas, com motivo. O valor sai do faturamento.
* Estorno em **dinheiro** exige o caixa aberto e não pode passar do dinheiro que há na gaveta (se faltar, faça um reforço ou devolva por PIX).

### Clientes e pets

* **Lista de clientes** com busca por nome, telefone ou nome do pet, sem se importar com acento.
* Telefone repetido pede confirmação (evita cadastro em dobro; pessoas da mesma casa podem dividir o número).
* **Ficha do cliente:**
  * resumo de compras: quantidade, total gasto, média e última compra (o vendedor vê só as vendas dele);
  * **pets**, com espécie, raça, porte, idade e observações (ex.: "bravo com outros cães"), e botões **Vacina**, **Agendar** e **Editar**;
  * **carteirinha** de cada pet;
  * histórico de **banho e tosa** e de **compras**;
  * contato com link direto para o **WhatsApp**.
* Excluir um pet ou cliente cancela os agendamentos dele que ainda não foram pagos.

### Carteirinha: vacinas, vermífugos e antipulgas

* Registre cada dose: tipo (vacina, vermífugo ou antipulgas), nome, data aplicada e data da próxima.
* Quando uma dose é renovada, a anterior aparece como **já renovada** e sai dos avisos.
* As doses que vencem nos próximos **7 dias** (configurável) e as vencidas há até 60 dias aparecem no Dashboard e na tela de Clientes, com botão **Lembrar** que abre o WhatsApp com a mensagem pronta para o dono.

### Agenda de banho e tosa

* **Faixa da semana** com o número de atendimentos de cada dia; setas, data e **Hoje** para navegar.
* **Agendar:** escolha o pet (com busca por pet ou dono), o serviço, a data e o horário.
  * Os horários já ocupados aparecem como **(ocupado)** e não podem ser escolhidos.
  * Também ficam bloqueados os horários que já passaram e os em que o serviço não termina antes de fechar.
  * O limite de atendimentos ao mesmo tempo e o horário da agenda são configuráveis (padrão: 1 por vez, das 08:00 às 19:00).
* **Andamento** de cada atendimento: Agendado → Em atendimento → Pronto. Também dá para marcar **Faltou** (só depois do horário) ou **Cancelar**.
* **Cobrar no caixa:** leva o serviço para a tela de Vendas. Pode ser cobrado antes ou depois do atendimento: o pagamento fica separado do andamento, e o atendimento pago aparece com o selo **Pago** e o link da venda.
* **WhatsApp:** botões **Lembrar** (lembrete do horário) e **Avisar que está pronto** (com "já pago" quando for o caso).
* **Serviços** (só administrador): nome, preço e duração de cada serviço (ex.: Banho porte P, 60 min, R$ 45,00). Serviços podem ser desativados sem perder o histórico.

### Produtos e categorias

* **Categorias:** grupos de produtos (ex.: Rações, Higiene, Acessórios).
* **Produtos:** nome, categoria, código de barras (4 a 14 números), preço de custo, preço de venda, estoque mínimo e foto (o celular reduz a foto antes de enviar).
* A lista mostra o **lucro por unidade**, a **margem** (lucro ÷ preço de venda) e o **acréscimo sobre o custo**.
* A quantidade em estoque não é digitada no produto: ela vem das entradas de estoque.
* Busca por nome ou código de barras e filtro por categoria.

### Estoque, lotes e validade

* **Registrar entrada:** produto, quantidade, **lote** e **validade** (vazia para o que não vence).
* **Dar baixa:** vencido, avaria ou perda, com motivo.
* **Posição do estoque:** quantidade de cada produto e alerta quando está no mínimo ou abaixo.
* **Lotes e validade:** lotes vencidos e os que vencem em até 30, 15 e 7 dias. Na venda, o sistema tira sempre do lote que vence primeiro.
* **Movimentações:** tudo o que entrou e saiu, com data, usuário e motivo.

### Despesas

Registre as despesas da loja (descrição, categoria, valor e data, sem data futura). Elas entram no **resultado** do Dashboard e nos relatórios. É possível editar e excluir.

### Relatórios

Para o período escolhido:

* **Resumo:** faturamento (separado em produtos e banho e tosa), vendas, ticket médio, lucro bruto, despesas e resultado.
* **Rentabilidade por produto:** quantidade, faturamento, custo, lucro, margem e acréscimo.
* **Banho e tosa:** atendimentos e receita por serviço.
* **Mais vendidos**, **despesas por categoria**, **vendas do período**, **movimentações de estoque** e **lotes vencidos ou vencendo**.
* Cada tabela tem **Exportar CSV** (abre no Excel); a página toda pode ser impressa ou salva em PDF.

### Usuários, senhas e auditoria

* **Usuários** (só administrador): criar, editar, **redefinir senha**, **desativar** (perde o acesso na hora e o histórico fica) e reativar. O sistema nunca deixa ficar sem nenhum administrador ativo.
* **Senhas:** troca obrigatória da senha provisória; login bloqueado por alguns minutos após muitas tentativas erradas; aviso de **Caps Lock ligado**; sessão encerrada após 30 minutos sem uso.
* **Auditoria** (só administrador): registro de quem fez o quê e quando: logins, vendas, cancelamentos, devoluções, caixa, estoque, clientes, agenda, carteirinha e alterações de usuários.

### Backup

* Todo dia às 22:00, cópia do banco e das fotos no computador e uma cópia **cifrada** no OneDrive.
* Guarda 30 dias, sempre mantendo os 7 mais novos.
* O Dashboard do administrador avisa se o backup falhou ou está atrasado.
* Como configurar e restaurar: veja o [README.md](README.md#4-configurar-o-backup).

---

## Rotina da loja

| Quando | O quê | Quem |
| --- | --- | --- |
| Ao abrir | Ligar o computador e conferir se o sistema abre | Quem abre |
| Ao abrir | **Abrir o caixa** com o troco da gaveta | Vendedor |
| Ao abrir | Olhar o **Dashboard** (Para fazer hoje e avisos de backup) | Administrador |
| Durante o dia | Vender, agendar e cobrar banho e tosa, registrar entradas de estoque | Vendedor / estoque |
| Durante o dia | Avisar os donos pelo WhatsApp (pet pronto, lembrete de horário e de vacina) | Vendedor |
| Ao fechar | **Fechar o caixa** contando a gaveta | Vendedor |
| Ao fechar | Deixar o computador ligado até depois das 22:00 (backup) | Quem fecha |
| Toda semana | **Lotes e validade**: tirar da prateleira ou dar baixa no que venceu | Estoque |
| Todo mês | Lançar as despesas e conferir os **Relatórios** | Financeiro |

## Usando no celular

* O sistema se adapta à tela do celular: no lugar do menu lateral aparece uma **barra inferior** com as telas mais usadas do perfil (ex.: para o vendedor, Vendas, Agenda, Caixa e Clientes) e **Mais** com o resto.
* Na venda, uma barra fixa mostra o total e leva direto ao pagamento.
* A **câmera** lê código de barras (Chrome/Edge no Android e Safari no iPhone), mas só com HTTPS. Sem HTTPS, use o leitor USB no computador.
* Para acessar de outros aparelhos, a loja precisa de um proxy com HTTPS ([README.md](README.md#2-instalar)).
