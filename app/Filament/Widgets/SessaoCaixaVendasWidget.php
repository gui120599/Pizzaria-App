<?php

namespace App\Filament\Widgets;

use App\Exceptions\CorrecaoPagamentoException;
use App\Exceptions\NfeIoException;
use App\Filament\Pages\RelatorioSessaoCaixa;
use App\Filament\Support\CorrecaoPagamentoFormulario;
use App\Models\NfEmissao;
use App\Models\Venda;
use App\Services\CorrecaoPagamentoService;
use App\Services\NfeIoService;
use App\Services\RelatorioSessaoCaixaService;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Uma linha por venda: lançado × combinado no pedido, fiado, troco e avisos.
 * Quem tem corrigir:pagamento_venda corrige a forma de um pagamento, divide um
 * pagamento ou troca a forma do troco (CorrecaoPagamentoService); venda com
 * NFC-e autorizada precisa cancelar a nota antes e reemitir depois.
 */
class SessaoCaixaVendasWidget extends BaseWidget
{
    use HasWidgetShield;
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    /** @var array<int, ?string> motivo de bloqueio por venda, memorizado por request */
    private array $bloqueios = [];

    public function table(Table $table): Table
    {
        return $table
            ->heading('Vendas')
            ->description('O que foi lançado em cada venda × o que o pedido combinou. Os avisos apontam lançamentos suspeitos.')
            ->records(function (int $page, int $recordsPerPage): LengthAwarePaginator {
                $linhas = (new RelatorioSessaoCaixaService($this->pageFilters ?? []))->vendas();

                return new LengthAwarePaginator(
                    $linhas->forPage($page, $recordsPerPage)->keyBy('key')->all(),
                    $linhas->count(),
                    $recordsPerPage,
                    $page,
                );
            })
            ->emptyStateHeading('Nenhuma venda no recorte')
            ->columns([
                TextColumn::make('venda_id')
                    ->label('Venda')
                    ->prefix('#'),
                TextColumn::make('finalizada_em')
                    ->label('Finalizada')
                    ->dateTime('d/m H:i'),
                TextColumn::make('cliente')
                    ->label('Cliente')
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('origens')
                    ->label('Origem')
                    ->badge()
                    ->color('gray')
                    ->placeholder('Direto no PDV'),
                ViewColumn::make('pagamentos')
                    ->label('Lançado × pedido')
                    ->view('filament.widgets.partials.sessao-caixa-pagamentos'),
                TextColumn::make('total')
                    ->label('Total')
                    ->money('BRL'),
                TextColumn::make('fiado')
                    ->label('A prazo')
                    ->money('BRL')
                    ->color(fn (float $state): string => $state > 0 ? 'warning' : 'gray'),
                ViewColumn::make('avisos')
                    ->label('Avisos')
                    ->view('filament.widgets.partials.sessao-caixa-avisos'),
            ])
            ->recordActions([
                $this->acaoCorrigir(),
                $this->acaoCancelarNfe(),
                $this->acaoReemitirNfe(),
            ]);
    }

