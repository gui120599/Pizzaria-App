<?php

namespace App\Filament\Resources\Compras\Support;

use App\Models\Compra;
use App\Models\CompraDevolucao;
use App\Services\Compras\DevolucaoPorNotaService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * Nota de devolução do fornecedor importada em rascunho: em vez de "Confirmar"
 * (que daria ENTRADA no estoque), registra a devolução da compra original —
 * saída de estoque + crédito com o fornecedor — e deixa a nota sem efeito.
 * Ver App\Services\Compras\DevolucaoPorNotaService.
 */
class RegistrarDevolucaoNotaAction
{
    public static function make(string $name = 'registrarDevolucaoNota'): Action
    {
        return Action::make($name)
            ->label('Registrar devolução da nota')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->visible(fn (Compra $record): bool => $record->isRascunho()
                && $record->ehNotaDeDevolucao()
                && auth()->user()->can('registrarDevolucao', $record))
            ->modalHeading('Registrar devolução da nota')
            ->modalDescription('Esta nota é uma devolução ao fornecedor: baixa o estoque dos itens, gera um crédito com o fornecedor (abatido na próxima compra dele) e deixa a nota sem efeito. Não pode ser desfeito.')
            ->modalSubmitActionLabel('Registrar devolução')
            ->modalWidth('4xl')
            ->schema(fn (Compra $record, DevolucaoPorNotaService $service): array => [
                Select::make('compra_original_id')
                    ->label('Compra original')
                    ->options(fn (): array => $service->opcoesDeOriginal($record))
                    ->default($service->sugerirOriginal($record)?->id)
                    ->required()
                    ->searchable()
                    ->live()
                    ->helperText($service->sugerirOriginal($record)
                        ? 'Encontrada pela nota referenciada (NFref) no XML.'
                        : 'A nota não referencia uma compra nossa: escolha a compra que foi devolvida.'),
                Select::make('devolucao_existente_id')
                    ->label('Devolução já registrada à mão (opcional)')
                    ->options(fn (Get $get): array => filled($get('compra_original_id'))
                        ? $service->opcoesDeDevolucaoExistente(Compra::findOrFail($get('compra_original_id')))
                        : [])
                    ->placeholder('Nenhuma: baixar o estoque agora')
                    ->helperText('Se a baixa e o crédito já foram feitos em "Registrar devolução", escolha-a aqui: a nota só é vinculada, sem nova movimentação.')
                    ->live(),
                Placeholder::make('previa')
                    ->label('O que vai acontecer')
                    ->content(fn (Get $get): HtmlString => filled($get('devolucao_existente_id'))
                        ? new HtmlString('Nenhuma movimentação nova: a nota fiscal só é vinculada à devolução escolhida.')
                        : (filled($get('compra_original_id'))
                            ? DevolucaoNotaView::itens($service->linhas($record, Compra::findOrFail($get('compra_original_id'))))
                            : new HtmlString('Escolha a compra original.')))
                    ->columnSpanFull(),
                Textarea::make('motivo')->label('Motivo')->rows(2)->columnSpanFull(),
            ])
            ->action(function (Compra $record, array $data, DevolucaoPorNotaService $service, Action $action): void {
                try {
                    if (filled($data['devolucao_existente_id'] ?? null)) {
                        $service->vincularADevolucaoExistente($record, CompraDevolucao::findOrFail($data['devolucao_existente_id']));
                        $mensagem = 'Nota vinculada à devolução já registrada.';
                    } else {
                        $service->registrar($record, Compra::findOrFail($data['compra_original_id']), $data['motivo'] ?? null);
                        $mensagem = 'Estoque baixado e crédito gerado com o fornecedor.';
                    }
                } catch (ValidationException $e) {
                    Notification::make()
                        ->title('Não foi possível registrar a devolução')
                        ->body(collect($e->errors())->flatten()->first())
                        ->danger()
                        ->send();

                    $action->halt();

                    return;
                }

                Notification::make()->title('Devolução registrada')->body($mensagem)->success()->send();
            });
    }
}
