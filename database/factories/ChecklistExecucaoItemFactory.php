<?php

namespace Database\Factories;

use App\Enums\Checklist\ExigenciaFoto;
use App\Enums\Checklist\TipoResposta;
use App\Models\ChecklistExecucao;
use App\Models\ChecklistExecucaoItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChecklistExecucaoItem>
 */
class ChecklistExecucaoItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'checklist_execucao_id' => ChecklistExecucao::factory(),
            'checklist_modelo_item_id' => null,
            'secao_titulo' => 'Equipamentos',
            'titulo' => fake()->sentence(3),
            'tipo_resposta' => TipoResposta::Check,
            'config' => [
                'opcoes' => null,
                'resposta_esperada' => null,
                'valor_minimo' => null,
                'valor_maximo' => null,
                'unidade' => null,
                'obrigatorio' => true,
                'critico' => false,
                'exige_foto' => ExigenciaFoto::Nunca->value,
                'exige_observacao_nao_conforme' => true,
            ],
            'ordem' => 1,
        ];
    }

    /**
     * Item já respondido (o `conforme` informado é gravado como está, sem avaliar).
     *
     * @param  array<string, mixed>  $valores
     */
    public function respondido(array $valores = ['valor_booleano' => true], ?bool $conforme = true, ?User $por = null): static
    {
        return $this->state(fn () => [
            ...$valores,
            'conforme' => $conforme,
            'respondido_por' => $por?->id ?? User::factory()->state(['name_first' => fake()->firstName()]),
            'respondido_em' => now(),
        ]);
    }
}
