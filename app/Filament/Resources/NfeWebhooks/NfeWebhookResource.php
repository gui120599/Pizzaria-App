<?php

namespace App\Filament\Resources\NfeWebhooks;

use App\Filament\Resources\NfeWebhooks\Pages\ManageNfeWebhooks;
use App\Filament\Resources\NfeWebhooks\Tables\NfeWebhooksTable;
use App\Models\Empresa;
use App\Models\NfeWebhook;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Caixa de entrada read-mostly dos webhooks recebidos da NFe.io
 * (POST /api/webhook/nfe-status) — auditoria/inspeção do payload bruto e da
 * verificação de assinatura, não o mecanismo que atualiza o status da venda
 * (isso é feito direto no NfeWebhookController). Só aparece com a
 * integração NFe.io configurada (mesmo gate do checkbox no PDV).
 */
class NfeWebhookResource extends Resource
{
    protected static ?string $model = NfeWebhook::class;

    protected static ?string $slug = 'nfe-webhooks';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static UnitEnum|string|null $navigationGroup = 'Comercial';

    protected static ?string $navigationLabel = 'Webhooks NFe.io';

    protected static ?string $modelLabel = 'Webhook';

    protected static ?string $pluralModelLabel = 'Webhooks';

    protected static ?int $navigationSort = 11;

    protected static ?string $recordTitleAttribute = 'nfw_invoice_id';

    public static function shouldRegisterNavigation(): bool
    {
        return Empresa::first()?->nfeIoConfigurado() ?? false;
    }

    public static function table(Table $table): Table
    {
        return NfeWebhooksTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageNfeWebhooks::route('/'),
        ];
    }
}
