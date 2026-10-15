<?php

namespace Database\Factories;

use App\Enums\Ponto\PeriodicidadeRepasse;
use App\Enums\Ponto\StatusPeriodo;
use App\Models\PontoPeriodo;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<PontoPeriodo>
 */
class PontoPeriodoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $inicio = now()->startOfWeek();

        return [
            'periodicidade' => PeriodicidadeRepasse::Semanal,
            'data_inicio' => $inicio->toDateString(),
            'data_fim' => $inicio->copy()->endOfWeek()->toDateString(),
            'data_pagamento' => $inicio->copy()->endOfWeek()->addDays(5)->toDateString(),
            'status' => StatusPeriodo::Aberto,
        ];
    }

    public function entre(string $inicio, string $fim, PeriodicidadeRepasse $periodicidade = PeriodicidadeRepasse::Semanal): static
    {
        return $this->state([
            'periodicidade' => $periodicidade,
            'data_inicio' => $inicio,
            'data_fim' => $fim,
            'data_pagamento' => Carbon::parse($fim)->addDays(5)->toDateString(),
        ]);
    }

    public function status(StatusPeriodo $status): static
    {
        return $this->state(['status' => $status]);
    }
}
