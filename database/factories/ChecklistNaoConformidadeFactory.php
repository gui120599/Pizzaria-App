<?php

namespace Database\Factories;

use App\Enums\Checklist\NaoConformidadeStatus;
use App\Models\ChecklistExecucaoItem;
use App\Models\ChecklistNaoConformidade;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChecklistNaoConformidade>
 */
class ChecklistNaoConformidadeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'checklist_execucao_item_id' => ChecklistExecucaoItem::factory()->respondido(['valor_booleano' => false], false),
            'descricao' => fake()->sentence(),
            'status' => NaoConformidadeStatus::Aberta,
            'critica' => false,
        ];
    }

    public function critica(): static
    {
        return $this->state(['critica' => true]);
    }

    public function vencida(): static
    {
        return $this->state(['prazo' => today()->subDays(2)]);
    }

    public function resolvida(): static
    {
        return $this->state([
            'status' => NaoConformidadeStatus::Resolvida,
            'resolvida_em' => now(),
        ]);
    }
}
