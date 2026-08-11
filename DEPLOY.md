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

- **PHP 8.2** selecionado no MultiPHP Manager (o `.htaccess` da raiz já assume `ea-php82`/LiteSpeed).
- Extensões PHP habilitadas no MultiPHP INI Editor: `bcmath, curl, dom, fileinfo, gd, intl, mbstring, pdo_mysql, openssl, soap, xml, zip, zlib`.
  - Atenção especial ao **`soap`** — usado na integração SEFAZ, nem sempre vem habilitado por padrão.
- Banco de dados MySQL criado, com usuário e senha.

## 2. Terminal — conferir o ambiente

cPanel → **Terminal**:

```bash
cd ~/public_html
php -v        # precisa mostrar 8.2.x — senão usar o binário versionado, ex: /opt/cpanel/ea-php82/root/usr/bin/php
git --version
ls -la
```

## 3. Transformar a pasta existente em repositório git

Como a pasta já tem os arquivos do FTP antigo (não vazia), faça pelo terminal — mais confiável do que a tela "Create" do Git™ Version Control do cPanel, que em várias versões recusa pasta não vazia:

```bash
cd ~/public_html
git init
git remote add origin https://github.com/gui120599/Pizzaria-App.git
git fetch origin producao
git checkout -f -t origin/producao
```

O `-f` sobrescreve qualquer arquivo rastreado desatualizado (vindo do FTP antigo). `.env`, `storage/app`, uploads e certificados não são tocados (fora do git). Depois disso, cPanel → **Git™ Version Control** costuma reconhecer automaticamente o repositório e passa a oferecer "Gerenciar → Pull ou Deploy" pros próximos updates.

## 4. Conferir o `.env` de produção

Como já existe um `.env` de produção, só confira se ele tem as variáveis novas que a branch `producao` introduziu (compare com `.env.example`):

```bash
diff <(grep -o '^[A-Z_]*=' .env.example | sort) <(grep -o '^[A-Z_]*=' .env | sort)
php artisan config:clear
```

## 5. Rodar as migrations

```bash
php artisan migrate --force
```

## 6. Storage

```bash
php artisan storage:link
```

Se o symlink não funcionar na hospedagem, crie manualmente uma pasta real em `public/storage` e garanta que `storage/` e `bootstrap/cache/` tenham permissão de escrita (755/775).

## 7. Cache de produção

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## 8. Reiniciar o PHP (limpar OPcache)

cPanel → **MultiPHP Manager** → selecione o domínio → reinicie o PHP. Sem isso, código antigo pode continuar em cache mesmo após o deploy.

## 9. Cron job (obrigatório, só na primeira vez)

O projeto tem 3 tarefas agendadas reais em `app/Console/Kernel.php`:
- `promocoes:resetar-recorrentes` — a cada minuto
- `sefaz:importar-novas-notas` — de hora em hora
- `contratos:gerar-lancamentos` — diariamente às 2h

cPanel → **Cron Jobs**:

```
* * * * * /opt/cpanel/ea-php82/root/usr/bin/php /home/SEU_USUARIO/public_html/artisan schedule:run >> /dev/null 2>&1
```

(ajuste o caminho do PHP e do usuário conforme o que apareceu no passo 2)

## 10. Certificado A1 (SEFAZ)

Depois do primeiro deploy, configure o certificado digital A1 pelo painel Filament (`ConfiguracaoSefaz`). Garanta que `storage/app/certificados` seja gravável.

## 11. Atualizações futuras

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
php artisan config:clear && php artisan cache:clear && php artisan view:clear && php artisan route:clear
php artisan migrate --force            # só se houver migration nova
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

E reinicie o PHP no MultiPHP Manager. Se o cPanel reconheceu o repositório no passo 3, dá pra fazer o `git pull` clicando em "Update from Remote" + "Deploy HEAD Commit" na tela do Git™ Version Control, em vez do terminal.
