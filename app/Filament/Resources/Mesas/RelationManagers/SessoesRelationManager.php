<?php

namespace App\Filament\Resources\Mesas\RelationManagers;

use App\Enums\StatusSessaoMesa;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Lista somente-leitura das sessões (ocupações) já registradas nesta mesa.
 * Gestão do ciclo de vida da sessão (abrir/fechar/reabrir) fica no
 * SessaoMesaResource.
 */
class SessoesRelationManager extends RelationManager
{
    protected static string $relationship = 'sessoes';

    protected static ?string $title = 'Sessões da mesa';

    protected static ?string $modelLabel = 'sessão';

    protected static ?string $pluralModelLabel = 'sessões';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('garcom.name')
                    ->label('Garçom'),
                TextColumn::make('cliente.cliente_nome')
                    ->label('Cliente')
                    ->placeholder('—'),
                TextColumn::make('sessao_mesa_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => StatusSessaoMesa::from($state)->getLabel())
                    ->color(fn (string $state): string => StatusSessaoMesa::from($state)->getColor()),
                TextColumn::make('created_at')
                    ->label('Aberta em')
                    ->dateTime('d/m/Y H:i'),
            ])
            ->filters([])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
