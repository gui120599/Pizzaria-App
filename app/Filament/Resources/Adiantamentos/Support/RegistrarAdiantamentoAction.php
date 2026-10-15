<?php

namespace App\Filament\Resources\Adiantamentos\Support;

use App\Enums\FormaPagamento;
use App\Enums\Ponto\NaturezaAdiantamento;
use App\Enums\Ponto\StatusAdiantamento;
use App\Exceptions\FinanceiroException;
use App\Exceptions\PontoException;
use App\Filament\Components\ContaFinanceiraSelect;
use App\Filament\Resources\ContasFinanceiras\Support\ValorMonetario;
use App\Models\Adiantamento;
use App\Models\Colaborador;
use App\Models\ContaFinanceira;
use App\Models\SessaoCaixa;
use App\Services\Ponto\AdiantamentoService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Leandrocfe\FilamentPtbrFormFields\Money;

/**
 * Lançamento rápido de vale/consumo. Vale e "outro" exigem "Sai de": viram
 * título já pago e saem da conta (Caixa PDV: retirada na sessão aberta).
 */
class RegistrarAdiantamentoAction
{
    /** @param  (callable(): ?Colaborador)|null  $colaboradorFixo  quando chamado de dentro do cadastro do colaborador */
    public static function make(string $name = 'registrarAdiantamento', ?callable $colaboradorFixo = null): Action
    {
        return Action::make($name)
            ->label('Registrar vale')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('primary')
            ->visible(fn (): bool => auth()->user()?->can('create:adiantamento') ?? false)
            ->modalHeading('Registrar adiantamento')
            ->modalSubmitActionLabel('Registrar')
            ->schema([
                Select::make('colaborador_id')
                    ->label('Colaborador')
                    ->options(fn (): array => Colaborador::query()->ativos()->orderBy('nome')->pluck('nome', 'id')->all())
                    ->searchable()
                    ->required()
                    ->visible($colaboradorFixo === null),
                Select::make('natureza')
                    ->label('Natureza')
                    ->options(collect(NaturezaAdiantamento::cases())
                        ->reject(fn (NaturezaAdiantamento $natureza): bool => $natureza === NaturezaAdiantamento::SaldoDevedor)
                        ->mapWithKeys(fn (NaturezaAdiantamento $natureza): array => [$natureza->value => $natureza->getLabel()])
                        ->all())
                    ->default(NaturezaAdiantamento::Vale->value)
                    ->live()
                    ->required(),
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
                ContaFinanceiraSelect::make()
                    ->label('Sai de')
                    ->sugerirForma()
                    ->visible(fn (Get $get): bool => self::natureza($get)?->saiDinheiro() ?? false)
                    ->required(fn (Get $get): bool => self::natureza($get)?->saiDinheiro() ?? false),
                ContaFinanceiraSelect::sessaoCaixa(),
                Select::make('forma_pagamento')
                    ->label('Forma')
                    ->options(FormaPagamento::class)
                    ->visible(fn (Get $get): bool => self::natureza($get)?->saiDinheiro() ?? false),
                TextInput::make('descricao')
                    ->label('Descrição')
                    ->maxLength(255),
            ])
            ->action(function (array $data, AdiantamentoService $adiantamentos, Action $action) use ($colaboradorFixo): void {
                $colaborador = $colaboradorFixo !== null ? $colaboradorFixo() : Colaborador::query()->find($data['colaborador_id'] ?? null);
                $natureza = NaturezaAdiantamento::from($data['natureza'] instanceof NaturezaAdiantamento ? $data['natureza']->value : $data['natureza']);
                $forma = $data['forma_pagamento'] ?? null;

                try {
                    $adiantamentos->registrar(
                        colaborador: $colaborador,
                        natureza: $natureza,
                        valor: ValorMonetario::normalizar($data['valor'] ?? null),
                        data: Carbon::parse($data['data']),
                        descricao: $data['descricao'] ?? null,
                        usuario: auth()->user(),
                        conta: $natureza->saiDinheiro() ? ContaFinanceira::query()->find($data['conta_financeira_id'] ?? null) : null,
                        sessao: filled($data['sessao_caixa_id'] ?? null) ? SessaoCaixa::query()->find($data['sessao_caixa_id']) : null,
                        forma: $forma instanceof FormaPagamento ? $forma : FormaPagamento::tryFrom((string) $forma),
                    );
                } catch (PontoException|FinanceiroException $e) {
                    Notification::make()->title('Adiantamento não registrado')->body($e->getMessage())->danger()->send();
                    $action->halt();
                }

                Notification::make()->title('Adiantamento registrado')->body('Será descontado no próximo repasse.')->success()->send();
            });
    }

    public static function cancelar(string $name = 'cancelarAdiantamento'): Action
    {
        return Action::make($name)
            ->label('Cancelar')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Adiantamento $record): bool => $record->status === StatusAdiantamento::Aberto
                && (auth()->user()?->can('cancelar:adiantamento') ?? false))
            ->modalHeading('Cancelar adiantamento')
            ->modalDescription('Se saiu dinheiro, a baixa é estornada e o valor volta para a conta de origem.')
            ->schema([
                TextInput::make('motivo')
                    ->label('Motivo')
                    ->required()
                    ->minLength(10)
                    ->maxLength(255),
            ])
            ->action(function (Adiantamento $record, array $data, AdiantamentoService $adiantamentos, Action $action): void {
                try {
                    $adiantamentos->cancelar($record, auth()->user(), $data['motivo']);
                } catch (PontoException|FinanceiroException $e) {
                    Notification::make()->title('Adiantamento não cancelado')->body($e->getMessage())->danger()->send();
                    $action->halt();
                }

                Notification::make()->title('Adiantamento cancelado')->success()->send();
            });
    }

    private static function natureza(Get $get): ?NaturezaAdiantamento
    {
        $natureza = $get('natureza');

        return $natureza instanceof NaturezaAdiantamento ? $natureza : NaturezaAdiantamento::tryFrom((string) $natureza);
    }
}
