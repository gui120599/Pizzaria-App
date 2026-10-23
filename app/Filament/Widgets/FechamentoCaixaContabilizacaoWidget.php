<?php

namespace App\Filament\Widgets;

use App\Enums\StatusLancamento;
use App\Filament\Resources\Lancamentos\LancamentoResource;
use App\Models\Lancamento;
use App\Services\RelatorioFechamentoCaixaService;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

/** Títulos que a contabilização do fechamento gerou nas sessões do recorte, com a conta onde o dinheiro entrou. */
class FechamentoCaixaContabilizacaoWidget extends BaseWidget
{
    use HasWidgetShield;
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Contabilização nas contas')
            ->description('Títulos gerados na confirmação de cada fechamento. Dinheiro e Pix direto entram na hora; cartão e Pix de maquininha ficam pendentes até o repasse.')
            ->query(function (): Builder {
                $sessoes = (new RelatorioFechamentoCaixaService($this->pageFilters ?? []))->sessoes()->modelKeys();

                return Lancamento::query()
                    ->whereIn('sessao_caixa_id', $sessoes)
                    ->where('status', '!=', StatusLancamento::Cancelado)
                    ->with(['maquininha', 'movimentacoesFinanceiras.conta']);
            })
            ->defaultSort('sessao_caixa_id')
            ->paginated(false)
            ->emptyStateHeading('Nenhuma sessão contabilizada no recorte')
            ->recordUrl(fn (Lancamento $record): string => LancamentoResource::getUrl('edit', ['record' => $record]))
            ->columns([
                TextColumn::make('sessao_caixa_id')
                    ->label('Sessão')
                    ->prefix('#'),
                TextColumn::make('descricao')
                    ->label('Título')
                    ->wrap(),
                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->badge(),
                TextColumn::make('valor')
                    ->label('Valor')
                    ->money('BRL'),
                TextColumn::make('conta')
                    ->label('Conta')
                    ->state(fn (Lancamento $record): string => $record->movimentacoesFinanceiras->first()?->conta?->nome
                        ?? ($record->maquininha_id !== null ? "A receber — repasse {$record->vencimento->format('d/m/Y')}" : '—')),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
            ]);
    }
}
