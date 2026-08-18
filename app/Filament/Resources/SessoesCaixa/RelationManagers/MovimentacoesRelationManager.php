<?php

namespace App\Filament\Resources\SessoesCaixa\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Lista somente-leitura das sangrias (saídas) registradas nesta sessão — gestão
 * completa (registrar/editar/cancelar saída) continua no fluxo legado
 * (MovimentacoesSessaoCaixaController), fora do escopo desta migração.
 * Filtra mov_tipo=SAIDA pra não misturar com o movimento ENTRADA de saldo
 * inicial (ver SessaoCaixaService::registrarMovimentoAbertura).
 */
class MovimentacoesRelationManager extends RelationManager
{
    protected static string $relationship = 'movimentacoes';

    protected static ?string $title = 'Sangrias (saídas)';

    protected static ?string $modelLabel = 'saída';

    protected static ?string $pluralModelLabel = 'saídas';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->where('mov_tipo', 'SAIDA'))
            ->defaultSort('id', 'desc')
            ->columns([
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
            ->filters([])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
