<?php

namespace Database\Factories;

use App\Models\ChecklistAnexo;
use App\Models\ChecklistExecucaoItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChecklistAnexo>
 */
class ChecklistAnexoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'checklist_execucao_item_id' => ChecklistExecucaoItem::factory(),
            'disco' => 'public',
            'caminho' => 'checklists/'.fake()->uuid().'.jpg',
            'tamanho_bytes' => fake()->numberBetween(50_000, 400_000),
            'enviado_por' => User::factory()->state(['name_first' => fake()->firstName()]),
        ];
    }
}
