<?php

namespace App\Filament\Resources\PromocoesCombo;

use App\Filament\Resources\PromocoesCombo\Pages\CreatePromocaoCombo;
use App\Filament\Resources\PromocoesCombo\Pages\EditPromocaoCombo;
use App\Filament\Resources\PromocoesCombo\Pages\ListPromocoesCombo;
use App\Filament\Resources\PromocoesCombo\Pages\RelatorioPromocoesCombo;
use App\Filament\Resources\PromocoesCombo\Schemas\PromocaoComboForm;
use App\Filament\Resources\PromocoesCombo\Tables\PromocoesComboTable;
use App\Models\PromocaoCombo;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class PromocaoComboResource extends Resource
{
    protected static ?string $model = PromocaoCombo::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquare2Stack;

    protected static UnitEnum|string|null $navigationGroup = 'Cardápio';

    protected static ?string $navigationLabel = 'Promoção Combo';

    protected static ?string $modelLabel = 'promoção combo';

    protected static ?string $pluralModelLabel = 'promoções combo';

    protected static ?int $navigationSort = 32;

    protected static ?string $slug = 'promocoes-combo';

    protected static ?string $recordTitleAttribute = 'promocombo_nome';

    public static function form(Schema $schema): Schema
    {
        return PromocaoComboForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PromocoesComboTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPromocoesCombo::route('/'),
            'create' => CreatePromocaoCombo::route('/create'),
            'relatorio' => RelatorioPromocoesCombo::route('/relatorio'),
            'edit' => EditPromocaoCombo::route('/{record}/edit'),
        ];
    }
}
