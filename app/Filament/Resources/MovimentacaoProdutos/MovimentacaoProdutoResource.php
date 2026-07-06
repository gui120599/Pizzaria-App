<?php

namespace App\Filament\Resources\MovimentacaoProdutos;

use App\Enums\MovimentacaoOrigemEnum;
use App\Enums\MovimentacaoTipoEnum;
use App\Filament\Resources\MovimentacaoProdutos\Pages\ManageMovimentacaoProdutos;
use App\Models\MovimentacaoProduto;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class MovimentacaoProdutoResource extends Resource
{
    protected static ?string $model = MovimentacaoProduto::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static UnitEnum|string|null $navigationGroup = 'Estoque';

    protected static ?string $navigationLabel = 'Movimentações';

    protected static ?string $modelLabel = 'Movimentação';

    protected static ?string $pluralModelLabel = 'Movimentações';

    protected static ?int $navigationSort = 30;

    /** Livro-razão imutável: criação/edição só pelo EstoqueService. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['produto', 'lote', 'centroCusto', 'user']))
            ->defaultSort('mov_data', 'desc')
            ->columns([
                TextColumn::make('mov_data')
                    ->label('Data')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('produto.produto_descricao')
                    ->label('Produto')
                    ->searchable()
                    ->limit(40)
                    ->sortable(),

                TextColumn::make('mov_tipo')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (MovimentacaoTipoEnum $state): string => $state->label())
                    ->color(fn (MovimentacaoTipoEnum $state): string => $state->cor()),

                TextColumn::make('mov_origem')
                    ->label('Origem')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?MovimentacaoOrigemEnum $state): string => $state?->label() ?? '—'),

                TextColumn::make('mov_quantidade')
                    ->label('Qtd.')
                    ->numeric(decimalPlaces: 3)
                    ->alignEnd(),

                TextColumn::make('mov_custo_unitario')
                    ->label('Custo Unit.')
                    ->money('BRL')
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('mov_custo_total')
                    ->label('Custo Total')
                    ->money('BRL')
                    ->alignEnd(),

                TextColumn::make('mov_saldo_apos')
                    ->label('Saldo após')
                    ->numeric(decimalPlaces: 3)
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('lote.lote_codigo')
                    ->label('Lote')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('centroCusto.centro_custo_nome')
                    ->label('Centro de Custo')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('user.name')
                    ->label('Usuário')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('mov_produto_id')
                    ->label('Produto')
                    ->relationship('produto', 'produto_descricao')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('mov_tipo')
                    ->label('Tipo')
                    ->options(
                        collect(MovimentacaoTipoEnum::cases())
                            ->mapWithKeys(fn ($t) => [$t->value => $t->label()])
                            ->toArray()
                    ),

                SelectFilter::make('mov_origem')
                    ->label('Origem')
                    ->options(
                        collect(MovimentacaoOrigemEnum::cases())
                            ->mapWithKeys(fn ($o) => [$o->value => $o->label()])
                            ->toArray()
                    ),

                SelectFilter::make('mov_centro_custo_id')
                    ->label('Centro de Custo')
                    ->relationship('centroCusto', 'centro_custo_nome')
                    ->preload(),

                Filter::make('periodo')
                    ->schema([
                        DatePicker::make('de')->label('De'),
                        DatePicker::make('ate')->label('Até'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['de'] ?? null, fn (Builder $q, $d) => $q->whereDate('mov_data', '>=', $d))
                            ->when($data['ate'] ?? null, fn (Builder $q, $d) => $q->whereDate('mov_data', '<=', $d));
                    }),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageMovimentacaoProdutos::route('/'),
        ];
    }
}
