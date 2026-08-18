#!/usr/bin/env bash
#
# Promove o HEAD atual de prod-new para a branch producao (deploy HostGator/cPanel).
#
# producao NÃO é um merge de prod-new — é uma árvore reconstruída a cada
# promoção: código de app/database igual ao de prod-new, mas SEM tests/ e SEM
# node_modules/ (não usados em runtime, ver DEPLOY.md), com vendor/ regerado
# via `composer install --no-dev` (o host não tem Composer), e com
# public/build/ recompilado via `npm run build` (o host não tem npm). Por
# isso os hashes de commit de prod-new e producao nunca batem mesmo quando o
# conteúdo de app/ é idêntico — é esperado, não é bug.
#
# DEPLOY.md e o .gitignore desta branch são preservados como estão em
# producao (não existem/diferem em prod-new de propósito).
#
# Uso: scripts/promote-producao.sh
# Rodar a partir de qualquer checkout do repo (usa um worktree temporário,
# não mexe no seu checkout atual).

set -euo pipefail

REPO_ROOT="$(git rev-parse --show-toplevel)"
cd "$REPO_ROOT"

git fetch origin prod-new producao --quiet

PROD_NEW_SHA="$(git rev-parse origin/prod-new)"
WT_DIR="$(mktemp -d)"

cleanup() {
  git worktree remove "$WT_DIR" --force 2>/dev/null || rm -rf "$WT_DIR"
}
trap cleanup EXIT

echo "==> Preparando worktree de producao em $WT_DIR"
git worktree add "$WT_DIR" producao --quiet 2>/dev/null || git worktree add "$WT_DIR" origin/producao -b producao --quiet

cd "$WT_DIR"
git pull origin producao --quiet

echo "==> Substituindo a árvore pelo conteúdo de prod-new ($PROD_NEW_SHA)"
git rm -rqf -- . 2>/dev/null || true
git checkout "origin/prod-new" -- .

echo "==> Restaurando DEPLOY.md e .gitignore próprios de producao"
git show HEAD:DEPLOY.md > DEPLOY.md 2>/dev/null && git add DEPLOY.md || true
git show HEAD:.gitignore > .gitignore && git add .gitignore

echo "==> Removendo tests/ e node_modules/ (não usados em runtime, ver DEPLOY.md)"
git rm -rqf -- tests/ 2>/dev/null || true
git rm -rqf -- node_modules/ 2>/dev/null || true
rm -rf tests node_modules

echo "==> Regenerando vendor/ sem dependências de dev"
rm -rf vendor
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> Recompilando assets (public/build/)"
npm ci --silent
npm run build --silent
rm -rf node_modules

git add -A

if git diff --cached --quiet; then
  echo "==> Nada para promover — producao já reflete prod-new."
  exit 0
fi

git commit -m "Promove prod-new (${PROD_NEW_SHA:0:8}) para produção

Regenerado via scripts/promote-producao.sh: código de app/database de
prod-new, vendor/ sem dev, public/build/ recompilado, sem tests/ nem
node_modules/. DEPLOY.md e .gitignore desta branch preservados."

echo "==> Enviando para origin/producao"
git push origin producao

echo "==> Promoção concluída. Lembrete: no servidor, git pull + resetar OPcache (ver DEPLOY.md)."
