<?php

namespace App\Filament\Components;

use App\Models\ContaFinanceira;
use App\Models\SessaoCaixa;
use App\Services\Financeiro\CaixaPdvBridge;
use App\Services\Ponto\AdiantamentoService;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\Log;
use Livewire\Component as Livewire;

/**
 * "Conta de origem" / "Sai de": contas ativas com o saldo no rótulo
 * ("Cofre — R$ 1.250,00"). O Caixa PDV mostra o dinheiro da gaveta da
 * sessão aberta (o saldo dele vem das sessões, não das movimentações).
 * Use junto de sessaoCaixa() quando a conta puder ser o Caixa PDV.
 */
class ContaFinanceiraSelect extends Select
{
    protected ?string $campoForma = null;

    public static function getDefaultName(): ?string
    {
        return 'conta_financeira_id';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Conta de origem')
            ->options(fn (): array => self::opcoes())
            ->native(false)
            ->live()
            ->afterStateUpdated(function (Select $component, Livewire $livewire, Set $set, Get $get, mixed $state): void {
                // O erro "obrigatório" de um envio anterior não some sozinho ao escolher a conta.
                $livewire->resetErrorBag($component->getStatePath());
                Log::info('DIAG-CONTA afterStateUpdated', ['path' => $component->getStatePath(), 'state' => $state]); // DIAG-TEMP

                $conta = self::conta($state);

                if ($this->campoForma !== null && $conta !== null && blank($get($this->campoForma))) {
                    $set($this->campoForma, AdiantamentoService::formaSugerida($conta)->value);
                }

                if (! $conta?->ehCaixaPdv() && $this->getName() === self::getDefaultName()) {
                    $set('sessao_caixa_id', null);
                }
            });
    }

    /** Ao escolher a conta, preenche a forma de pagamento coerente se ela estiver vazia (só sugestão). */
    public function sugerirForma(string $campo = 'forma_pagamento'): static
    {
        $this->campoForma = $campo;

        return $this;
    }

    /**
     * Sessão de caixa de onde sai (ou para onde vai) o dinheiro, quando uma
     * das contas é o Caixa PDV e há mais de uma sessão aberta (com uma só,
     * ela é usada sozinha).
     */
    public static function sessaoCaixa(string ...$camposConta): Select
    {
        $camposConta = $camposConta === [] ? [self::getDefaultName()] : $camposConta;
        $usaCaixaPdv = fn (Get $get): bool => collect($camposConta)->contains(fn (string $campo): bool => self::conta($get($campo))?->ehCaixaPdv() === true)
            && app(CaixaPdvBridge::class)->sessoesAbertas()->count() > 1;

        return Select::make('sessao_caixa_id')
            ->label('Sessão de caixa')
            ->options(fn (): array => app(CaixaPdvBridge::class)->sessoesAbertas()->load('caixa')
                ->mapWithKeys(fn (SessaoCaixa $sessao): array => [
                    $sessao->id => "#{$sessao->id} — ".($sessao->caixa?->caixa_nome ?? 'Caixa')
                        .' — R$ '.self::moeda(app(CaixaPdvBridge::class)->dinheiroDisponivel($sessao)).' na gaveta',
                ])
                ->all())
            ->native(false)
            ->helperText('Há mais de uma sessão aberta: escolha de qual delas sai o dinheiro.')
            ->validationMessages(['required' => 'Escolha a sessão de caixa de onde sai o dinheiro.'])
            ->visible($usaCaixaPdv)
            ->required($usaCaixaPdv);
    }

    /** @return array<int, string> */
    public static function opcoes(): array
    {
        return ContaFinanceira::query()->ordenadas()->get()
            ->mapWithKeys(fn (ContaFinanceira $conta): array => [$conta->id => self::rotulo($conta)])
            ->all();
    }

    public static function rotulo(ContaFinanceira $conta): string
    {
        if (! $conta->ehCaixaPdv()) {
            return "{$conta->nome} — R$ ".self::moeda((float) $conta->saldo_atual);
        }

        $ponte = app(CaixaPdvBridge::class);
        $abertas = $ponte->sessoesAbertas();

        return match ($abertas->count()) {
            0 => "{$conta->nome} — sem sessão aberta",
            1 => "{$conta->nome} — R$ ".self::moeda($ponte->dinheiroDisponivel($abertas->first())).' na gaveta',
            default => "{$conta->nome} — {$abertas->count()} sessões abertas",
        };
    }

    public static function conta(mixed $estado): ?ContaFinanceira
    {
        return filled($estado) ? ContaFinanceira::query()->find($estado) : null;
    }

    private static function moeda(float $valor): string
    {
        return number_format($valor, 2, ',', '.');
    }
}
