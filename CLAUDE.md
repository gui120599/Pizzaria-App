#CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Stack

- **Laravel 12** (PHP 8.2+) + **Filament 4** (admin panel)
- **MySQL** via Laravel Sail (Docker)
- **Vite** + Tailwind CSS 4 + Alpine.js
- Locale: `pt_BR` throughout the application

## Development Commands

```bash
# Docker Environment (Laravel Sail)
./vendor/bin/sail up -d # Start containers
./vendor/bin/sail down # Stop containers

# Artisan
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan migrate:fresh --seed
./vendor/bin/sail artisan cache:clear && artisan config:clear && artisan view:clear

# Frontend
npm run dev
# Vite dev server
npm run build # Production build

# Tests
./vendor/bin/sail artisan test
./vendor/bin/sail artisan test --filter=ClassName # Specific test

# Code style
./vendor/bin/sail composer pint # Laravel Pint (PSR-12)

# Filament
./vendor/bin/sail artisan filament:upgrade # Run after updating the Filament

## Architecture

### Two Parallel Interfaces

The project has **two distinct interfaces** coexisting:

1. **Legacy Interface** — Traditional MVC via `routes/web.php`. Controllers in `app/Http/Controllers/`, Blade views in `resources/views/`. Covers the operational service flow (orders, sales, tables, cash register).

2. **Filament Panel** — accessible via `/admin`, configured in `app/Providers/Filament/AdminPanelProvider.php`. Resources are located in `app/Filament/Resources/`, with automatic discovery. It is the management/back office panel.

### Resource Filament Structure

Each resource follows the pattern of subdirectories by responsibility:

```
app/Filament/Resources/{Domain}/

{Domain}Resource.php # Main resource record

Schemas/{Domain}Form.php # Form definition (Schema)

Tables/{Domain}Table.php # Table definition

Pages/

Create{Domain}.php
Edit{Domain}.php
List{Domain}.php # or Manage for simple resources

```

### Column Naming Convention

All database columns follow the model prefix:
- `order_*` → `orders` table
- `product_*` → `products` table
- `client_*` → `clients` table
- `order_item_*` → table `items_orders`

### Order Lifecycle

Sequential status: `STARTED` → `OPEN` → `PREPARING` → `READY` → `IN TRANSIT` → `DELIVERED` → `FINISHED`
Alternative terminal status: `CANCELLED`

Each transaction records the date and time in the corresponding field (`order_preparation_date_time`, `order_ready_date_time`, etc.).

### Enums

In `app/Enums/`:

- `StockControlModeEnum` — `block | warn | do_not_control`
- `EnumTypeMovement` / `EnumOriginMovement` — stock movements
- `EnumTypeProduct` — `produced | resale | input | Internal consumption`
- `ProductUnitEnum` — units of measure
- `ProviderCategoryEnum` / `ProviderTypeEnum` — suppliers/employees

### Authorization

Uses `spatie/laravel-permission`. Sensitive routes (cash register session, reports) require `middleware('permission:Admin')`. The `User` model implements `HasRoles`.

### External Integrations

- **NFe.io** — NF-e issuance via the `nfe/nfe` package. Status webhook in `POST /api/webhook/nfe-status`.

- **IBGE** — `app/Services/IBGEServices.php` for address lookup.

- **DomPDF** — PDF generation via `PDFController` (orders, sessions, reports).

### Database

Default connection: MySQL running in the Sail container. The user session is stored in the database (`SESSION_DRIVER=database`). For testing, it uses an in-memory `testing` database (`array` driver).