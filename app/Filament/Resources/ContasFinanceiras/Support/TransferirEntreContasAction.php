<?php

namespace App\Filament\Resources\ContasFinanceiras\Support;

use App\Exceptions\FinanceiroException;
use App\Filament\Components\ContaFinanceiraSelect;
use App\Models\ContaFinanceira;
use App\Models\SessaoCaixa;
use App\Services\Financeiro\MovimentacaoService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Leandrocfe\FilamentPtbrFormFields\Money;

/**
 * Sangria para o cofre, depósito no banco, saque: duas movimentações com o
 * mesmo transferencia_uuid, sem lançamento. Ponta no Caixa PDV vira
 * sangria/suprimento na sessão aberta.
 */
class TransferirEntreContasAction
{
    public static function make(string $name = 'transferir', ?ContaFinanceira $origemPadrao = null): Action
    {
        return Action::make($name)
            ->label('Transferir')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('info')
            ->visible(fn (): bool => auth()->user()?->can('transferir:conta_financeira') ?? false)
            ->modalHeading('Transferir entre contas')
            ->modalSubmitActionLabel('Transferir')
            ->schema([
                ContaFinanceiraSelect::make('conta_financeira_id')
                    ->label('Sai de')
                    ->default(fn (?ContaFinanceira $record): ?int => $record?->id ?? $origemPadrao?->id)
                    ->required(),
                ContaFinanceiraSelect::make('destino_id')
                    ->label('Vai para')
                    ->different('conta_financeira_id')
                    ->required(),
                ContaFinanceiraSelect::sessaoCaixa('conta_financeira_id', 'destino_id'),
                Money::make('valor')
                    ->label('Valor')
                    ->minValue(0.01)
                    ->required(),
                DatePicker::make('data')
                    ->label('Data')
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->default(today())
                    ->maxDate(today())
                    ->required(),
                TextInput::make('descricao')
                    ->label('Descrição')
                    ->placeholder('Ex.: Sangria para o cofre')
                    ->maxLength(255),
            ])
            ->action(function (array $data, MovimentacaoService $movimentacoes, Action $action): void {
                try {
                    $movimentacoes->transferir(
                        ContaFinanceira::query()->findOrFail($data['conta_financeira_id']),
                        ContaFinanceira::query()->findOrFail($data['destino_id']),
                        ValorMonetario::normalizar($data['valor'] ?? null),
                        Carbon::parse($data['data']),
                        $data['descricao'] ?? null,
                        auth()->user(),
                        filled($data['sessao_caixa_id'] ?? null) ? SessaoCaixa::query()->find($data['sessao_caixa_id']) : null,
                    );
                } catch (FinanceiroException $e) {
                    Notification::make()->title('Transferência não feita')->body($e->getMessage())->danger()->send();
                    $action->halt();
                }

                Notification::make()->title('Transferência registrada')->success()->send();
            });
    }
}
