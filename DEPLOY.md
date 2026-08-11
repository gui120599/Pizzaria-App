# Deploy em produção — HostGator (cPanel)

Esta branch (`producao`) contém apenas o necessário para rodar a aplicação no servidor: sem `tests/`, `docker-compose.yml`, config de build front-end ou `vendor/`/`node_modules/` versionados. `public/build/` (gerado pelo `npm run build`) continua comitado, pois o servidor não tem Node.js.

## 1. Pré-requisitos no cPanel

- **PHP 8.2** selecionado no MultiPHP Manager (o `.htaccess` da raiz já assume `ea-php82`/LiteSpeed).
- Extensões PHP habilitadas no MultiPHP INI Editor: `bcmath, curl, dom, fileinfo, gd, intl, mbstring, pdo_mysql, openssl, soap, xml, zip, zlib`.
  - Atenção especial ao **`soap`** — é usado na integração SEFAZ e nem sempre vem habilitado por padrão.
- Banco de dados MySQL criado, com usuário e senha, e permissão total sobre o banco.
- Domínio apontando para a pasta onde os arquivos desta branch serão enviados (o `.htaccess` da raiz já reescreve as requisições para `/public/`).

## 2. Enviar os arquivos

Com Terminal/SSH disponível no cPanel:

```bash
git clone --branch producao <url-do-repositorio> .
# ou, se o repositório já existir no servidor:
git checkout producao && git pull origin producao
```

Se `git` não estiver disponível no servidor, envie o conteúdo da branch via FTP/Gerenciador de Arquivos do cPanel.

## 3. Instalar dependências PHP

```bash
composer install --no-dev --optimize-autoloader
```

É esse comando que gera o `vendor/` no servidor (ele não vem mais comitado no repositório). Rode de novo sempre que `composer.json`/`composer.lock` mudar.

## 4. Configurar o `.env`

```bash
cp .env.example .env
php artisan key:generate
```

Preencha no `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` com o domínio real, `DB_*` com os dados do banco criado no cPanel, `MAIL_*` e `RECAPTCHA_*` com os valores reais.

## 5. Rodar as migrations

```bash
php artisan migrate --force
```

## 6. Storage

O `public/storage` local (ambiente Docker) é um symlink para um caminho do container, que não existe no servidor e não é confiável via FTP. No servidor:

```bash
php artisan storage:link
```

Se o symlink não funcionar na hospedagem, crie manualmente uma pasta real em `public/storage` e garanta que `storage/` e `bootstrap/cache/` tenham permissão de escrita pelo usuário do PHP (755/775).

## 7. Cache de produção

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## 8. Cron job (obrigatório)

O projeto tem 3 tarefas agendadas reais em `app/Console/Kernel.php`:
- `promocoes:resetar-recorrentes` — a cada minuto
- `sefaz:importar-novas-notas` — de hora em hora
- `contratos:gerar-lancamentos` — diariamente às 2h

Sem cron configurado no cPanel, nenhuma delas roda. Adicione em **Cron Jobs**:

```
* * * * * php /caminho/completo/para/artisan schedule:run >> /dev/null 2>&1
```

## 9. Certificado A1 (SEFAZ)

Depois do primeiro deploy, configure o certificado digital A1 pelo painel Filament (`ConfiguracaoSefaz`). Garanta que `storage/app/certificados` seja gravável.

## 10. Atualizações futuras

1. Desenvolva e teste em `prod-new` (ou na branch de dev). Rode `npm run build` localmente antes de levar as mudanças para `producao` — é o único jeito de atualizar `public/build/`, já que o servidor não tem Node.js.
2. Leve as mudanças para `producao` (merge/cherry-pick), excluindo o que for específico de dev.
3. No servidor: `git pull origin producao` (ou reenvio via FTP).
4. Se `composer.json`/`composer.lock` mudou: `composer install --no-dev --optimize-autoloader`.
5. `php artisan config:clear && php artisan cache:clear && php artisan view:clear && php artisan route:clear`, seguido de `config:cache`/`route:cache`/`view:cache`.
6. **Reinicie o PHP** (Restart PHP no MultiPHP Manager do cPanel) — o OPcache não recarrega sozinho após o deploy.
