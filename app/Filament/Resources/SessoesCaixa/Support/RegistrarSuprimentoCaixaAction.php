<?php

namespace App\Filament\Resources\SessoesCaixa\Support;

use App\Exceptions\FinanceiroException;
use App\Filament\Components\ContaFinanceiraSelect;
use App\Models\ContaFinanceira;
use App\Models\SessaoCaixa;
use App\Services\Financeiro\MovimentacaoService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Leandrocfe\FilamentPtbrFormFields\Money;

/**
 * Registra um suprimento (reforço de troco) numa sessão ABERTA: transferência
 * de outra conta para o Caixa PDV, que grava o suprimento na sessão
 * (CaixaPdvBridge, motivo Suprimento).
 */
class RegistrarSuprimentoCaixaAction
{
    public static function make(string $name = 'registrarSuprimentoCaixa'): Action
    {
        return Action::make($name)
            ->label('Registrar suprimento')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('success')
            ->visible(fn (SessaoCaixa $record): bool => $record->sessaocaixa_status === 'ABERTA'
                && auth()->user()?->can('sangrar', $record))
            ->modalHeading('Registrar suprimento de caixa')
            ->modalDescription('Reforço de troco ou aporte de dinheiro (ou outro valor) no turno em andamento.')
            ->modalSubmitActionLabel('Registrar suprimento')
            ->schema([
                ContaFinanceiraSelect::make('conta_origem_id')
                    ->label('Vem de qual conta')
                    ->semCaixaPdv()
                    ->placeholder('Selecione a conta')
                    ->helperText('Cofre, banco ou outra conta de onde sai o dinheiro do reforço.')
                    ->required(),
                Money::make('valor')
                    ->label('Valor')
                    ->minValue(0.01)
                    ->required(),
                TextInput::make('descricao')
                    ->label('Descrição')
                    ->required()
                    ->maxLength(255)
                    ->default('Reforço de troco')
                    ->columnSpanFull(),
                Textarea::make('observacoes')
                    ->label('Observações')
                    ->rows(2)
                    ->columnSpanFull(),
            ])
            ->action(function (SessaoCaixa $record, array $data, MovimentacaoService $movimentacoes, Action $action): void {
                try {
                    $caixaPdv = ContaFinanceira::caixaPdv() ?? throw new FinanceiroException('Cadastre a conta Caixa PDV para registrar suprimentos.');

                    // O Caixa PDV grava o suprimento na sessão (CaixaPdvBridge).
                    $movimentacoes->transferir(
                        ContaFinanceira::query()->findOrFail($data['conta_origem_id']),
                        $caixaPdv,
                        self::normalizeMoney($data['valor'] ?? null),
                        today(),
                        trim($data['descricao'].(filled($data['observacoes'] ?? null) ? ' — '.trim($data['observacoes']) : '')),
                        auth()->user(),
                        $record,
                    );
                } catch (ValidationException|FinanceiroException $e) {
                    Notification::make()
                        ->title('Não foi possível registrar o suprimento')
                        ->body($e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage())
                        ->danger()
                        ->send();

                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title('Suprimento registrado com sucesso!')
                    ->success()
                    ->send();
            });
    }

    /** Ver RegistrarSaidaCaixaAction::normalizeMoney(). */
    private static function normalizeMoney(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return (float) str_replace(['.', ','], ['', '.'], (string) $value);
    }
}
