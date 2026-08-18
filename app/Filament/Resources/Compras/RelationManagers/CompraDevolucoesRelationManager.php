<?php

namespace App\Filament\Resources\Compras\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Lista somente-leitura das devoluções já registradas para esta compra —
 * a devolução em si é feita via RegistrarDevolucaoAction (header action da
 * página de edição), não por aqui.
 */
class CompraDevolucoesRelationManager extends RelationManager
{
    protected static string $relationship = 'devolucoes';

    protected static ?string $title = 'Devoluções';

    protected static ?string $modelLabel = 'devolução';

    protected static ?string $pluralModelLabel = 'devoluções';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Data')
                    ->dateTime('d/m/Y H:i'),
                TextColumn::make('valor_total')
                    ->label('Valor')
                    ->money('BRL'),
                TextColumn::make('motivo')
                    ->label('Motivo')
                    ->limit(60)
                    ->placeholder('—'),
                TextColumn::make('user.name_first')
                    ->label('Registrado por')
                    ->placeholder('—'),
            ])
            ->filters([])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
