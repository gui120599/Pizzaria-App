<?php

namespace App\Filament\Resources\PromocoesCombo\Pages;

use App\Enums\StatusPedidoEnum;
use App\Filament\Resources\PromocoesCombo\PromocaoComboResource;
use App\Models\PedidoComboAplicacao;
use App\Models\PromocaoCombo;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Pages\Page;
use Filament\Tables\Columns\Summarizers\Count;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Combos concedidos por período (Fase 4 do plano da Promoção Combo): um
 * registro por combo aplicado, com o retrato gravado no pedido (De/Por e
 * desconto). Pedido cancelado fica fora por padrão, porque devolveu o combo.
 */
class RelatorioPromocoesCombo extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = PromocaoComboResource::class;

    protected static ?string $title = 'Relatório de combos';

    protected static ?string $breadcrumb = 'Relatório';

    protected string $view = 'filament.resources.promocoes-combo.pages.relatorio-promocoes-combo';

    public static function canAccess(array $parameters = []): bool
    {
        return PromocaoComboResource::canViewAny();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(PedidoComboAplicacao::query()
                ->select('pedido_combo_aplicacoes.*')
                ->join('pedidos', 'pedidos.id', '=', 'pedido_combo_aplicacoes.pca_pedido_id')
                ->whereNull('pedidos.deleted_at')
                ->with('pedido'))
            ->defaultSort('pedido_combo_aplicacoes.created_at', 'desc')
            ->groups([
                Group::make('pca_promocao_combo_id')
                    ->label('Promoção')
                    ->getTitleFromRecordUsing(fn (PedidoComboAplicacao $record): string => $record->pca_nome),
            ])
            ->columns([
                TextColumn::make('pedido.created_at')
                    ->label('Data do pedido')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('pedidos.created_at', $direction)),
                TextColumn::make('pca_pedido_id')
                    ->label('Pedido')
                    ->prefix('#')
                    ->searchable(),
                TextColumn::make('pca_nome')
                    ->label('Promoção')
                    ->wrap()
                    ->summarize(Count::make()->label('Combos')),
                TextColumn::make('pedido.pedido_status')
                    ->label('Situação do pedido')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => StatusPedidoEnum::tryFrom((string) $state)?->label() ?? (string) $state)
                    ->color(fn (?string $state): string => $state === StatusPedidoEnum::CANCELADO->value ? 'danger' : 'gray'),
                TextColumn::make('pca_valor_cheio')
                    ->label('De')
                    ->money('BRL')
                    ->alignEnd()
                    ->summarize(Sum::make()->label('Total')->money('BRL')),
                TextColumn::make('pca_preco')
                    ->label('Por')
                    ->money('BRL')
                    ->alignEnd()
                    ->summarize(Sum::make()->label('Total')->money('BRL')),
                TextColumn::make('pca_desconto')
                    ->label('Desconto')
                    ->money('BRL')
                    ->alignEnd()
                    ->weight('bold')
                    ->summarize(Sum::make()->label('Total')->money('BRL')),
            ])
            ->filters([
                Filter::make('periodo')
                    ->schema([
                        DatePicker::make('de')->label('De'),
                        DatePicker::make('ate')->label('Até'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['de'] ?? null, fn (Builder $q, $data) => $q->whereDate('pedidos.created_at', '>=', $data))
                        ->when($data['ate'] ?? null, fn (Builder $q, $data) => $q->whereDate('pedidos.created_at', '<=', $data))),
                SelectFilter::make('pca_promocao_combo_id')
                    ->label('Promoção')
                    ->options(fn (): array => PromocaoCombo::withTrashed()->orderBy('promocombo_nome')->pluck('promocombo_nome', 'id')->all())
                    ->searchable(),
                TernaryFilter::make('cancelados')
                    ->label('Pedidos cancelados')
                    ->placeholder('Sem os cancelados')
                    ->trueLabel('Só os cancelados')
                    ->falseLabel('Todos')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('pedidos.pedido_status', StatusPedidoEnum::CANCELADO->value),
                        false: fn (Builder $query): Builder => $query,
                        blank: fn (Builder $query): Builder => $query->where('pedidos.pedido_status', '!=', StatusPedidoEnum::CANCELADO->value),
                    ),
            ]);
    }
}
