<?php

namespace App\Filament\Widgets;

use App\Enums\StatusLancamento;
use App\Filament\Resources\Lancamentos\LancamentoResource;
use App\Models\Lancamento;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * A receber das adquirentes: recebíveis de maquininha ainda pendentes com
 * repasse até os próximos 7 dias (e os atrasados), por conta e data. Só entram
 * no extrato da conta quando liquidados (LiquidacaoRecebiveisService).
 */
class RecebiveisAdquirentesWidget extends BaseWidget
{
    use HasWidgetShield;

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 1;

    public function table(Table $table): Table
    {
        return $table
            ->heading('A receber das adquirentes')
            ->description('Cartão e Pix de maquininha entram na conta só na data de repasse. Atrasados aparecem em vermelho.')
            ->query(fn (): Builder => Lancamento::query()
                ->receber()
                ->whereNotNull('maquininha_id')
                ->where('status', StatusLancamento::Pendente)
                ->whereDate('vencimento', '<=', today()->addDays(7))
                ->with('maquininha.contaFinanceira'))
            ->defaultSort('vencimento')
            ->paginated(false)
            ->emptyStateHeading('Nenhum recebível de maquininha nos próximos 7 dias')
            ->recordUrl(fn (Lancamento $record): string => LancamentoResource::getUrl('edit', ['record' => $record]))
            ->columns([
                TextColumn::make('vencimento')
                    ->label('Repasse')
                    ->date('d/m/Y')
                    ->weight('bold')
                    ->color(fn (Lancamento $record): ?string => $record->vencimento->lt(today()) ? 'danger' : null),
                TextColumn::make('maquininha.contaFinanceira.nome')
                    ->label('Conta')
                    ->placeholder('Sem conta'),
                TextColumn::make('maquininha.nome')
                    ->label('Maquininha'),
                TextColumn::make('valor')
                    ->label('Bruto')
                    ->money('BRL')
                    ->summarize(Sum::make()->money('BRL')),
                TextColumn::make('taxa_adquirente')
                    ->label('Taxa prevista')
                    ->money('BRL')
                    ->color('danger')
                    ->summarize(Sum::make()->money('BRL')),
                TextColumn::make('liquido')
                    ->label('Líquido')
                    ->state(fn (Lancamento $record): float => round((float) $record->valor - (float) $record->taxa_adquirente, 2))
                    ->money('BRL'),
            ]);
    }
}
