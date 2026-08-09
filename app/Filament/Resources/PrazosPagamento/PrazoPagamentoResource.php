<?php

namespace App\Filament\Resources\PrazosPagamento;

use App\Filament\Resources\PrazosPagamento\Pages\CreatePrazoPagamento;
use App\Filament\Resources\PrazosPagamento\Pages\EditPrazoPagamento;
use App\Filament\Resources\PrazosPagamento\Pages\ListPrazosPagamento;
use App\Filament\Resources\PrazosPagamento\Schemas\PrazoPagamentoForm;
use App\Filament\Resources\PrazosPagamento\Tables\PrazosPagamentoTable;
use App\Models\PrazoPagamento;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class PrazoPagamentoResource extends Resource
{
    protected static ?string $model = PrazoPagamento::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static UnitEnum|string|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationLabel = 'Prazos de Pagamento';

    protected static ?string $modelLabel = 'prazo de pagamento';

    protected static ?string $pluralModelLabel = 'prazos de pagamento';

    protected static ?int $navigationSort = 25;

    protected static ?string $recordTitleAttribute = 'prazo_pagamento_nome';

    public static function form(Schema $schema): Schema
    {
        return PrazoPagamentoForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PrazosPagamentoTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPrazosPagamento::route('/'),
            'create' => CreatePrazoPagamento::route('/create'),
            'edit' => EditPrazoPagamento::route('/{record}/edit'),
        ];
    }
}
