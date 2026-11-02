<?php

namespace Database\Factories;

use App\Enums\EventoPushGarcomEnum;
use App\Models\DispositivoPush;
use App\Models\PushEnvio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PushEnvio>
 */
class PushEnvioFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pe_chave' => 'teste:'.fake()->uuid(),
            'pe_evento' => EventoPushGarcomEnum::TESTE,
            'pe_dispositivo_id' => DispositivoPush::factory(),
            'pe_status' => PushEnvio::PENDENTE,
            'pe_tentativas' => 0,
            'pe_payload' => ['titulo' => 'Teste', 'corpo' => 'Teste', 'url' => '/garcom'],
            'pe_expira_em' => now()->addMinutes(5),
        ];
    }
}
