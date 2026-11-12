#!/usr/bin/env bash
# E3 - Desempenho: mede o tempo de resposta das telas principais (só leitura, nada é gravado além do login).
# Uso: bash artigo/experimentos/medir_desempenho.sh [repetições]   (app em http://localhost:8000, contas de demonstração)
set -euo pipefail
BASE=http://localhost:8000/index.php
N=${1:-30}
SENHA=senha123   # senha das contas de demonstração (ver README); não usar com contas reais
DE=$(date -d '-90 days' +%F); ATE=$(date +%F)
TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT

entrar() {  # $1 = e-mail; guarda o cookie da sessão em $TMP/$1
  local pote="$TMP/$1"
  local csrf
  csrf=$(curl -s -c "$pote" -b "$pote" "$BASE?page=login" | grep -o 'name="csrf" value="[^"]*"' | cut -d'"' -f4)
  curl -s -o /dev/null -c "$pote" -b "$pote" --data-urlencode "csrf=$csrf" --data-urlencode "email=$1" --data-urlencode "senha=$SENHA" "$BASE?page=login"
  # confere que entrou (sem sessão, a página redireciona para o login)
  [ "$(curl -s -o /dev/null -w '%{http_code}' -b "$pote" "$BASE?page=minha_senha")" = 200 ] || { echo "falha no login de $1" >&2; exit 1; }
}

medir() {  # $1 = e-mail, $2 = nome da tela, $3 = parâmetros
  local pote="$TMP/$1" t codigo
  curl -s -o /dev/null -b "$pote" "$BASE?$3"   # aquecimento, não conta
  for _ in $(seq "$N"); do
    read -r codigo t < <(curl -s -o /dev/null -b "$pote" -w '%{http_code} %{time_total}\n' "$BASE?$3")
    [ "$codigo" = 200 ] || { echo "$2 respondeu $codigo" >&2; exit 1; }
    echo "$t"
  done | sort -n | awk -v nome="$2" '{ v[NR] = $1 * 1000; s += $1 * 1000 }
    END { p95 = v[int(NR * 0.95 + 0.999)]; printf "| %-34s | %7.0f | %7.0f | %7.0f | %7.0f |\n", nome, s / NR, v[int((NR + 1) / 2)], p95, v[NR] }'
}

for u in financeiro vendedor estoque; do entrar "$u@petshop.com"; done

echo "Repetições por tela: $N (mais 1 de aquecimento) - $(date '+%d/%m/%Y %H:%M')"
echo
echo "| Tela                               | Média ms | Mediana | p95 ms | Máx ms |"
echo "|------------------------------------|---------:|--------:|-------:|-------:|"
medir financeiro@petshop.com "Dashboard (mês atual)"             "page=dashboard"
medir financeiro@petshop.com "Dashboard (90 dias)"               "page=dashboard&de=$DE&ate=$ATE"
medir financeiro@petshop.com "Relatórios (90 dias)"              "page=relatorios&de=$DE&ate=$ATE"
medir financeiro@petshop.com "Histórico de vendas (90 dias)"     "page=historico&de=$DE&ate=$ATE"
medir financeiro@petshop.com "Caixa"                             "page=caixa"
medir financeiro@petshop.com "Despesas"                          "page=despesas"
medir vendedor@petshop.com   "Vendas (PDV)"                      "page=vendas"
medir vendedor@petshop.com   "Clientes"                          "page=clientes"
medir vendedor@petshop.com   "Agenda (hoje)"                     "page=agenda"
medir estoque@petshop.com    "Produtos"                          "page=produtos"
medir estoque@petshop.com    "Estoque"                           "page=estoque"
