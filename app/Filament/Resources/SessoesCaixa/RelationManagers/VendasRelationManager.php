<?php

namespace App\Filament\Resources\SessoesCaixa\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Lista somente-leitura das vendas lançadas nesta sessão de caixa — espelha
 * SessaoCaixaController::listarVendasSessaoCaixa. Gestão da venda em si continua
 * no PDV (App\Filament\Pages\OperarVenda) ou no VendaResource.
 */
class VendasRelationManager extends RelationManager
{
    protected static string $relationship = 'vendas';

    protected static ?string $title = 'Vendas da sessão';

    protected static ?string $modelLabel = 'venda';

    protected static ?string $pluralModelLabel = 'vendas';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('cliente.cliente_nome')
                    ->label('Cliente')
                    ->placeholder('Consumidor final'),
                TextColumn::make('venda_valor_total')
                    ->label('Valor total')
                    ->money('BRL'),
                TextColumn::make('venda_valor_pago')
                    ->label('Pago')
                    ->money('BRL'),
                TextColumn::make('venda_status')
                    ->label('Status')
                    ->badge(),
                TextColumn::make('venda_datahora_finalizada')
                    ->label('Finalizada em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
            ])
            ->filters([])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