    private function acaoCorrigir(): Action
    {
        return Action::make('corrigir')
            ->label('Corrigir')
            ->icon(Heroicon::OutlinedWrenchScrewdriver)
            ->color('warning')
            ->visible(fn (array $record): bool => self::podeCorrigir() && $record['pagamentos'] !== [])
            ->authorize(fn (): bool => self::podeCorrigir())
            ->disabled(fn (array $record): bool => $this->motivoBloqueio($record) !== null)
            ->tooltip(fn (array $record): ?string => $this->motivoBloqueio($record))
            ->modalHeading(fn (array $record): string => "Corrigir pagamento — venda #{$record['venda_id']}")
            ->modalDescription('Só muda a forma em que o dinheiro entrou; o valor e o total da venda continuam iguais. O rascunho do fechamento é recalculado.')
            ->modalSubmitActionLabel('Salvar correção')
            ->schema(fn (array $record): array => CorrecaoPagamentoFormulario::schema($record['pagamentos']))
            ->action(function (array $record, array $data, CorrecaoPagamentoService $service): void {
                try {
                    CorrecaoPagamentoFormulario::aplicar($record['venda_id'], $data, $service);
                } catch (CorrecaoPagamentoException $e) {
                    Notification::make()->title('Correção não feita')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title("Venda #{$record['venda_id']} corrigida")->success()->send();
                $this->recarregarPagina();
            });
    }

    private function acaoCancelarNfe(): Action
    {
        return Action::make('cancelarNfeParaCorrigir')
            ->label('Cancelar NFC-e')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (array $record): bool => self::podeCorrigir() && $record['status_nfe'] === NfEmissao::STATUS_AUTORIZADA)
            ->authorize(fn (): bool => self::podeCorrigir())
            ->requiresConfirmation()
            ->modalHeading(fn (array $record): string => "Cancelar a NFC-e da venda #{$record['venda_id']}?")
            ->modalDescription('Para corrigir a forma de pagamento a nota precisa ser cancelada; depois da correção ela é reemitida com outro número. O cancelamento é confirmado pela SEFAZ (webhook) e tem prazo curto — se for recusado, a correção fica bloqueada.')
            ->action(function (array $record, NfeIoService $nfe): void {
                $venda = Venda::findOrFail($record['venda_id']);

                try {
                    $nfe->cancelar($venda->venda_id_nfe);
                } catch (NfeIoException $e) {
                    Notification::make()->title('Não foi possível cancelar a NFC-e')->body($e->getMessage())->danger()->send();

                    return;
                }

                $venda->update(['venda_status_nfe' => 'CancelamentoSolicitado']);
                NfEmissao::espelharStatus($venda->venda_id_nfe, 'CancelamentoSolicitado');

                Notification::make()->title('Cancelamento solicitado')->body('Assim que a SEFAZ confirmar, a correção é liberada.')->success()->send();
                $this->resetTable();
            });
    }

    private function acaoReemitirNfe(): Action
    {
        return Action::make('reemitirNfe')
            ->label('Reemitir NFC-e')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('primary')
            ->visible(fn (array $record): bool => self::podeCorrigir() && $record['status_nfe'] === 'Cancelled' && $record['correcoes'] !== [])
            ->authorize(fn (): bool => self::podeCorrigir())
            ->requiresConfirmation()
            ->modalDescription('Emite uma NFC-e nova (outro número) com as formas de pagamento corrigidas.')
            ->action(function (array $record, NfeIoService $nfe): void {
                try {
                    $nfe->emitir(Venda::findOrFail($record['venda_id']));
                } catch (NfeIoException $e) {
                    Notification::make()->title('Não foi possível reemitir a NFC-e')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('NFC-e enviada')->body('A autorização chega pelo webhook da NFe.io.')->success()->send();
                $this->resetTable();
            });
    }

    /** @param  array<string, mixed>  $record */
    private function motivoBloqueio(array $record): ?string
    {
        if (! array_key_exists($record['venda_id'], $this->bloqueios)) {
            $venda = Venda::find($record['venda_id']);
            $this->bloqueios[$record['venda_id']] = $venda
                ? app(CorrecaoPagamentoService::class)->motivoBloqueioVenda($venda)
                : 'Venda não encontrada.';
        }

        return $this->bloqueios[$record['venda_id']];
    }

    /** Recarrega a página com os mesmos filtros: fluxo e conferência (outros widgets) também mudam. */
    private function recarregarPagina(): void
    {
        $this->redirect(RelatorioSessaoCaixa::getUrl(['filters' => $this->pageFilters ?? []]));
    }

    public static function podeCorrigir(): bool
    {
        return auth()->user()?->can('corrigir:pagamento_venda') ?? false;
    }

    /** @return array<int, string> */
    public static function opcoesSemTaxa(): array
    {
        return CorrecaoPagamentoFormulario::opcoesSemTaxa();
    }

    /** @return array<int, string> */
    public static function maquininhas(): array
    {
        return CorrecaoPagamentoFormulario::maquininhas();
    }

    /** @return array<int, string> */
    public static function bandeiras(): array
    {
        return CorrecaoPagamentoFormulario::bandeiras();
    }
}
