<?php

namespace App\Filament\Garcom\Resources\ChecklistExecucoes;

use App\Filament\Garcom\Resources\ChecklistExecucoes\Pages\ListChecklistExecucoes;
use App\Filament\Garcom\Resources\ChecklistExecucoes\Pages\ViewChecklistExecucao;
use App\Filament\Resources\ChecklistExecucoes\Schemas\ChecklistExecucaoInfolist;
use App\Models\ChecklistExecucao;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Histórico do /garcom: só as execuções que o usuário iniciou ou em que
 * respondeu itens, dos últimos 30 dias. A visualização reusa o infolist do
 * /admin (a policy `view` libera quem participou).
 */
class ChecklistExecucaoResource extends Resource
{
    protected static ?string $model = ChecklistExecucao::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Histórico';

    protected static ?string $modelLabel = 'checklist';

    protected static ?string $pluralModelLabel = 'meus checklists';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $slug = 'historico-checklists';

    protected static ?int $navigationSort = 3;

    /** A listagem já vem filtrada pelo usuário; a policy viewAny (gestão) não se aplica aqui. */
    public static function canViewAny(): bool
    {
        return auth()->check();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->participadasPor(auth()->user())
            ->whereDate('data_referencia', '>=', ChecklistExecucao::dataOperacional()->subDays(29)->format('Y-m-d'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return ChecklistExecucaoInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('data_referencia', 'desc')
            ->columns([
                TextColumn::make('data_referencia')
                    ->label('Dia')
                    ->date('d/m'),
                TextColumn::make('modelo_nome')
                    ->label('Checklist')
                    ->wrap(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
                TextColumn::make('percentual_conformidade')
                    ->label('Conformidade')
                    ->formatStateUsing(fn (?string $state): ?string => $state === null ? null : number_format((float) $state, 0, ',', '.').'%')
                    ->placeholder('—')
                    ->visibleFrom('sm'),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('Nenhum checklist nos últimos 30 dias');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChecklistExecucoes::route('/'),
            'view' => ViewChecklistExecucao::route('/{record}'),
        ];
    }
}
