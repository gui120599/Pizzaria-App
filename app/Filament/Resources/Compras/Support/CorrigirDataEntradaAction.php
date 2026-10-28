<?php

namespace App\Filament\Resources\Compras\Support;

use App\Enums\CompraStatusEnum;
use App\Enums\VencimentoOrigemEnum;
use App\Models\Compra;
use App\Services\CompraService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Corrige a data de entrada no estoque de uma compra já confirmada — caso
 * típico: nota importada num dia e mercadoria lançada dias depois, confirmada
 * sem perceber a data. Ajusta compra, movimentações, lotes e desloca os
 * vencimentos dos títulos em aberto (ver CompraService::corrigirDataEntrada()).
 */
class CorrigirDataEntradaAction
{
    public static function make(string $name = 'corrigirDataEntrada'): Action
    {
        return Action::make($name)
            ->label('Corrigir data de entrada')
            ->icon('heroicon-o-calendar-days')
            ->color('warning')
            ->visible(fn (Compra $record): bool => $record->compra_status === CompraStatusEnum::CONFIRMADA
                && auth()->user()->can('corrigirDataEntrada', $record))
            ->modalHeading('Corrigir data de entrada no estoque')
            ->modalDescription('Ajusta a data das movimentações de estoque e dos lotes desta compra. Se você optar por deslocar, os vencimentos das contas a pagar em aberto mudam pela mesma diferença de dias; títulos pagos nunca mudam. Saldo e custo médio não são alterados.')
            ->modalSubmitActionLabel('Corrigir data')
            ->modalWidth('lg')
            ->schema([
                ConfirmarCompraAction::campoDataEntrada()
                    ->label('Data correta de entrada')
                    ->default(fn (Compra $record) => $record->compra_data_entrada),
                // O padrão vem da origem dos vencimentos: contados da entrada acompanham a
                // correção; os da nota (duplicatas) ou da emissão não dependem dela.
                Toggle::make('deslocar_vencimentos')
                    ->label('Deslocar vencimentos em aberto')
                    ->default(fn (Compra $record): bool => VencimentoOrigemEnum::dependeDaEntrada($record->compra_vencimento_origem))
                    ->helperText(fn (Compra $record): ?string => VencimentoOrigemEnum::dependeDaEntrada($record->compra_vencimento_origem)
                        ? null
                        : 'Os vencimentos foram contados a partir da nota/emissão e não dependem da entrada.')
                    ->live(),
                Placeholder::make('previa')
                    ->label('Será ajustado')
                    ->content(function (Get $get, Compra $record, CompraService $service): string {
                        $previa = $service->previaCorrecaoDataEntrada($record);
                        $titulos = $get('deslocar_vencimentos') ? " e {$previa['titulos']} título(s) a pagar em aberto" : '';

                        return "{$previa['movimentacoes']} movimentação(ões) de estoque, {$previa['lotes']} lote(s){$titulos}.";
                    }),
            ])
            ->action(function (Compra $record, array $data, CompraService $service, Action $action): void {
                try {
                    $resultado = $service->corrigirDataEntrada($record, Carbon::parse($data['data_entrada']), auth()->user()?->name ?: auth()->user()?->name_first, (bool) ($data['deslocar_vencimentos'] ?? true));
                } catch (ValidationException $e) {
                    Notification::make()
                        ->title('Não foi possível corrigir a data')
                        ->body(collect($e->errors())->flatten()->first())
                        ->danger()
                        ->send();

                    $action->halt();

                    return;
                }

                $deslocamento = $resultado['titulos'] > 0
                    ? " {$resultado['titulos']} vencimento(s) deslocado(s) em ".sprintf('%+d', $resultado['dias']).' dia(s).'
                    : '';

                Notification::make()
                    ->title('Data de entrada corrigida')
                    ->body("{$resultado['movimentacoes']} movimentação(ões) e {$resultado['lotes']} lote(s) ajustados.{$deslocamento}")
                    ->success()
                    ->send();

                $record->refresh();
            });
    }
}
