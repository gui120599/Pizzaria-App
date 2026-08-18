<?php

namespace App\Filament\Resources\Compras\Support;

use App\Enums\CompraStatusEnum;
use App\Models\Compra;
use App\Models\CompraDevolucaoItem;
use App\Models\CompraItem;
use App\Services\CompraDevolucaoService;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Registra a devolução de itens de uma compra confirmada ao fornecedor:
 * baixa o estoque valorado (App\Services\EstoqueService::registrarSaida) e
 * gera um crédito com o fornecedor (App\Models\PrestadorCredito), abatido
 * automaticamente na próxima conta a pagar gerada para ele (ver
 * App\Services\CompraService::gerarContaPagar).
 */
class RegistrarDevolucaoAction
{
    public static function make(string $name = 'registrarDevolucao'): Action
    {
        return Action::make($name)
            ->label('Registrar devolução')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->visible(fn (Compra $record): bool => $record->compra_status === CompraStatusEnum::CONFIRMADA
                && auth()->user()->can('registrarDevolucao', $record))
            ->modalHeading('Registrar devolução ao fornecedor')
            ->modalDescription('Baixa o estoque dos itens devolvidos e gera um crédito com o fornecedor, abatido automaticamente na próxima compra dele. Esta ação não pode ser desfeita.')
            ->modalSubmitActionLabel('Registrar devolução')
            ->modalWidth('xl')
            ->schema(fn (Compra $record): array => [
                Repeater::make('itens')
                    ->label('Itens devolvidos')
                    ->schema([
                        Select::make('compra_item_id')
                            ->label('Item')
                            ->options(fn (): array => self::opcoesItens($record))
                            ->required()
                            ->searchable()
                            ->columnSpan(3),
                        TextInput::make('quantidade')
                            ->label('Quantidade')
                            ->numeric()
                            ->minValue(0.0001)
                            ->required()
                            ->columnSpan(1),
                    ])
                    ->columns(4)
                    ->addActionLabel('Adicionar item')
                    ->minItems(1)
                    ->columnSpanFull(),
                Textarea::make('motivo')
                    ->label('Motivo')
                    ->rows(2)
                    ->columnSpanFull(),
            ])
            ->action(function (Compra $record, array $data, CompraDevolucaoService $service, Action $action): void {
                try {
                    $service->registrar($record, $data['itens'] ?? [], $data['motivo'] ?? null);
                } catch (ValidationException $e) {
                    Notification::make()
                        ->title('Não foi possível registrar a devolução')
                        ->body(collect($e->errors())->flatten()->first())
                        ->danger()
                        ->send();

                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title('Devolução registrada')
                    ->body('Estoque baixado e crédito gerado com o fornecedor.')
                    ->success()
                    ->send();
            });
    }

    /** @return array<int, string> */
    private static function opcoesItens(Compra $record): array
    {
        return $record->itens
            ->mapWithKeys(function (CompraItem $item): array {
                $jaDevolvida = (float) CompraDevolucaoItem::where('compra_item_id', $item->id)->sum('quantidade');
                $disponivel = round((float) $item->ci_quantidade_compra - $jaDevolvida, 4);

                if ($disponivel <= 0) {
                    return [];
                }

                return [$item->id => "{$item->ci_descricao_fornecedor} — disponível: {$disponivel}"];
            })
            ->filter()
            ->all();
    }
}
