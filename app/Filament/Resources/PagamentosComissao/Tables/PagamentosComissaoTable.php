<?php

namespace App\Filament\Resources\PagamentosComissao\Tables;

use App\Enums\FormaPagamentoComissaoEnum;
use App\Models\Influenciador;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PagamentosComissaoTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['influenciador', 'cupomCredito']))
            ->defaultSort('pgcom_pago_em', 'desc')
            ->emptyStateHeading('Nenhum pagamento de comissão gerado ainda')
            ->columns([
                TextColumn::make('influenciador.influenciador_nome')
                    ->label('Influenciador')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('pgcom_periodo_referencia')
                    ->label('Período')
                    ->date('m/Y'),

                TextColumn::make('pgcom_valor_total')
                    ->label('Valor Total')
                    ->money('BRL')
                    ->weight('bold'),

                TextColumn::make('pgcom_forma')
                    ->label('Forma')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label()),

                TextColumn::make('cupomCredito.cupom_codigo')
                    ->label('Cupom de Crédito')
                    ->placeholder('—'),

                TextColumn::make('pgcom_pago_em')
                    ->label('Pago em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('pgcom_influenciador_id')
                    ->label('Influenciador')
                    ->options(fn () => Influenciador::pluck('influenciador_nome', 'id'))
                    ->searchable(),

                SelectFilter::make('pgcom_forma')
                    ->label('Forma')
                    ->options([
                        FormaPagamentoComissaoEnum::PIX->value => FormaPagamentoComissaoEnum::PIX->label(),
                        FormaPagamentoComissaoEnum::CREDITO_PIZZA->value => FormaPagamentoComissaoEnum::CREDITO_PIZZA->label(),
                    ]),
            ]);
    }
}
