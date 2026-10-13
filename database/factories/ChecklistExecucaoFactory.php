<?php

namespace Database\Factories;

use App\Enums\Checklist\ExecucaoStatus;
use App\Models\ChecklistExecucao;
use App\Models\ChecklistModelo;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Execução "crua" (sem itens). Para uma execução com snapshot dos itens do
 * modelo, use o ChecklistExecucaoService.
 *
 * @extends Factory<ChecklistExecucao>
 */
class ChecklistExecucaoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'checklist_modelo_id' => ChecklistModelo::factory(),
            'modelo_versao' => fn (array $attributes) => ChecklistModelo::find($attributes['checklist_modelo_id'])->versao,
            'modelo_nome' => fn (array $attributes) => ChecklistModelo::find($attributes['checklist_modelo_id'])->nome,
            'tipo' => fn (array $attributes) => ChecklistModelo::find($attributes['checklist_modelo_id'])->tipo,
            'setor' => fn (array $attributes) => ChecklistModelo::find($attributes['checklist_modelo_id'])->setor,
            'data_referencia' => ChecklistExecucao::dataOperacional(),
            'status' => ExecucaoStatus::Pendente,
        ];
    }

    public function emAndamento(?User $por = null): static
    {
        return $this->state(fn () => [
            'status' => ExecucaoStatus::EmAndamento,
            'iniciado_por' => $por?->id ?? User::factory()->state(['name_first' => fake()->firstName()]),
            'iniciado_em' => now(),
        ]);
    }

    public function concluido(?User $por = null): static
    {
        return $this->state(fn () => [
            'status' => ExecucaoStatus::Concluido,
            'iniciado_por' => $por?->id ?? User::factory()->state(['name_first' => fake()->firstName()]),
            'iniciado_em' => now()->subHour(),
            'concluido_por' => $por?->id,
            'concluido_em' => now(),
            'percentual_conformidade' => 100,
        ]);
    }

    public function naoRealizado(): static
    {
        return $this->state(['status' => ExecucaoStatus::NaoRealizado]);
    }
}
