<?php

namespace App\Filament\Resources\Comissoes\Tables;

use App\Enums\StatusComissaoEnum;
use App\Models\Influenciador;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ComissoesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['influenciador', 'pedido', 'cupom']))
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nenhuma comissão registrada')
            ->columns([
                TextColumn::make('influenciador.influenciador_nome')
                    ->label('Influenciador')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('comissao_pedido_id')
                    ->label('Pedido')
                    ->prefix('#'),

                TextColumn::make('cupom.cupom_codigo')
                    ->label('Cupom')
                    ->placeholder('—'),

                TextColumn::make('comissao_base_calculo')
                    ->label('Base de Cálculo')
                    ->money('BRL'),

                TextColumn::make('comissao_valor')
                    ->label('Comissão')
                    ->money('BRL')
                    ->weight('bold'),

                TextColumn::make('comissao_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label())
                    ->color(fn ($state) => match ($state) {
                        StatusComissaoEnum::PENDENTE => 'gray',
                        StatusComissaoEnum::CREDITADA => 'success',
                        StatusComissaoEnum::ESTORNADA => 'warning',
                        StatusComissaoEnum::CANCELADA => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('comissao_creditada_em')
                    ->label('Creditada em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Criada em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('comissao_influenciador_id')
                    ->label('Influenciador')
                    ->options(fn () => Influenciador::pluck('influenciador_nome', 'id'))
                    ->searchable(),

                SelectFilter::make('comissao_status')
                    ->label('Status')
                    ->options([
                        StatusComissaoEnum::PENDENTE->value => StatusComissaoEnum::PENDENTE->label(),
                        StatusComissaoEnum::CREDITADA->value => StatusComissaoEnum::CREDITADA->label(),
                        StatusComissaoEnum::ESTORNADA->value => StatusComissaoEnum::ESTORNADA->label(),
                        StatusComissaoEnum::CANCELADA->value => StatusComissaoEnum::CANCELADA->label(),
                    ]),

                Filter::make('periodo')
                    ->schema([
                        DatePicker::make('inicio')->label('De'),
                        DatePicker::make('fim')->label('Até'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['inicio'] ?? null, fn (Builder $q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['fim'] ?? null, fn (Builder $q, $d) => $q->whereDate('created_at', '<=', $d))),
            ]);
    }
}
