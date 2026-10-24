<?php

namespace App\Filament\Resources\Vendas\Tables\Actions;

use App\Enums\MotivoCancelamentoEnum;
use App\Models\Venda;
use App\Services\CancelamentoVendaService;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Cancela em lote vendas finalizadas (estorna o estoque da venda direta e
 * devolve os pedidos ao recebimento do PDV — ver CancelamentoVendaService).
 * Venda com NFC-e autorizada, fechamento confirmado, fiado ou pagamento Stone
 * é pulada com o motivo: esses casos seguem o fluxo individual.
 */
class CancelarVendasEmLoteBulkAction
{
    public static function make(): BulkAction
    {
        return BulkAction::make('cancelarVendasEmLote')
            ->label('Cancelar vendas')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->authorize(fn (): bool => auth()->user()?->can('cancelar_finalizada:venda') ?? false)
            ->modalHeading(fn (Collection $records): string => 'Cancelar '.$records->count().' venda(s)?')
            ->modalDescription(fn (Collection $records): string => 'Total selecionado: R$ '.number_format((float) $records->sum('venda_valor_total'), 2, ',', '.')
                .'. Os itens lançados direto na venda voltam ao estoque e os pedidos voltam para a lista de recebimento do PDV. '
                .'Vendas com NFC-e autorizada, fechamento confirmado, a prazo ou pagas na Stone são puladas.')
            ->modalSubmitActionLabel('Cancelar vendas')
            ->schema([
                Select::make('motivo')
                    ->label('Motivo do cancelamento')
                    ->options(collect(MotivoCancelamentoEnum::cases())->mapWithKeys(fn (MotivoCancelamentoEnum $motivo): array => [$motivo->value => $motivo->label()])->all())
                    ->required(),
            ])
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, array $data, CancelamentoVendaService $cancelamento): void {
                $motivo = MotivoCancelamentoEnum::from($data['motivo']);
                $canceladas = 0;
                $puladas = [];
                $falhas = [];

                foreach ($records as $venda) {
                    /** @var Venda $venda */
                    if ($bloqueio = $cancelamento->motivoBloqueioCancelamento($venda)) {
                        $puladas[] = "#{$venda->id}: {$bloqueio}";

                        continue;
                    }

                    try {
                        $cancelamento->cancelarFinalizada($venda, $motivo, auth()->id());
                        $canceladas++;
                    } catch (Throwable $e) {
                        report($e);
                        $falhas[] = "#{$venda->id}: {$e->getMessage()}";
                    }
                }

                RelatorioEmLote::notificar('Cancelamento de vendas', $canceladas, 'cancelada(s)', $puladas, $falhas);
            });
    }
}
