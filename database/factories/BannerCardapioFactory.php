<?php

namespace Database\Factories;

use App\Enums\AcaoBannerEnum;
use App\Models\BannerCardapio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BannerCardapio>
 */
class BannerCardapioFactory extends Factory
{
    /**
     * Banner no ar agora, sem janela (fica até alguém desligar) e sem ação.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'banner_titulo' => fake()->sentence(3),
            'banner_imagem' => 'banners_cardapio/'.fake()->uuid().'.jpg',
            'banner_ativo' => true,
            'banner_acao' => AcaoBannerEnum::NENHUMA,
            'banner_exibir_delivery' => true,
            'banner_exibir_mesa' => true,
            'banner_recorrente' => false,
        ];
    }

    /** Recorrente em dias da semana (0 = domingo) e, opcionalmente, num horário. */
    public function recorrente(array $dias, ?string $horaInicio = null, ?string $horaFim = null): static
    {
        return $this->state([
            'banner_recorrente' => true,
            'banner_dias_semana' => $dias,
            'banner_hora_inicio' => $horaInicio,
            'banner_hora_fim' => $horaFim,
        ]);
    }
}
