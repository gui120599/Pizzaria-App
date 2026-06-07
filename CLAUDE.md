# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Stack

- **Laravel 12** (PHP 8.2+) + **Filament 4** (admin panel)
- **MySQL** via Laravel Sail (Docker)
- **Vite** + Tailwind CSS 4 + Alpine.js
- Locale: `pt_BR` em toda a aplicação

## Comandos de Desenvolvimento

```bash
# Ambiente Docker (Laravel Sail)
./vendor/bin/sail up -d          # Subir containers
./vendor/bin/sail down           # Derrubar containers

# Artisan
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan migrate:fresh --seed
./vendor/bin/sail artisan cache:clear && artisan config:clear && artisan view:clear

# Frontend
npm run dev      # Vite dev server
npm run build    # Build para produção

# Testes
./vendor/bin/sail artisan test
./vendor/bin/sail artisan test --filter=NomeDaClasse   # Teste específico

# Code style
./vendor/bin/sail composer pint   # Laravel Pint (PSR-12)

# Filament
./vendor/bin/sail artisan filament:upgrade   # Rodar após atualizar o Filament
```

## Arquitetura

### Duas Interfaces Paralelas

O projeto possui **duas interfaces distintas** em coexistência:

1. **Interface legada** — MVC tradicional via `routes/web.php`. Controllers em `app/Http/Controllers/`, views Blade em `resources/views/`. Cobre o fluxo operacional de atendimento (pedidos, vendas, mesas, caixa).

2. **Painel Filament** — acessível em `/admin`, configurado em `app/Providers/Filament/AdminPanelProvider.php`. Recursos em `app/Filament/Resources/`, com descoberta automática. É o painel de gestão/backoffice.

### Estrutura dos Resources Filament

Cada resource segue o padrão de subdiretórios por responsabilidade:

```
app/Filament/Resources/{Dominio}/
    {Dominio}Resource.php          # Registro principal do resource
    Schemas/{Dominio}Form.php      # Definição do formulário (Schema)
    Tables/{Dominio}Table.php      # Definição da tabela
    Pages/
        Create{Dominio}.php
        Edit{Dominio}.php
        List{Dominio}.php          # ou Manage para recursos simples
```

### Convenção de Nomenclatura de Colunas

Todas as colunas de banco seguem prefixo do modelo:
- `pedido_*` → tabela `pedidos`
- `produto_*` → tabela `produtos`
- `cliente_*` → tabela `clientes`
- `item_pedido_*` → tabela `itens_pedidos`

### Ciclo de Vida do Pedido

Status sequencial: `INICIADO` → `ABERTO` → `PREPARANDO` → `PRONTO` → `EM TRANSPORTE` → `ENTREGUE` → `FINALIZADO`  
Status terminal alternativo: `CANCELADO`

Cada transição registra datahora no campo correspondente (`pedido_datahora_preparo`, `pedido_datahora_pronto`, etc).

### Enums

Em `app/Enums/`:
- `EstoqueModoControleEnum` — `bloquear | avisar | nao_controlar`
- `MovTipoEnum` / `MovOrigemEnum` — movimentações de estoque
- `ProdutoTipoEnum` — `produzido | revenda | insumo | consumo_interno`
- `UnidadeProdutoEnum` — unidades de medida
- `PrestadorCategoriaEnum` / `PrestadorTipoEnum` — fornecedores/funcionários

### Autorização

Usa `spatie/laravel-permission`. Rotas sensíveis (sessão de caixa, relatórios) exigem `middleware('permission:Admin')`. O model `User` implementa `HasRoles`.

### Integrações Externas

- **NFe.io** — emissão de NF-e via pacote `nfe/nfe`. Webhook de status em `POST /api/webhook/nfe-status`.
- **IBGE** — `app/Services/IBGEServices.php` para busca de endereços.
- **DomPDF** — geração de PDFs via `PDFController` (pedidos, sessões, relatórios).

### Banco de Dados

Conexão padrão: MySQL rodando no container Sail. A sessão do usuário é armazenada no banco (`SESSION_DRIVER=database`). Para testes, usa banco `testing` em memória (`array` driver).
