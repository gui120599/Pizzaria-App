<?php

namespace Database\Factories;

use App\Enums\Ponto\NaturezaItem;
use App\Models\Repasse;
use App\Models\RepasseItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RepasseItem>
 */
class RepasseItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'repasse_id' => Repasse::factory(),
            'natureza' => NaturezaItem::Bonificacao,
            'tipo' => NaturezaItem::Bonificacao->tipo(),
            'descricao' => 'Bonificação',
            'valor' => 50,
            'automatico' => false,
        ];
    }

    public function natureza(NaturezaItem $natureza, float $valor): static
    {
        return $this->state([
            'natureza' => $natureza,
            'tipo' => $natureza->tipo(),
            'descricao' => $natureza->getLabel(),
            'valor' => $valor,
        ]);
    }
}
