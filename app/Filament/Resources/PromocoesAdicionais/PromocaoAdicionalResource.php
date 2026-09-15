<?php

namespace App\Filament\Resources\PromocoesAdicionais;

use App\Filament\Resources\PromocoesAdicionais\Pages\CreatePromocaoAdicional;
use App\Filament\Resources\PromocoesAdicionais\Pages\EditPromocaoAdicional;
use App\Filament\Resources\PromocoesAdicionais\Pages\ListPromocoesAdicionais;
use App\Filament\Resources\PromocoesAdicionais\Schemas\PromocaoAdicionalForm;
use App\Filament\Resources\PromocoesAdicionais\Tables\PromocoesAdicionaisTable;
use App\Models\PromocaoAdicional;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class PromocaoAdicionalResource extends Resource
{
    protected static ?string $model = PromocaoAdicional::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static UnitEnum|string|null $navigationGroup = 'Cardápio';

    protected static ?string $navigationLabel = 'Promoções de Adicional';

    protected static ?string $modelLabel = 'promoção de adicional';

    protected static ?string $pluralModelLabel = 'promoções de adicional';

    protected static ?int $navigationSort = 31;

    protected static ?string $slug = 'promocoes-adicionais';

    protected static ?string $recordTitleAttribute = 'promoad_nome';

    public static function form(Schema $schema): Schema
    {
        return PromocaoAdicionalForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PromocoesAdicionaisTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPromocoesAdicionais::route('/'),
            'create' => CreatePromocaoAdicional::route('/create'),
            'edit' => EditPromocaoAdicional::route('/{record}/edit'),
        ];
    }
}
