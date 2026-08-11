<?php

namespace App\Filament\Resources\SefazNotasRecebidas\Tables;

use App\Enums\SefazNotaRecebidaStatusEnum;
use App\Exceptions\DanfeGeracaoException;
use App\Exceptions\NfeXmlInvalidoException;
use App\Exceptions\SefazAutenticacaoException;
use App\Exceptions\SefazDocumentoAindaNaoDisponivelException;
use App\Exceptions\SefazIndisponivelException;
use App\Filament\Resources\Compras\CompraResource;
use App\Models\SefazNotaRecebida;
use App\Services\Nfe\DanfeService;
use App\Services\Nfe\Dto\NfeItem;
use App\Services\Nfe\Dto\NfeParseada;
use App\Services\Sefaz\SefazDistribuicaoService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SefazNotasRecebidasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('compra'))
            ->defaultSort('snr_data_emissao', 'desc')
            ->columns([
                TextColumn::make('snr_nome_emitente')
                    ->label('Emitente')
                    ->description(fn (SefazNotaRecebida $record): ?string => $record->snr_cnpj_emitente)
                    ->searchable(['snr_nome_emitente', 'snr_cnpj_emitente'])
                    ->placeholder('—'),

                TextColumn::make('snr_chave_acesso')
                    ->label('Chave de acesso')
                    ->copyable()
                    ->fontFamily('mono')
                    ->limit(20)
                    ->searchable(),

                TextColumn::make('snr_valor')
                    ->label('Valor')
                    ->money('BRL')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('snr_data_emissao')
                    ->label('Emissão')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('snr_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (SefazNotaRecebidaStatusEnum $state): string => $state->label())
                    ->color(fn (SefazNotaRecebidaStatusEnum $state): string => $state->cor()),

                TextColumn::make('snr_encontrada_em')
                    ->label('Encontrada em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('snr_status')
                    ->label('Status')
                    ->options(
                        collect(SefazNotaRecebidaStatusEnum::cases())
                            ->mapWithKeys(fn ($s) => [$s->value => $s->label()])
                            ->toArray()
                    )
                    ->default(SefazNotaRecebidaStatusEnum::PENDENTE->value),
            ])
            ->recordActions([
                self::verDetalhesAction(),
                self::imprimirDanfeAction(),
                self::importarAction(),
                self::ignorarAction(),
                Action::make('verCompra')
                    ->label('Ver compra')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->visible(fn (SefazNotaRecebida $record): bool => $record->snr_status === SefazNotaRecebidaStatusEnum::IMPORTADA && $record->snr_compra_id !== null)
                    ->url(fn (SefazNotaRecebida $record): string => CompraResource::getUrl('edit', ['record' => $record->snr_compra_id])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::importarSelecionadasAction(),
                ]),
            ]);
    }

    private static function verDetalhesAction(): Action
    {
        return Action::make('verDetalhes')
            ->label('Ver NF')
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->modalHeading(fn (SefazNotaRecebida $record): string => 'NF-e — '.($record->snr_nome_emitente ?? $record->snr_chave_acesso))
            ->modalWidth('4xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->visible(fn (SefazNotaRecebida $record): bool => $record->snr_status === SefazNotaRecebidaStatusEnum::PENDENTE)
            ->modalContent(function (SefazNotaRecebida $record, SefazDistribuicaoService $service): HtmlString {
                try {
                    $nfe = $service->visualizarNota($record);
                } catch (SefazDocumentoAindaNaoDisponivelException $e) {
                    return new HtmlString(self::mensagemModal($e->getMessage(), 'warning'));
                } catch (SefazAutenticacaoException|SefazIndisponivelException $e) {
                    return new HtmlString(self::mensagemModal($e->getMessage(), 'danger'));
                } catch (NfeXmlInvalidoException $e) {
                    return new HtmlString(self::mensagemModal('XML devolvido pela SEFAZ inválido: '.$e->getMessage(), 'danger'));
                }

                return new HtmlString(self::gerarHtmlNota($nfe));
            });
    }

    private static function imprimirDanfeAction(): Action
    {
        return Action::make('imprimirDanfe')
            ->label('Imprimir DANFE')
            ->icon('heroicon-o-printer')
            ->color('gray')
            ->action(function (SefazNotaRecebida $record, SefazDistribuicaoService $service, DanfeService $danfe): ?StreamedResponse {
                try {
                    $xml = $service->obterXmlDaNota($record);
                } catch (SefazDocumentoAindaNaoDisponivelException $e) {
                    Notification::make()
                        ->title('Nota manifestada, aguardando liberação')
                        ->body($e->getMessage())
                        ->warning()
                        ->persistent()
                        ->send();

                    return null;
                } catch (SefazAutenticacaoException|SefazIndisponivelException $e) {
                    Notification::make()
                        ->title('Não foi possível consultar a SEFAZ')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return null;
                }

                try {
                    $pdf = $danfe->gerar($xml);
                } catch (DanfeGeracaoException $e) {
                    Notification::make()
                        ->title('Não foi possível gerar o DANFE')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return null;
                }

                return response()->streamDownload(
                    fn () => print ($pdf),
                    "DANFE-{$record->snr_chave_acesso}.pdf",
                    ['Content-Type' => 'application/pdf'],
                );
            });
    }

    private static function mensagemModal(string $mensagem, string $tipo): string
    {
        $cor = $tipo === 'danger' ? 'text-danger-600' : 'text-warning-600';

        return '<div class="p-6 text-center"><p class="text-sm '.$cor.'">'.e($mensagem).'</p></div>';
    }

    /**
     * Gera o HTML da prévia da nota — todo texto vindo do XML (emitente,
     * descrição dos itens) é de origem externa (fornecedor), nunca confiável:
     * escapado via e() antes de entrar no HTML pra evitar XSS.
     */
    private static function gerarHtmlNota(NfeParseada $nfe): string
    {
        $emitente = e($nfe->emitente->razaoSocial ?? $nfe->emitente->nomeFantasia ?? $nfe->emitente->documento);
        $numeroSerie = e(trim(($nfe->numero ? "Nº {$nfe->numero}" : '').($nfe->serie ? " / Série {$nfe->serie}" : '')));
        $emissao = $nfe->dataEmissao?->format('d/m/Y') ?? '—';

        $html = '<div class="space-y-4 p-2">';
        $html .= '<div class="grid grid-cols-2 gap-2 text-sm">';
        $html .= "<div><span class=\"font-semibold\">Emitente:</span> {$emitente}</div>";
        $html .= '<div><span class="font-semibold">CNPJ:</span> '.e($nfe->emitente->documento).'</div>';
        $html .= '<div><span class="font-semibold">Emissão:</span> '.e($emissao).'</div>';
        $html .= '<div><span class="font-semibold">Nota:</span> '.($numeroSerie !== '' ? $numeroSerie : '—').'</div>';
        $html .= '</div>';

        foreach ($nfe->avisos() as $aviso) {
            $html .= '<div class="rounded bg-warning-50 p-2 text-xs text-warning-700">'.e($aviso).'</div>';
        }

        $html .= '<div class="overflow-x-auto"><table class="w-full text-sm"><thead>';
        $html .= '<tr class="border-b-2 border-gray-300"><th class="p-2 text-left">Item</th>';
        $html .= '<th class="p-2 text-right">Qtd.</th><th class="p-2 text-right">Unitário</th>';
        $html .= '<th class="p-2 text-right">Total</th></tr></thead><tbody>';

        foreach ($nfe->itens as $item) {
            $html .= '<tr class="border-b border-gray-200">';
            $html .= '<td class="p-2">'.e($item->descricao).'</td>';
            $html .= '<td class="p-2 text-right">'.self::formatarQuantidade($item).'</td>';
            $html .= '<td class="p-2 text-right">'.self::formatarMoeda($item->valorUnitarioComercial).'</td>';
            $html .= '<td class="p-2 text-right font-semibold">'.self::formatarMoeda($item->valorTotalBruto).'</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div>';

        $html .= '<div class="flex justify-end"><div class="w-64 space-y-1 text-sm">';
        $html .= '<div class="flex justify-between"><span>Produtos</span><span>'.self::formatarMoeda($nfe->valorProdutos).'</span></div>';
        if ($nfe->valorFrete > 0) {
            $html .= '<div class="flex justify-between"><span>Frete</span><span>'.self::formatarMoeda($nfe->valorFrete).'</span></div>';
        }
        if ($nfe->valorDesconto > 0) {
            $html .= '<div class="flex justify-between"><span>Desconto</span><span>-'.self::formatarMoeda($nfe->valorDesconto).'</span></div>';
        }
        if ($nfe->valorOutros > 0) {
            $html .= '<div class="flex justify-between"><span>Outros</span><span>'.self::formatarMoeda($nfe->valorOutros).'</span></div>';
        }
        $html .= '<div class="flex justify-between border-t border-gray-300 pt-1 font-semibold"><span>Total</span><span>'.self::formatarMoeda($nfe->valorTotalNota).'</span></div>';
        $html .= '</div></div>';

        $html .= '</div>';

        return $html;
    }

    private static function formatarQuantidade(NfeItem $item): string
    {
        return number_format($item->quantidadeComercial, 2, ',', '.').' '.e($item->unidadeComercial);
    }

    private static function formatarMoeda(float $valor): string
    {
        return 'R$ '.number_format($valor, 2, ',', '.');
    }

    private static function importarAction(): Action
    {
        return Action::make('importar')
            ->label('Importar')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('Consulta a SEFAZ, manifesta ciência se necessário, e importa como rascunho de compra.')
            ->visible(fn (SefazNotaRecebida $record): bool => $record->snr_status === SefazNotaRecebidaStatusEnum::PENDENTE)
            ->action(function (SefazNotaRecebida $record, SefazDistribuicaoService $service, Component $livewire): void {
                try {
                    $compra = $service->importarNota($record, auth()->id());
                } catch (SefazDocumentoAindaNaoDisponivelException $e) {
                    Notification::make()
                        ->title('Nota manifestada, aguardando liberação')
                        ->body($e->getMessage())
                        ->warning()
                        ->persistent()
                        ->send();

                    return;
                } catch (SefazAutenticacaoException $e) {
                    Notification::make()
                        ->title('Falha de autenticação com a SEFAZ')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                } catch (SefazIndisponivelException $e) {
                    Notification::make()
                        ->title('Não foi possível consultar a SEFAZ')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                } catch (ValidationException $e) {
                    Notification::make()
                        ->title('Não foi possível importar')
                        ->body(collect($e->errors())->flatten()->first())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Compra importada')
                    ->body('Revise os itens e confirme quando estiver tudo certo.')
                    ->success()
                    ->send();

                $livewire->redirect(CompraResource::getUrl('edit', ['record' => $compra]));
            });
    }

    private static function ignorarAction(): Action
    {
        return Action::make('ignorar')
            ->label('Ignorar')
            ->icon('heroicon-o-x-mark')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription('A nota some da lista de pendentes. Você pode importá-la depois por chave, se mudar de ideia.')
            ->visible(fn (SefazNotaRecebida $record): bool => $record->snr_status === SefazNotaRecebidaStatusEnum::PENDENTE)
            ->action(function (SefazNotaRecebida $record, SefazDistribuicaoService $service): void {
                $service->ignorarNota($record);

                Notification::make()
                    ->title('Nota ignorada')
                    ->success()
                    ->send();
            });
    }

    private static function importarSelecionadasAction(): BulkAction
    {
        return BulkAction::make('importarSelecionadas')
            ->label('Importar selecionadas')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('success')
            ->requiresConfirmation()
            ->modalWidth('md')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, SefazDistribuicaoService $service): void {
                $importadas = 0;
                $pendentes = 0;
                $erros = 0;

                foreach ($records->where('snr_status', SefazNotaRecebidaStatusEnum::PENDENTE) as $record) {
                    try {
                        $service->importarNota($record, auth()->id());
                        $importadas++;
                    } catch (SefazDocumentoAindaNaoDisponivelException) {
                        $pendentes++;
                    } catch (SefazAutenticacaoException|SefazIndisponivelException|ValidationException) {
                        $erros++;
                    }
                }

                Notification::make()
                    ->title('Importação em lote concluída')
                    ->body("{$importadas} importada(s), {$pendentes} ainda não liberada(s), {$erros} com erro.")
                    ->success()
                    ->send();
            });
    }
}
