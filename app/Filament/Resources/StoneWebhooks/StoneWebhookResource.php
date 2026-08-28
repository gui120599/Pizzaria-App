<?php

namespace App\Filament\Resources\StoneWebhooks;

use App\Filament\Resources\StoneWebhooks\Pages\ManageStoneWebhooks;
use App\Filament\Resources\StoneWebhooks\Tables\StoneWebhooksTable;
use App\Models\StoneWebhook;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Caixa de entrada read-mostly dos webhooks recebidos do Connect Stone
 * (POST /api/webhook/stone-connect) — auditoria/inspeção do payload bruto e da
 * autenticação Basic Auth, não o mecanismo que lança o pagamento na venda.
 * Só aparece com a integração Stone configurada.
 */
class StoneWebhookResource extends Resource
{
    protected static ?string $model = StoneWebhook::class;

    protected static ?string $slug = 'stone-webhooks';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static UnitEnum|string|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationLabel = 'Webhooks Stone';

    protected static ?string $modelLabel = 'Webhook';

    protected static ?string $pluralModelLabel = 'Webhooks';

    protected static ?int $navigationSort = 14;

    protected static ?string $recordTitleAttribute = 'stw_order_code';

    public static function shouldRegisterNavigation(): bool
    {
        return filled(config('services.stone.webhook_user'))
            || filled(config('services.stone.secret_key'));
    }

    public static function table(Table $table): Table
    {
        return StoneWebhooksTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageStoneWebhooks::route('/'),
        ];
    }
}
