<?php

namespace Database\Factories;

use App\Enums\Financeiro\NaturezaMovimentacao;
use App\Enums\Financeiro\TipoMovimentacao;
use App\Models\ContaFinanceira;
use App\Models\MovimentacaoFinanceira;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Só para testes: na aplicação, movimentação nasce pelo MovimentacaoService
 * (que também atualiza o saldo da conta).
 *
 * @extends Factory<MovimentacaoFinanceira>
 */
class MovimentacaoFinanceiraFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conta_financeira_id' => ContaFinanceira::factory(),
            'tipo' => TipoMovimentacao::Saida,
            'natureza' => NaturezaMovimentacao::PagamentoLancamento,
            'valor' => fake()->randomFloat(2, 10, 500),
            'data_movimento' => now()->toDateString(),
            'descricao' => fake()->sentence(3),
        ];
    }

    public function entrada(): static
    {
        return $this->state(['tipo' => TipoMovimentacao::Entrada, 'natureza' => NaturezaMovimentacao::RecebimentoLancamento]);
    }

    public function saida(): static
    {
        return $this->state(['tipo' => TipoMovimentacao::Saida]);
    }
}
