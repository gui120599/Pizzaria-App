<?php

namespace App\Filament\Resources\Vendas\Tables\Actions;

use App\Models\Venda;
use App\Services\Stone\StoneRecebimentoService;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;

/**
 * Retira da lista da maquininha as cobranças Stone abertas das vendas
 * selecionadas (ex.: o cliente pagou de outra forma). Não mexe na venda.
 */
class CancelarCobrancasStoneEmLoteBulkAction
{
    public static function make(): BulkAction
    {
        return BulkAction::make('cancelarCobrancasStoneEmLote')
            ->label('Cancelar cobranças na maquininha')
            ->icon('heroicon-o-device-phone-mobile')
            ->color('danger')
            ->authorize(fn (): bool => auth()->user()?->can('operar:venda') ?? false)
            ->requiresConfirmation()
            ->modalHeading('Cancelar cobranças na maquininha')
            ->modalDescription('Retira da lista da maquininha as cobranças abertas das vendas selecionadas. As vendas não são alteradas; as que não têm cobrança aberta são ignoradas.')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, StoneRecebimentoService $recebimento): void {
                $records->loadMissing('stonePedidos');

                $resultado = $recebimento->cancelarCobrancasNaStone(
                    $records->flatMap(fn (Venda $venda) => $venda->stonePedidos)
                );

                Notification::make()
                    ->title("{$resultado['cancelados']} cobrança(s) cancelada(s) na maquininha.")
                    ->body($resultado['recusados'] === [] ? null : implode("\n", $resultado['recusados']))
                    ->color($resultado['recusados'] === [] ? 'success' : 'warning')
                    ->send();
            });
    }
}
