<?php

namespace App\Filament\Resources\Cupons;

use App\Filament\Resources\Cupons\Pages\ManageCupons;
use App\Filament\Resources\Cupons\Schemas\CupomForm;
use App\Filament\Resources\Cupons\Tables\CupomsTable;
use App\Models\Cupom;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * Visão da equipe sobre todos os cupons: os internos (sem teto) e os criados
 * pelos influenciadores. A tela do influenciador é outra (painel /influenciadores).
 */
class CupomResource extends Resource
{
    protected static ?string $model = Cupom::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?string $navigationLabel = 'Cupons';

    // Sem isso o Filament pluralizaria "cupom" pelas regras do inglês.
    protected static ?string $slug = 'cupons';

    protected static UnitEnum|string|null $navigationGroup = 'Pessoas';

    protected static ?int $navigationSort = 27;

    protected static ?string $recordTitleAttribute = 'cupom_codigo';

    public static function form(Schema $schema): Schema
    {
        return CupomForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CupomsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCupons::route('/'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
