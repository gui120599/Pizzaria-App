<?php

namespace App\Filament\Resources\SessoesCaixa\Support;

use App\Enums\FormaPagamento;
use App\Enums\MotivoSaidaCaixa;
use App\Exceptions\FinanceiroException;
use App\Models\Lancamento;
use App\Models\PlanoDespesa;
use App\Models\Prestador;
use App\Models\SessaoCaixa;
use App\Services\Financeiro\DespesaCaixaService;
use App\Services\MovimentacaoCaixaService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Validation\ValidationException;
use Leandrocfe\FilamentPtbrFormFields\Money;

/**
 * Registra uma saída (sangria) de caixa numa sessão ABERTA — traz pro Filament
 * o que hoje só existe no fluxo legado Blade (MovimentacoesSessaoCaixaController,
 * rotas /Saidas*), com motivo estruturado e recompute correto do saldo (ver
 * App\Services\MovimentacaoCaixaService).
 *
 * "Pagamento de despesa" exige um título a pagar (em aberto ou criado na
 * hora) e a retirada sai da baixa dele pelo Caixa PDV (DespesaCaixaService),
 * para a despesa existir uma vez só no financeiro.
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
                Select::make('motivo')
                    ->label('Motivo')
                    ->options([
                        MotivoSaidaCaixa::Sangria->value => MotivoSaidaCaixa::Sangria->getLabel(),
                        MotivoSaidaCaixa::PagamentoDespesa->value => MotivoSaidaCaixa::PagamentoDespesa->getLabel(),
                        MotivoSaidaCaixa::Outros->value => MotivoSaidaCaixa::Outros->getLabel(),
                    ])
                    ->default(MotivoSaidaCaixa::Sangria->value)
                    ->live()
                    ->required(),
                // Despesa sai sempre em dinheiro da gaveta.
                Select::make('forma_pagamento')
                    ->label('Forma de pagamento')
                    ->options(FormaPagamento::class)
                    ->default(FormaPagamento::Dinheiro)
                    ->visible(fn (Get $get): bool => ! self::ehDespesa($get))
                    ->required(),
                ToggleButtons::make('titulo_origem')
                    ->label('Título a pagar')
                    ->options(['novo' => 'Criar agora', 'existente' => 'Título em aberto'])
                    ->default('novo')
                    ->grouped()
                    ->live()
                    ->visible(fn (Get $get): bool => self::ehDespesa($get))
                    ->required(fn (Get $get): bool => self::ehDespesa($get)),
                Select::make('lancamento_id')
                    ->label('Título em aberto')
                    ->options(fn (): array => Lancamento::query()->pagar()->pendentes()->orderBy('vencimento')->limit(300)->get()
                        ->mapWithKeys(fn (Lancamento $titulo): array => [
                            $titulo->id => "{$titulo->descricao} — R$ ".number_format($titulo->valorRestante, 2, ',', '.')." (vence {$titulo->vencimento->format('d/m/Y')})",
                        ])
                        ->all())
                    ->searchable()
                    ->native(false)
                    ->visible(fn (Get $get): bool => self::ehDespesa($get) && $get('titulo_origem') === 'existente')
                    ->required(fn (Get $get): bool => self::ehDespesa($get) && $get('titulo_origem') === 'existente'),
                Select::make('plano_despesa_id')
                    ->label('Conta (Plano de Despesas)')
                    ->options(fn (): array => PlanoDespesa::query()->orderBy('nome')->pluck('nome', 'id')->all())
                    ->searchable()
                    ->native(false)
                    ->visible(fn (Get $get): bool => self::ehDespesa($get) && $get('titulo_origem') === 'novo')
                    ->required(fn (Get $get): bool => self::ehDespesa($get) && $get('titulo_origem') === 'novo'),
                Select::make('favorecido_id')
                    ->label('Fornecedor / colaborador')
                    ->options(fn (): array => Prestador::favorecidos()->orderBy('nome')->get()
                        ->mapWithKeys(fn (Prestador $prestador): array => [$prestador->id => $prestador->rotuloFavorecido()])
                        ->all())
                    ->searchable()
                    ->native(false)
                    ->visible(fn (Get $get): bool => self::ehDespesa($get) && $get('titulo_origem') === 'novo'),
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
            ->action(function (SessaoCaixa $record, array $data, MovimentacaoCaixaService $service, DespesaCaixaService $despesas, Action $action): void {
                try {
                    if (MotivoSaidaCaixa::from($data['motivo']) === MotivoSaidaCaixa::PagamentoDespesa) {
                        $existente = ($data['titulo_origem'] ?? null) === 'existente';

                        $despesas->pagar(
                            sessao: $record,
                            valor: self::normalizeMoney($data['valor'] ?? null),
                            titulo: $existente ? Lancamento::query()->find($data['lancamento_id'] ?? null) : null,
                            novoTitulo: $existente ? null : [
                                'descricao' => $data['descricao'],
                                'plano_despesa_id' => (int) $data['plano_despesa_id'],
                                'favorecido_id' => filled($data['favorecido_id'] ?? null) ? (int) $data['favorecido_id'] : null,
                                'observacoes' => $data['observacoes'] ?? null,
                            ],
                            observacoes: $data['observacoes'] ?? null,
                        );

                        Notification::make()
                            ->title('Despesa paga pelo caixa')
                            ->body('O título foi baixado pelo Caixa PDV e a retirada entrou na sessão.')
                            ->success()
                            ->send();

                        return;
                    }

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
                } catch (ValidationException|FinanceiroException $e) {
                    Notification::make()
                        ->title('Não foi possível registrar a saída')
                        ->body($e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage())
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

    private static function ehDespesa(Get $get): bool
    {
        $motivo = $get('motivo');

        return ($motivo instanceof MotivoSaidaCaixa ? $motivo->value : $motivo) === MotivoSaidaCaixa::PagamentoDespesa->value;
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
