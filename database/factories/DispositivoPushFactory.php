<?php

namespace Database\Factories;

use App\Models\DispositivoPush;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DispositivoPush>
 */
class DispositivoPushFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $endpoint = 'https://fcm.googleapis.com/fcm/send/'.fake()->unique()->sha256();

        return [
            'dp_user_id' => User::factory(),
            'dp_endpoint' => $endpoint,
            'dp_endpoint_hash' => DispositivoPush::hashDoEndpoint($endpoint),
            'dp_chave_p256dh' => fake()->regexify('[A-Za-z0-9_-]{87}'),
            'dp_chave_auth' => fake()->regexify('[A-Za-z0-9_-]{22}'),
            'dp_content_encoding' => 'aes128gcm',
            'dp_plataforma' => 'android',
            'dp_navegador' => 'Chrome',
            'dp_instalado' => false,
            'dp_ativo' => true,
        ];
    }

    public function inativo(string $motivo = 'usuario'): static
    {
        return $this->state(fn () => [
            'dp_ativo' => false,
            'dp_desativado_em' => now(),
            'dp_motivo_desativacao' => $motivo,
        ]);
    }

    public function pausado(): static
    {
        return $this->state(fn () => ['dp_pausado_ate' => now()->addHours(4)]);
    }
}
