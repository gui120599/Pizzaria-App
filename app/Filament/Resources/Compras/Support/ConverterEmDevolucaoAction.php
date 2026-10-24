<?php

namespace App\Filament\Resources\Compras\Support;

use App\Enums\CompraStatusEnum;
use App\Models\Compra;
use App\Services\Compras\CompraEstornoService;
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
 * Corrige uma nota de devolução que foi CONFIRMADA como compra (entrada de
 * estoque + título): estorna a entrada e os títulos e registra a devolução da
 * compra original, tudo numa transação. Mostra a prévia (simulação com
 * rollback) antes de aplicar. Ver App\Services\Compras\CompraEstornoService.
 */
class ConverterEmDevolucaoAction
{
    public static function make(string $name = 'converterEmDevolucao'): Action
    {
        return Action::make($name)
            ->label('Converter em devolução')
            ->icon('heroicon-o-arrow-path')
            ->color('danger')
            ->visible(fn (Compra $record): bool => $record->compra_status === CompraStatusEnum::CONFIRMADA
                && $record->ehNotaDeDevolucao()
                && auth()->user()->can('estornar', $record))
            ->modalHeading('Converter em devolução')
            ->modalDescription('Esta nota de devolução foi confirmada como compra. A entrada de estoque será desfeita (com recálculo do custo médio), os títulos a pagar cancelados e a devolução registrada na compra original. Confira a prévia antes de confirmar.')
            ->modalSubmitActionLabel('Estornar e registrar devolução')
            ->modalWidth('4xl')
            ->schema(fn (Compra $record, DevolucaoPorNotaService $service, CompraEstornoService $estorno): array => [
                Placeholder::make('previa_estorno')
                    ->label('Prévia do estorno')
                    ->content(fn (): HtmlString => DevolucaoNotaView::estorno($estorno->simular($record)))
                    ->columnSpanFull(),
                Select::make('compra_original_id')
                    ->label('Compra original')
                    ->options(fn (): array => $service->opcoesDeOriginal($record))
                    ->default($service->sugerirOriginal($record)?->id)
                    ->required()
                    ->searchable()
                    ->live(),
                Placeholder::make('previa_devolucao')
                    ->label('Devolução a registrar')
                    ->content(fn (Get $get): HtmlString => filled($get('compra_original_id'))
                        ? DevolucaoNotaView::itens($service->linhas($record, Compra::findOrFail($get('compra_original_id'))))
                        : new HtmlString('Escolha a compra original.'))
                    ->columnSpanFull(),
                Textarea::make('motivo')->label('Motivo do estorno')->required()->rows(2)->columnSpanFull(),
            ])
            ->action(function (Compra $record, array $data, DevolucaoPorNotaService $service, Action $action): void {
                try {
                    $service->converterCompraConfirmada($record, Compra::findOrFail($data['compra_original_id']), $data['motivo']);
                } catch (ValidationException $e) {
                    Notification::make()
                        ->title('Não foi possível converter em devolução')
                        ->body(collect($e->errors())->flatten()->first())
                        ->danger()
                        ->send();

                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title('Nota convertida em devolução')
                    ->body('Entrada estornada, títulos cancelados, estoque baixado e crédito gerado com o fornecedor.')
                    ->success()
                    ->send();
            });
    }
}
