<?php

namespace App\Filament\Resources\SessoesCaixa\Support;

use App\Enums\FormaPagamento;
use App\Enums\MotivoSaidaCaixa;
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
 * Registra uma saída (sangria) de caixa numa sessão ABERTA — traz pro Filament
 * o que hoje só existe no fluxo legado Blade (MovimentacoesSessaoCaixaController,
 * rotas /Saidas*), com motivo estruturado e recompute correto do saldo (ver
 * App\Services\MovimentacaoCaixaService).
 */
class RegistrarSaidaCaixaAction
{
    public static function make(string $name = 'registrarSaidaCaixa'): Action
    {
        return Action::make($name)
            ->label('Registrar saída')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('danger')
            ->visible(fn (SessaoCaixa $record): bool => $record->sessaocaixa_status === 'ABERTA'
                && auth()->user()?->can('sangrar', $record))
            ->modalHeading('Registrar saída de caixa')
            ->modalDescription('Sangria/retirada de dinheiro (ou outro valor) do turno em andamento.')
            ->modalSubmitActionLabel('Registrar saída')
            ->schema([
                Select::make('forma_pagamento')
                    ->label('Forma de pagamento')
                    ->options(FormaPagamento::class)
                    ->default(FormaPagamento::Dinheiro)
                    ->required(),
                Select::make('motivo')
                    ->label('Motivo')
                    ->options([
                        MotivoSaidaCaixa::Sangria->value => MotivoSaidaCaixa::Sangria->getLabel(),
                        MotivoSaidaCaixa::PagamentoDespesa->value => MotivoSaidaCaixa::PagamentoDespesa->getLabel(),
                        MotivoSaidaCaixa::Outros->value => MotivoSaidaCaixa::Outros->getLabel(),
                    ])
                    ->default(MotivoSaidaCaixa::Sangria->value)
                    ->required(),
                Money::make('valor')
                    ->label('Valor')
                    ->minValue(0.01)
                    ->required(),
                TextInput::make('descricao')
                    ->label('Descrição')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Textarea::make('observacoes')
                    ->label('Observações')
                    ->rows(2)
                    ->columnSpanFull(),
            ])
            ->action(function (SessaoCaixa $record, array $data, MovimentacaoCaixaService $service, Action $action): void {
                try {
                    $service->registrarSaida(
                        sessao: $record,
                        valor: self::normalizeMoney($data['valor'] ?? null),
                        // Select::options(FormaPagamento::class) já entrega o state como
                        // instância do enum — nada de ::from() aqui.
                        formaPagamento: $data['forma_pagamento'],
                        motivo: MotivoSaidaCaixa::from($data['motivo']),
                        descricao: $data['descricao'],
                        observacoes: $data['observacoes'] ?? null,
                    );
                } catch (ValidationException $e) {
                    Notification::make()
                        ->title('Não foi possível registrar a saída')
                        ->body(collect($e->errors())->flatten()->first())
                        ->danger()
                        ->send();

                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title('Saída registrada com sucesso!')
                    ->success()
                    ->send();
            });
    }

    /**
     * Money::make() mantém o valor em estado bruto formatado (ex: "1.234,56")
     * enquanto o form não é salvo — precisa normalizar pro padrão decimal antes
     * de repassar ao Service.
     */
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
