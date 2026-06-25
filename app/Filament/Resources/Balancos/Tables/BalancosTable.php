<?php

namespace App\Filament\Resources\Balancos\Tables;

use App\Enums\MovimentacaoTipoEnum;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\DateFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Forms\Components\DatePicker;

class BalancosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['produto', 'usuario'])->latest('mbal_data_balanco'))
            ->columns([
                TextColumn::make('mbal_data_balanco')
                    ->label('Data')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('produto.produto_descricao')
                    ->label('Produto')
                    ->searchable()
                    ->limit(40),

                TextColumn::make('mbal_tipo_movimentacao')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (MovimentacaoTipoEnum $state): string => $state->label())
                    ->color(fn (MovimentacaoTipoEnum $state): string => $state->cor()),

                TextColumn::make('mbal_quantidade_sistema')
                    ->label('Saldo sistema')
                    ->numeric(decimalPlaces: 3)
                    ->alignEnd(),

                TextColumn::make('mbal_quantidade_balanco')
                    ->label('Qtd. física')
                    ->numeric(decimalPlaces: 3)
                    ->alignEnd(),

                TextColumn::make('mbal_quantidade_ajuste')
                    ->label('Ajuste')
                    ->numeric(decimalPlaces: 3)
                    ->alignEnd()
                    ->color(fn ($record): string => $record->mbal_tipo_movimentacao === MovimentacaoTipoEnum::ENTRADA ? 'success' : 'danger'),

                TextColumn::make('usuario.name')
                    ->label('Usuário')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('mbal_observacao')
                    ->label('Observação')
                    ->limit(50)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('mbal_produto_id')
                    ->label('Produto')
                    ->relationship('produto', 'produto_descricao')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('mbal_tipo_movimentacao')
                    ->label('Tipo')
                    ->options(
                        collect(MovimentacaoTipoEnum::cases())
                            ->mapWithKeys(fn ($t) => [$t->value => $t->label()])
                            ->toArray()
                    ),

                Filter::make('periodo')
                    ->schema([
                        DatePicker::make('de')->label('De'),
                        DatePicker::make('ate')->label('Até'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['de'] ?? null, fn (Builder $q, $d) => $q->whereDate('mbal_data_balanco', '>=', $d))
                            ->when($data['ate'] ?? null, fn (Builder $q, $d) => $q->whereDate('mbal_data_balanco', '<=', $d));
                    }),
            ])
            ->recordActions([
                Action::make('ver_movimentacao')
                    ->label('Ver movimentação')
                    ->icon('heroicon-o-arrow-trending-up')
                    ->color('gray')
                    ->url(fn ($record) => route('filament.admin.resources.movimentacao-produtos.index', [
                        'tableFilters[mov_produto_id][value]' => $record->mbal_produto_id,
                    ]))
                    ->openUrlInNewTab(),
            ])
            ->toolbarActions([]);
    }
}
