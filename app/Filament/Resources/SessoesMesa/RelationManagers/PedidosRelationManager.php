<?php

namespace App\Filament\Resources\SessoesMesa\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Lista somente-leitura dos pedidos vinculados a esta sessão de mesa. Lançamento
 * de itens/pedidos pelo garçom continua fora do escopo do Filament por enquanto
 * (telas pedidos_mesa/pedido_mesa do Blade legado) — esta classe é o "terreno
 * preparado": quando esse fluxo for migrado, ganha headerActions/recordActions
 * de lançamento aqui, sem precisar recriar a estrutura.
 */
class PedidosRelationManager extends RelationManager
{
    protected static string $relationship = 'pedidos';

    protected static ?string $title = 'Pedidos da sessão';

    protected static ?string $modelLabel = 'pedido';

    protected static ?string $pluralModelLabel = 'pedidos';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('#'),
                TextColumn::make('cliente.cliente_nome')
                    ->label('Cliente')
                    ->placeholder('—'),
                TextColumn::make('pedido_status')
                    ->label('Status')
                    ->badge(),
                TextColumn::make('pedido_valor_total')
                    ->label('Valor total')
                    ->money('BRL'),
                TextColumn::make('pedido_datahora_abertura')
                    ->label('Aberto em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
            ])
            ->filters([])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
