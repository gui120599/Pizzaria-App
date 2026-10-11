<?php

namespace App\Filament\Widgets;

use App\Enums\FormaPagamento;
use App\Exceptions\CorrecaoPagamentoException;
use App\Filament\Pages\RelatorioSessaoCaixa;
use App\Models\LancamentoPagamento;
use App\Models\Maquininha;
use App\Services\CorrecaoPagamentoService;
use App\Services\RelatorioSessaoCaixaService;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Fiado gerado nas vendas do recorte × fiado recebido nas sessões (que pode
 * ser de venda de outro dia). O recebimento feito no PDV tem a forma
 * corrigível (CorrecaoPagamentoService::trocarFormaFiado).
 */
class SessaoCaixaFiadoWidget extends BaseWidget
{
    use HasWidgetShield;
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Fiado: gerado × recebido')
            ->description('A prazo gerado nas vendas do recorte e recebimentos de fiado feitos nas sessões. O recebido entra no esperado do caixa pela forma; o gerado não.')
            ->records(function (): array {
                $relatorio = new RelatorioSessaoCaixaService($this->pageFilters ?? []);

                return $relatorio->fiadoGerado()
                    ->map(fn (array $linha): array => [
                        'key' => 'gerado-'.$linha['key'],
                        'movimento' => 'Gerado (a prazo)',
                        'data' => $linha['finalizada_em'],
                        'cliente' => $linha['cliente'],
                        'venda_id' => $linha['venda_id'],
                        'forma' => $linha['status'],
                        'valor' => $linha['valor'],
                        'recebimento_id' => null,
                    ])
                    ->concat($relatorio->fiadoRecebido()->map(fn (array $linha): array => [
                        'key' => 'recebido-'.$linha['key'],
                        'movimento' => 'Recebido na sessão #'.$linha['sessao_id'],
                        'data' => $linha['data'],
                        'cliente' => $linha['cliente'],
                        'venda_id' => $linha['venda_id'],
                        'forma' => $linha['forma'].($linha['maquininha'] ? ' · '.$linha['maquininha'] : '').($linha['entra_no_caixa'] ? '' : ' (fora do caixa)'),
                        'valor' => $linha['valor'],
                        'recebimento_id' => $linha['id'],
                    ]))
                    ->keyBy('key')
                    ->all();
            })
            ->paginated(false)
            ->emptyStateHeading('Nenhum fiado gerado ou recebido no recorte')
            ->columns([
                TextColumn::make('movimento')
                    ->label('Movimento')
                    ->badge()
                    ->color(fn (string $state): string => str_starts_with($state, 'Gerado') ? 'warning' : 'success'),
                TextColumn::make('data')
                    ->label('Data')
                    ->dateTime('d/m H:i'),
                TextColumn::make('cliente')
                    ->label('Cliente')
                    ->placeholder('—'),
                TextColumn::make('venda_id')
                    ->label('Venda')
                    ->prefix('#')
                    ->placeholder('—'),
                TextColumn::make('forma')
                    ->label('Forma / situação'),
                TextColumn::make('valor')
                    ->label('Valor')
                    ->money('BRL')
                    ->weight('bold'),
            ])
            ->recordActions([
                Action::make('corrigirForma')
                    ->label('Corrigir forma')
                    ->icon(Heroicon::OutlinedWrenchScrewdriver)
                    ->color('warning')
                    ->visible(fn (array $record): bool => $record['recebimento_id'] !== null && SessaoCaixaVendasWidget::podeCorrigir())
                    ->authorize(fn (): bool => SessaoCaixaVendasWidget::podeCorrigir())
                    ->disabled(fn (array $record): bool => $this->motivoBloqueio($record) !== null)
                    ->tooltip(fn (array $record): ?string => $this->motivoBloqueio($record))
                    ->modalHeading('Corrigir a forma do recebimento de fiado')
                    ->schema([
                        Select::make('forma')
                            ->label('Forma correta')
                            ->options(FormaPagamento::class)
                            ->required()
                            ->live(),
                        Select::make('maquininha_id')
                            ->label('Maquininha')
                            ->options(SessaoCaixaVendasWidget::maquininhas())
                            ->default(fn (): ?int => Maquininha::padrao()?->id)
                            ->visible(fn (Get $get): bool => self::forma($get('forma'))?->tipoMaquininha() !== null),
                        Select::make('cartao_id')
                            ->label('Bandeira')
                            ->options(SessaoCaixaVendasWidget::bandeiras())
                            ->visible(fn (Get $get): bool => in_array(self::forma($get('forma')), [FormaPagamento::CartaoCredito, FormaPagamento::CartaoDebito], true)),
                        Textarea::make('justificativa')
                            ->label('Justificativa')
                            ->rows(2),
                    ])
                    ->action(function (array $record, array $data, CorrecaoPagamentoService $service): void {
                        try {
                            $service->trocarFormaFiado(
                                LancamentoPagamento::findOrFail($record['recebimento_id']),
                                self::forma($data['forma']),
                                filled($data['maquininha_id'] ?? null) ? (int) $data['maquininha_id'] : null,
                                filled($data['cartao_id'] ?? null) ? (int) $data['cartao_id'] : null,
                                filled($data['justificativa'] ?? null) ? $data['justificativa'] : null,
                            );
                        } catch (CorrecaoPagamentoException $e) {
                            Notification::make()->title('Correção não feita')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Recebimento corrigido')->success()->send();
                        $this->redirect(RelatorioSessaoCaixa::getUrl(['filters' => $this->pageFilters ?? []]));
                    }),
            ]);
    }

    /** @param  array<string, mixed>  $record */
    private function motivoBloqueio(array $record): ?string
    {
        $recebimento = $record['recebimento_id'] ? LancamentoPagamento::find($record['recebimento_id']) : null;

        return $recebimento
            ? app(CorrecaoPagamentoService::class)->motivoBloqueioRecebimento($recebimento)
            : 'Recebimento não encontrado.';
    }

    private static function forma(mixed $forma): ?FormaPagamento
    {
        return $forma instanceof FormaPagamento ? $forma : FormaPagamento::tryFrom((string) $forma);
    }
}
