<?php

namespace App\Filament\Resources\ContasFinanceiras\Pages;

use App\Filament\Resources\ContasFinanceiras\ContaFinanceiraResource;
use App\Filament\Resources\ContasFinanceiras\Support\TransferirEntreContasAction;
use App\Models\ContaFinanceira;
use App\Services\Financeiro\MovimentacaoService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListContasFinanceiras extends ListRecords
{
    protected static string $resource = ContaFinanceiraResource::class;

    protected function getHeaderActions(): array
    {
        return [
            TransferirEntreContasAction::make(),
            Action::make('recalcularSaldos')
                ->label('Recalcular saldos')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can('ajustar_saldo:conta_financeira') ?? false)
                ->requiresConfirmation()
                ->modalDescription('Reconstrói o saldo de cada conta a partir do saldo inicial e das movimentações.')
                ->action(function (MovimentacaoService $movimentacoes): void {
                    $corrigidas = ContaFinanceira::withTrashed()->get()
                        ->filter(function (ContaFinanceira $conta) use ($movimentacoes): bool {
                            $saldo = $movimentacoes->recalcularSaldo($conta);

                            return abs($saldo['anterior'] - $saldo['atual']) >= 0.01;
                        });

                    Notification::make()
                        ->title($corrigidas->isEmpty() ? 'Saldos conferidos: nenhuma divergência' : 'Saldos corrigidos')
                        ->body($corrigidas->isEmpty() ? null : $corrigidas->pluck('nome')->join(', '))
                        ->success()
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}
