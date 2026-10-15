<?php

namespace Database\Factories;

use App\Enums\Ponto\OrigemMarcacao;
use App\Models\Colaborador;
use App\Models\PontoMarcacao;
use App\Support\DiaOperacional;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * Só para testes de apuração/espelho: não passa pela cadeia global de NSR e
 * hash do PontoService (cada marcação fabricada tem hash próprio, sem
 * hash_anterior). Para testar a cadeia, use o PontoService.
 *
 * @extends Factory<PontoMarcacao>
 */
class PontoMarcacaoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $registradaEm = now()->startOfMinute();

        return [
            'nsr' => fake()->unique()->numberBetween(1_000_000, 9_999_999),
            'colaborador_id' => Colaborador::factory(),
            'registrada_em' => $registradaEm,
            'data_referencia' => DiaOperacional::de($registradaEm)->toDateString(),
            'origem' => OrigemMarcacao::App,
            'hash_anterior' => null,
            'hash' => str_repeat('0', 64),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (PontoMarcacao $marcacao): void {
            $marcacao->hash = PontoMarcacao::calcularHash($marcacao);
        });
    }

    /** Marcação no instante informado (dia operacional derivado dele). */
    public function em(string $dataHora): static
    {
        $instante = Carbon::parse($dataHora);

        return $this->state([
            'registrada_em' => $instante,
            'data_referencia' => DiaOperacional::de($instante)->toDateString(),
        ]);
    }

    public function origem(OrigemMarcacao $origem): static
    {
        return $this->state(['origem' => $origem]);
    }
}
