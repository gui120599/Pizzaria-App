<?php

namespace App\Filament\Resources\AgenteExecucoes;

use App\Filament\Resources\AgenteExecucoes\Pages\ListAgenteExecucoes;
use App\Models\AgenteExecucao;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Histórico das rodadas do agente de compras. Só leitura. */
class AgenteExecucaoResource extends Resource
{
    protected static ?string $model = AgenteExecucao::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static UnitEnum|string|null $navigationGroup = 'Estoque';

    protected static ?string $navigationLabel = 'Agente de compras';

    protected static ?string $modelLabel = 'rodada do agente';

    protected static ?string $pluralModelLabel = 'rodadas do agente de compras';

    protected static ?int $navigationSort = 8;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('resolver:pendencia_compra') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('finalizada_em')->label('Finalizada em')->dateTime('d/m/Y H:i'),
            TextEntry::make('pendencias_resolvidas')->label('Resolvidas pelo agente'),
            TextEntry::make('pendencias_sugeridas')->label('Sugestões aguardando aprovação'),
            TextEntry::make('resumo')->label('Resumo')->columnSpanFull()->prose(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('finalizada_em', 'desc')
            ->columns([
                TextColumn::make('finalizada_em')->label('Rodada')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('pendencias_resolvidas')->label('Resolvidas')->alignEnd(),
                TextColumn::make('pendencias_sugeridas')->label('Sugeridas')->alignEnd(),
                TextColumn::make('resumo')->label('Resumo')->limit(120)->wrap(),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgenteExecucoes::route('/'),
        ];
    }
}
