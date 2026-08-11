# Deploy em produção — HostGator (cPanel)

Esta branch (`producao`) contém tudo que o servidor precisa pra rodar a aplicação, incluindo `vendor/` e `public/build/` já prontos — porque o suporte da hospedagem não informou o caminho do Composer no servidor, então **não dá pra depender de rodar `composer install` lá**. `node_modules/` continua fora (nunca é necessário em runtime; só `public/build/`, já compilado, importa).

Repositório público em `https://github.com/gui120599/Pizzaria-App`. Já existe um site publicado no domínio (via FTP, antes deste processo) — o `.htaccess` da raiz já assume que o repositório fica direto na pasta do domínio (ex.: `public_html`) e reescreve as requisições para `/public/`.

## 0. Backup antes de mexer

Pelo Terminal ou File Manager, guarde o que não está no git e já existe em produção:

```bash
cd ~/public_html   # ajuste se o domínio apontar para outra pasta
mkdir -p ~/backup_pre_git
cp -a .env ~/backup_pre_git/
cp -a storage/app ~/backup_pre_git/storage_app 2>/dev/null
```

## 1. Pré-requisitos no cPanel

- PHP 8.2+ selecionado no MultiPHP Manager (`composer.json` exige `^8.2`; o domínio `emporiodapizzago.com.br` está em **PHP 8.4** — `ea-php84`/LiteSpeed — o `.htaccess` reflete a versão selecionada e é reescrito automaticamente pelo cPanel a cada troca de versão).
- Extensões PHP habilitadas no MultiPHP INI Editor: `bcmath, curl, dom, fileinfo, gd, intl, mbstring, pdo_mysql, openssl, soap, xml, zip, zlib`.
  - Atenção especial ao **`soap`** — usado na integração SEFAZ, nem sempre vem habilitado por padrão.
- Banco de dados MySQL criado, com usuário e senha.
- **Não conte com Composer no servidor** — o suporte da HostGator não informa o caminho do binário nesse plano. É por isso que `vendor/` vem comitado na branch.

## 2. Terminal — conferir o ambiente

cPanel → **Terminal**:

```bash
cd ~/public_html   # pasta do domínio (confirme com `ls -la ~`, pode ser outra em contas com múltiplos domínios)
php -v
git --version
ls -la
```

## 3. Colocar a pasta do domínio na branch `producao`

Se a pasta já tem um `.git` de um deploy anterior (`git status` mostra branch e remote configurados), pule para o passo 3b. Se for a primeira vez (pasta só com os arquivos do FTP antigo, sem `.git`):

```bash
cd ~/public_html
git init
git remote add origin https://github.com/gui120599/Pizzaria-App.git
git fetch origin producao
```

**3a. Cuidado com `.htaccess` e `public/.htaccess`** — em produção eles costumam ter customizações geradas pelo próprio cPanel que **não podem ser perdidas**:
- `public/.htaccess`: precisa de `Options +FollowSymLinks` (sem isso o symlink de `public/storage` não funciona e as imagens somem).
- `.htaccess` (raiz): pode ter um bloco de proteção contra hotlink gerado pela ferramenta de Hotlink Protection do cPanel (cobre a conta inteira, não só este domínio).
- o bloco `# php -- BEGIN cPanel-generated handler` é reescrito automaticamente pelo cPanel a cada troca de versão do PHP.

Antes de trocar de branch, guarde essas edições e devolva depois (só funciona sem conflito se esses arquivos não tiverem mudado na branch `producao` em si — o que é o caso hoje):

```bash
git checkout -- public/error_log   # descarta mudança sem importância, se houver
git stash push -- .htaccess public/.htaccess
```

**3b. Trocar para `producao`:**

```bash
git checkout -b producao origin/producao   # primeira vez
# ou, se a branch local já existir:
git checkout producao && git pull origin producao

git stash pop   # devolve as edições do .htaccess/public/.htaccess guardadas no 3a
```

`.env`, `storage/app`, uploads e certificados não são tocados (fora do git).

**3c. Congele os dois `.htaccess` pra nunca mais serem sobrescritos** por um `git pull`/`checkout` futuro:

```bash
git update-index --skip-worktree .htaccess public/.htaccess
```

## 4. Limpar cache compilado do bootstrap (sempre que o `vendor/` mudar)

