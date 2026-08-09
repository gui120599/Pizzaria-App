<?php

namespace App\Filament\Resources\Compras\RelationManagers;

use App\Filament\Resources\Lancamentos\LancamentoResource;
use App\Models\Lancamento;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Lista somente-leitura dos títulos a pagar (parcelas) já gerados por esta compra —
 * mesmo padrão de App\Filament\Resources\Contratos\RelationManagers\LancamentosRelationManager.
 * Gestão do lançamento em si (registrar pagamento, estornar, editar) continua só no
 * LancamentoResource.
 */
class LancamentosRelationManager extends RelationManager
{
    protected static string $relationship = 'lancamentos';

    protected static ?string $title = 'Contas a pagar geradas';

    protected static ?string $modelLabel = 'título';

    protected static ?string $pluralModelLabel = 'títulos';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('parcela_numero')
            ->columns([
                TextColumn::make('parcelaLabel')
                    ->label('Parcela')
                    ->badge()
                    ->placeholder('Única'),
                TextColumn::make('valor')
                    ->label('Valor')
                    ->money('BRL'),
                TextColumn::make('vencimento')
                    ->label('Vencimento')
                    ->date('d/m/Y'),
                TextColumn::make('forma_pagamento')
                    ->label('Forma de pagamento')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
            ])
            ->filters([])
            ->headerActions([])
            ->recordActions([
                Action::make('abrir')
                    ->label('Abrir')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Lancamento $record): string => LancamentoResource::getUrl('edit', ['record' => $record]))
                    ->openUrlInNewTab(),
            ])
            ->toolbarActions([]);
    }
}
