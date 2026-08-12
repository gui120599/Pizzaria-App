<?php

namespace App\Filament\Resources\MovimentacaoProdutos;

use App\Enums\MovimentacaoOrigemEnum;
use App\Enums\MovimentacaoTipoEnum;
use App\Filament\Resources\MovimentacaoProdutos\Pages\ManageMovimentacaoProdutos;
use App\Filament\Support\CorrecaoEstoquePreview;
use App\Models\MovimentacaoProduto;
use App\Services\CorrecaoEstoqueService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
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

    /**
     * Livro-razão: criação/edição só pelo EstoqueService. A única exceção é a
     * exclusão de movimentações soltas via CorrecaoEstoqueService::aplicarExclusao()
     * (ver acaoExcluirMovimentacao() abaixo) — soft delete auditado, não uma
     * exclusão livre.
     */
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
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['produto', 'lote.marca', 'centroCusto', 'user']))
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

                TextColumn::make('lote.marca.marca_nome')
                    ->label('Marca')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

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
            ->recordActions([
                self::acaoExcluirMovimentacao(),
            ])
            ->toolbarActions([]);
    }

    /**
     * Exclui uma movimentação "solta" — sem compra, balanço ou venda por
     * trás (ex.: lançamento errado feito na ação "Movimentar" do Produto).
     * Só aparece nesse caso: movimentação ligada a um documento tem que ser
     * corrigida/ajustada por lá, não apagada daqui — ver
     * CorrecaoEstoqueService::aplicarExclusao().
     */
    private static function acaoExcluirMovimentacao(): Action
    {
        return Action::make('excluirMovimentacao')
            ->label('Excluir')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn (MovimentacaoProduto $record): bool => $record->mov_referencia_id === null
                && $record->mov_venda_id === null
                && auth()->user()?->can('corrigir', MovimentacaoProduto::class))
            ->modalHeading('Excluir movimentação')
            ->modalDescription('Use quando esta movimentação nem deveria ter existido (ex.: lançamento manual duplicado ou com o produto errado). O sistema recalcula em cadeia o saldo, o custo médio e tudo que saiu do estoque depois dela — como se ela nunca tivesse acontecido.')
            ->modalWidth('lg')
            ->form(function (MovimentacaoProduto $record): array {
                return [
                    Placeholder::make('previa')
                        ->label('Prévia do impacto')
                        ->content(function () use ($record): HtmlString {
                            $resultado = app(CorrecaoEstoqueService::class)->simularExclusao($record->produto, $record);

                            return CorrecaoEstoquePreview::resumo($resultado);
                        }),

                    Textarea::make('motivo')
                        ->label('Motivo da exclusão')
                        ->required()
                        ->rows(2)
                        ->placeholder('Ex.: lançamento duplicado por engano na ação Movimentar.'),
                ];
            })
            ->action(function (array $data, MovimentacaoProduto $record): void {
                try {
                    app(CorrecaoEstoqueService::class)->aplicarExclusao($record, $data['motivo']);
                } catch (ValidationException $e) {
                    Notification::make()
                        ->title('Não foi possível excluir')
                        ->body(collect($e->errors())->flatten()->implode(' '))
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Movimentação excluída')
                    ->body('O saldo e o custo médio do produto foram recalculados.')
                    ->success()
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageMovimentacaoProdutos::route('/'),
        ];
    }
}
