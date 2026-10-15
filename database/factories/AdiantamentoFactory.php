<?php

namespace Database\Factories;

use App\Enums\Financeiro\TipoContaFinanceira;
use App\Enums\Ponto\NaturezaAdiantamento;
use App\Enums\Ponto\StatusAdiantamento;
use App\Models\Adiantamento;
use App\Models\Colaborador;
use App\Models\ContaFinanceira;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Só para testes: na aplicação, vale nasce pelo AdiantamentoService (título
 * pago + movimentação na conta).
 *
 * @extends Factory<Adiantamento>
 */
class AdiantamentoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'colaborador_id' => Colaborador::factory(),
            'data' => now()->toDateString(),
            'natureza' => NaturezaAdiantamento::Vale,
            'valor' => 100,
            'descricao' => 'Vale',
            'conta_financeira_id' => ContaFinanceira::factory()->state(['tipo' => TipoContaFinanceira::CaixaInterno]),
            'status' => StatusAdiantamento::Aberto,
        ];
    }

    public function consumo(float $valor = 30): static
    {
        return $this->state([
            'natureza' => NaturezaAdiantamento::Consumo,
            'valor' => $valor,
            'descricao' => 'Consumo interno',
            'conta_financeira_id' => null,
        ]);
    }
}
