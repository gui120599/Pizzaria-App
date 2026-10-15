<?php

namespace Database\Factories;

use App\Enums\Financeiro\TipoContaFinanceira;
use App\Models\ContaFinanceira;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContaFinanceira>
 */
class ContaFinanceiraFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nome' => 'Conta '.fake()->unique()->words(2, true),
            'tipo' => TipoContaFinanceira::Banco,
            'saldo_inicial' => 0,
            'data_saldo_inicial' => now()->startOfMonth()->toDateString(),
            'saldo_atual' => 0,
            'permite_saldo_negativo' => false,
            'ordem' => 0,
        ];
    }

    public function caixaPdv(): static
    {
        return $this->state(['nome' => 'Caixa PDV', 'tipo' => TipoContaFinanceira::CaixaPdv]);
    }

    public function cofre(): static
    {
        return $this->state(['nome' => 'Cofre', 'tipo' => TipoContaFinanceira::CaixaInterno]);
    }

    public function banco(): static
    {
        return $this->state(['tipo' => TipoContaFinanceira::Banco, 'banco' => 'Banco do Brasil']);
    }

    public function comSaldo(float $saldo): static
    {
        return $this->state(['saldo_inicial' => $saldo, 'saldo_atual' => $saldo]);
    }

    public function permiteNegativo(): static
    {
        return $this->state(['permite_saldo_negativo' => true]);
    }
}
