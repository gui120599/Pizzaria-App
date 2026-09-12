<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\InteractsComFiltrosContasPagarReceber;
use App\Models\Lancamento;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Listagem detalhada dos lançamentos do conjunto filtrado, agrupada pela mesma
 * "situação" derivada usada no resto do relatório (Vencido / A vencer / Pago /
 * Cancelado). A coluna `situacao` é calculada via selectRaw (não é coluna real)
 * pra poder agrupar e ordenar no próprio SQL, com subtotal por grupo (Filament
 * soma automaticamente a summarize() de cada coluna dentro de cada grupo).
 */
class ContasPagarReceberDetalhamentoWidget extends BaseWidget
{
    use HasWidgetShield;
    use InteractsComFiltrosContasPagarReceber;
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Detalhamento dos lançamentos')
            ->query(
                $this->relatorioService()
                    ->query()
                    ->with(['favorecido', 'cliente', 'planoDespesa', 'planoReceita'])
                    ->withSum('pagamentos', 'valor')
                    // withSum() já adicionou "lancamentos.*" ao select (só faz isso quando
                    // nenhuma coluna foi selecionada ainda) — repetir aqui duplicaria a
                    // coluna `id` e quebra a query de subtotal por grupo do Filament.
                    ->selectRaw('CASE
                        WHEN status = "cancelado" THEN "Cancelado"
                        WHEN status = "pago" THEN "Pago"
                        WHEN vencimento < CURDATE() THEN "Vencido"
                        ELSE "A vencer"
                    END as situacao')
                    ->orderByRaw('FIELD(situacao, "Vencido", "A vencer", "Pago", "Cancelado")')
                    ->orderBy('vencimento')
            )
            ->groups([
                Group::make('situacao')
                    ->label('Situação')
                    ->collapsible(),
            ])
            ->defaultGroup('situacao')
            ->columns([
                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->badge(),
                TextColumn::make('descricao')
                    ->label('Descrição')
                    ->wrap()
                    ->searchable(),
                TextColumn::make('nomeFavorecido')
                    ->label('Favorecido')
                    ->getStateUsing(fn (Lancamento $record): ?string => $record->nomeFavorecido)
                    ->placeholder('—'),
                TextColumn::make('conta')
                    ->label('Conta')
                    ->getStateUsing(fn (Lancamento $record): ?string => $record->plano?->nome)
                    ->placeholder('—'),
                TextColumn::make('valor')
                    ->label('Valor')
                    ->money('BRL')
                    ->sortable()
                    ->summarize(Sum::make()->money('BRL')->label('Subtotal')),
                TextColumn::make('valorRestante')
                    ->label('Restante')
                    ->getStateUsing(fn (Lancamento $record): float => $record->valorRestante)
                    ->money('BRL'),
                TextColumn::make('vencimento')
                    ->label('Vencimento')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
            ])
            ->paginated([10, 25, 50, 'all']);
    }
}