**Passo crítico** — o Laravel guarda um manifesto compilado de service providers em `bootstrap/cache/packages.php`/`services.php`. Esses arquivos **não são rastreados pelo git**, então continuam com a lista antiga de pacotes mesmo depois de trocar de branch. Se algum pacote saiu do `vendor/` (ex.: pacotes de dev), qualquer `php artisan` (e o próprio site) quebra com `Class "...ServiceProvider" not found` até isso ser limpo:

```bash
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php bootstrap/cache/config.php bootstrap/cache/routes-v7.php
php artisan --version   # se rodar sem erro, seguiu ok
```

## 5. Conferir o `.env` de produção

```bash
diff <(grep -o '^[A-Z_]*=' .env.example | sort) <(grep -o '^[A-Z_]*=' .env | sort)
grep -E '^(APP_ENV|APP_DEBUG)=' .env
```

Garanta `APP_ENV=production` e `APP_DEBUG=false` (um `.env` herdado de um deploy antigo via FTP pode estar com `APP_ENV=local`/`APP_DEBUG=true`, o que expõe stack trace completo pro público):

```bash
sed -i 's/^APP_ENV=.*/APP_ENV=production/' .env
sed -i 's/^APP_DEBUG=.*/APP_DEBUG=false/' .env
php artisan config:clear
```

## 6. Rodar as migrations

```bash
php artisan migrate:status   # confira o que está pendente antes
php artisan migrate --force
```

## 7. Storage

```bash
ls -la public/storage
```

Se já existir um symlink válido (`public/storage -> .../storage/app/public/`), não mexa. Só rode `php artisan storage:link` se não existir — ele falha com erro se o link já estiver lá.

## 8. Cache de produção

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## 9. Reiniciar o PHP (limpar OPcache)

cPanel → **MultiPHP Manager** → selecione o domínio → reinicie o PHP. Sem isso, código antigo pode continuar em cache mesmo após o deploy.

## 10. Cron job (obrigatório, só na primeira vez)

O projeto tem 3 tarefas agendadas reais em `app/Console/Kernel.php`:
- `promocoes:resetar-recorrentes` — a cada minuto
- `sefaz:importar-novas-notas` — de hora em hora
- `contratos:gerar-lancamentos` — diariamente às 2h

cPanel → **Cron Jobs**:

```
* * * * * php /home2/empo7374/public_html/artisan schedule:run >> /dev/null 2>&1
```

(configure um e-mail de notificação no cron para ser avisado se algum comando falhar)

## 11. Certificado A1 (SEFAZ)

```bash
mkdir -p storage/app/certificados
chmod -R 775 storage/app/certificados storage/logs storage/framework bootstrap/cache
```

Depois configure o certificado digital A1 pelo painel Filament (`ConfiguracaoSefaz`).

## 12. Checklist final

```bash
tail -20 storage/logs/laravel.log   # sem erros novos
git status --short                  # só deve mostrar os untracked de sempre (fotos, logs, htaccess.phpupgrader.*)
```
Abra o site no navegador e teste login + uma ação real (ex.: abrir uma venda) antes de considerar concluído.

## 13. Atualizações futuras

**Localmente**, antes de subir qualquer mudança para `producao`:

1. Desenvolva e teste em `prod-new` (ou na branch de dev).
2. Se algo de front-end mudou, rode `npm run build` — é o único jeito de atualizar `public/build/`, já que o servidor não tem Node.js.
3. Leve as mudanças para `producao` (merge/cherry-pick).
4. Se `composer.json`/`composer.lock` mudou, reconstrua o `vendor/` sem pacotes de dev: `composer install --no-dev --optimize-autoloader` e comite o resultado.
5. `git push origin producao`.

**No servidor**:

```bash
cd ~/public_html
git pull origin producao
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php bootstrap/cache/config.php bootstrap/cache/routes-v7.php
php artisan config:clear && php artisan cache:clear && php artisan view:clear && php artisan route:clear
php artisan migrate:status                      # confira antes de aplicar
php artisan migrate --force                      # só se houver migration nova
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

E reinicie o PHP no MultiPHP Manager. Repita o passo 4 (limpar cache do bootstrap) **sempre** que o conjunto de pacotes do `vendor/` mudar — é a causa mais provável de o site cair logo após um deploy.
