<?php

namespace Database\Factories;

use App\Models\ChecklistModelo;
use App\Models\ChecklistModeloSecao;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChecklistModeloSecao>
 */
class ChecklistModeloSecaoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'checklist_modelo_id' => ChecklistModelo::factory(),
            'titulo' => fake()->randomElement(['Equipamentos', 'Pré-preparo', 'Higiene', 'Salão']),
            'ordem' => 1,
        ];
    }
}
