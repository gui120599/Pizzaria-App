<?php

namespace App\Filament\Resources\PlanoDespesas;

use App\Filament\Resources\PlanoDespesas\Pages\CreatePlanoDespesa;
use App\Filament\Resources\PlanoDespesas\Pages\EditPlanoDespesa;
use App\Filament\Resources\PlanoDespesas\Pages\ListPlanoDespesas;
use App\Filament\Resources\PlanoDespesas\Schemas\PlanoDespesaForm;
use App\Filament\Resources\PlanoDespesas\Tables\PlanoDespesasTable;
use App\Models\PlanoDespesa;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class PlanoDespesaResource extends Resource
{
    protected static ?string $model = PlanoDespesa::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingDown;

    protected static UnitEnum|string|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationLabel = 'Plano de Despesas';

    protected static ?string $modelLabel = 'conta de despesa';

    protected static ?string $pluralModelLabel = 'contas de despesa';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'nome';

    public static function form(Schema $schema): Schema
    {
        return PlanoDespesaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PlanoDespesasTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlanoDespesas::route('/'),
            'create' => CreatePlanoDespesa::route('/create'),
            'edit' => EditPlanoDespesa::route('/{record}/edit'),
        ];
    }
}
