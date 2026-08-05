<?php

namespace App\Filament\Resources\FechamentosCaixa;

use App\Enums\StatusFechamentoCaixa;
use App\Filament\Resources\FechamentosCaixa\Pages\CreateFechamentoCaixa;
use App\Filament\Resources\FechamentosCaixa\Pages\EditFechamentoCaixa;
use App\Filament\Resources\FechamentosCaixa\Pages\ListFechamentosCaixa;
use App\Filament\Resources\FechamentosCaixa\Schemas\FechamentoCaixaForm;
use App\Filament\Resources\FechamentosCaixa\Tables\FechamentosCaixaTable;
use App\Models\FechamentoCaixa;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class FechamentoCaixaResource extends Resource
{
    protected static ?string $model = FechamentoCaixa::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static UnitEnum|string|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationLabel = 'Fechamento de Caixa';

    protected static ?string $modelLabel = 'fechamento de caixa';

    protected static ?string $pluralModelLabel = 'fechamentos de caixa';

    protected static ?int $navigationSort = 12;

    public static function form(Schema $schema): Schema
    {
        return FechamentoCaixaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FechamentosCaixaTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    /** Confirmado trava edição/exclusão direta — corrige via "Reabrir fechamento". */
    public static function canEdit(Model $record): bool
    {
        /** @var FechamentoCaixa $record */
        return $record->status !== StatusFechamentoCaixa::Confirmado;
    }

    public static function canDelete(Model $record): bool
    {
        /** @var FechamentoCaixa $record */
        return $record->status !== StatusFechamentoCaixa::Confirmado;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFechamentosCaixa::route('/'),
            'create' => CreateFechamentoCaixa::route('/create'),
            'edit' => EditFechamentoCaixa::route('/{record}/edit'),
        ];
    }
}
