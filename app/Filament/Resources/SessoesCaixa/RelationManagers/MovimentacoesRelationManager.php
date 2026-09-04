<?php

namespace App\Filament\Resources\SessoesCaixa\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Lista somente-leitura de todos os movimentos da sessão — entradas (saldo
 * inicial de abertura, ver SessaoCaixaService::registrarMovimentoAbertura) e
 * saídas (sangrias). Gestão completa de sangria (registrar/editar/cancelar)
 * continua no fluxo legado (MovimentacoesSessaoCaixaController), fora do
 * escopo deste relation manager.
 */
class MovimentacoesRelationManager extends RelationManager
{
    protected static string $relationship = 'movimentacoes';

    protected static ?string $title = 'Movimentações';

    protected static ?string $modelLabel = 'movimentação';

    protected static ?string $pluralModelLabel = 'movimentações';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('mov_tipo')
                    ->label('Tipo')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'ENTRADA' ? 'success' : 'danger'),
                TextColumn::make('mov_forma_pagamento')
                    ->label('Forma de pagamento')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('mov_descricao')
                    ->label('Descrição'),
                TextColumn::make('mov_valor')
                    ->label('Valor')
                    ->money('BRL'),
                TextColumn::make('venda.id')
                    ->label('Venda vinculada')
                    ->placeholder('—'),
                TextColumn::make('mov_observacoes')
                    ->label('Observações')
                    ->limit(40)
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Registrado em')
                    ->dateTime('d/m/Y H:i'),
            ])
            ->filters([
                SelectFilter::make('mov_tipo')
                    ->label('Tipo')
                    ->options([
                        'ENTRADA' => 'Entrada',
                        'SAIDA' => 'Saída',
                    ]),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
