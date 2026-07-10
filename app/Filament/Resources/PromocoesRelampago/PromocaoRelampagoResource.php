<?php

namespace App\Filament\Resources\PromocoesRelampago;

use App\Filament\Resources\PromocoesRelampago\Pages\CreatePromocaoRelampago;
use App\Filament\Resources\PromocoesRelampago\Pages\EditPromocaoRelampago;
use App\Filament\Resources\PromocoesRelampago\Pages\ListPromocoesRelampago;
use App\Filament\Resources\PromocoesRelampago\Schemas\PromocaoRelampagoForm;
use App\Filament\Resources\PromocoesRelampago\Tables\PromocoesRelampagoTable;
use App\Models\PromocaoRelampago;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class PromocaoRelampagoResource extends Resource
{
    protected static ?string $model = PromocaoRelampago::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static UnitEnum|string|null $navigationGroup = 'Cardápio';

    protected static ?string $navigationLabel = 'Promoções Relâmpago';

    protected static ?string $modelLabel = 'promoção relâmpago';

    protected static ?string $pluralModelLabel = 'promoções relâmpago';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'promocoes-relampago';

    protected static ?string $recordTitleAttribute = 'promocao_nome';

    public static function form(Schema $schema): Schema
    {
        return PromocaoRelampagoForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PromocoesRelampagoTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPromocoesRelampago::route('/'),
            'create' => CreatePromocaoRelampago::route('/create'),
            'edit' => EditPromocaoRelampago::route('/{record}/edit'),
        ];
    }
}
