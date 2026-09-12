<?php

namespace App\Filament\Resources\SessoesCaixa\Support;

use App\Enums\FormaPagamento;
use App\Models\SessaoCaixa;
use App\Services\MovimentacaoCaixaService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Leandrocfe\FilamentPtbrFormFields\Money;

/**
 * Registra um suprimento (reforço de troco / aporte) numa sessão ABERTA —
 * entrada manual de dinheiro (ou outro valor) no turno em andamento. Motivo
 * fixo (App\Enums\MotivoSaidaCaixa::Suprimento), não escolhido pelo operador —
 * é a única razão de existir desta ação.
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
                Select::make('forma_pagamento')
                    ->label('Forma de pagamento')
                    ->options(FormaPagamento::class)
                    ->default(FormaPagamento::Dinheiro)
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
            ->action(function (SessaoCaixa $record, array $data, MovimentacaoCaixaService $service, Action $action): void {
                try {
                    $service->registrarSuprimento(
                        sessao: $record,
                        valor: self::normalizeMoney($data['valor'] ?? null),
                        formaPagamento: $data['forma_pagamento'],
                        descricao: $data['descricao'],
                        observacoes: $data['observacoes'] ?? null,
                    );
                } catch (ValidationException $e) {
                    Notification::make()
                        ->title('Não foi possível registrar o suprimento')
                        ->body(collect($e->errors())->flatten()->first())
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
