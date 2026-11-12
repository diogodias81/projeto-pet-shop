#!/usr/bin/env bash
# E2 - Segurança: verificações automáticas na aplicação rodando (complementa a lista do ASVS em resultados.md).
# Não grava dados: só faz login com as contas de demonstração e tenta acessos que devem ser recusados.
# Uso: bash artigo/experimentos/verificar_seguranca.sh
set -uo pipefail
BASE=http://localhost:8000
SENHA=senha123   # contas de demonstração; não usar com contas reais
TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT
ok=0; falha=0
conferir() { if eval "$2"; then echo "ok    - $1"; ok=$((ok+1)); else echo "FALHA - $1"; falha=$((falha+1)); fi; }

cab=$(curl -s -D - -o /dev/null "$BASE/index.php?page=login")
tem() { grep -qi "^$1" <<<"$cab"; }
conferir "CSP com default-src 'self' e frame-ancestors 'none'"   "tem \"Content-Security-Policy: default-src 'self'\" && grep -qi \"frame-ancestors 'none'\" <<<\"\$cab\""
conferir "X-Content-Type-Options: nosniff"                      "tem 'X-Content-Type-Options: nosniff'"
conferir "X-Frame-Options: DENY"                                "tem 'X-Frame-Options: DENY'"
conferir "Referrer-Policy definido"                             "tem 'Referrer-Policy:'"
conferir "Permissions-Policy limita câmera ao próprio site"     "tem 'Permissions-Policy: camera=(self)'"
conferir "Cookie de sessão HttpOnly e SameSite=Lax"             "grep -i '^Set-Cookie: PHPSESSID' <<<\"\$cab\" | grep -qi 'HttpOnly' && grep -i '^Set-Cookie: PHPSESSID' <<<\"\$cab\" | grep -qi 'SameSite=Lax'"
conferir "Servidor não revela versão (Apache/PHP)"              "! grep -qiE '^(Server: Apache/[0-9]|X-Powered-By)' <<<\"\$cab\""
conferir "Pasta storage (banco e backups) bloqueada"            "[ \"\$(curl -s -o /dev/null -w '%{http_code}' $BASE/storage/petshop.sqlite)\" != 200 ]"
conferir "Sem listagem de pastas em uploads"                    "[ \"\$(curl -s -o /dev/null -w '%{http_code}' $BASE/uploads/produtos/)\" != 200 ]"
conferir "Sem login, telas internas mandam para o login"        "curl -s -o /dev/null -w '%{redirect_url}' '$BASE/index.php?page=relatorios' | grep -q 'page=login'"

pote="$TMP/vendedor"
csrf=$(curl -s -c "$pote" -b "$pote" "$BASE/index.php?page=login" | grep -o 'name="csrf" value="[^"]*"' | cut -d'"' -f4)
conferir "Login sem token CSRF é recusado"                      "! curl -s -c \"\$pote\" -b \"\$pote\" -o /dev/null -w '%{redirect_url}' --data 'email=vendedor@petshop.com&senha=$SENHA' '$BASE/index.php?page=login' | grep -q 'page=inicio'"
sessaoAntes=$(grep PHPSESSID "$pote" | awk '{print $7}')
curl -s -o /dev/null -c "$pote" -b "$pote" --data-urlencode "csrf=$csrf" --data-urlencode "email=vendedor@petshop.com" --data-urlencode "senha=$SENHA" "$BASE/index.php?page=login"
conferir "Login troca o identificador da sessão (fixação)"      "[ \"\$(grep PHPSESSID \"\$pote\" | awk '{print \$7}')\" != \"\$sessaoAntes\" ]"
for tela in usuarios auditoria relatorios despesas dashboard; do
  conferir "Vendedor recebe 403 na tela $tela"                  "[ \"\$(curl -s -o /dev/null -w '%{http_code}' -b \"\$pote\" '$BASE/index.php?page=$tela')\" = 403 ]"
done
conferir "Sair só por POST (link de outro site não desloga)"    "curl -s -o /dev/null -b \"\$pote\" '$BASE/index.php?page=logout'; [ \"\$(curl -s -o /dev/null -w '%{http_code}' -b \"\$pote\" '$BASE/index.php?page=vendas')\" = 200 ]"
conferir "POST sem token CSRF numa tela logada é recusado"      "[ \"\$(curl -s -o /dev/null -w '%{http_code}' -b \"\$pote\" --data 'atual=x&nova=y&confirmacao=y' '$BASE/index.php?page=minha_senha')\" = 400 ]"

echo; echo "Verificações: $ok ok, $falha falha(s)"
