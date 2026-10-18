<?php

namespace Database\Factories;

use App\Enums\Checklist\ExigenciaFoto;
use App\Enums\Checklist\TipoResposta;
use App\Models\ChecklistModelo;
use App\Models\ChecklistModeloItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChecklistModeloItem>
 */
class ChecklistModeloItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'checklist_modelo_id' => ChecklistModelo::factory(),
            'checklist_modelo_secao_id' => null,
            'titulo' => fake()->sentence(3),
            'instrucao' => null,
            'tipo_resposta' => TipoResposta::Check,
            'opcoes' => null,
            'resposta_esperada' => null,
            'valor_minimo' => null,
            'valor_maximo' => null,
            'unidade' => null,
            'obrigatorio' => true,
            'critico' => false,
            'exige_foto' => ExigenciaFoto::Nunca,
            'exige_observacao_nao_conforme' => true,
            'ordem' => 1,
        ];
    }

    public function simNao(string $esperada = 'sim'): static
    {
        return $this->state([
            'tipo_resposta' => TipoResposta::SimNao,
            'resposta_esperada' => $esperada,
        ]);
    }

    public function numero(?float $minimo, ?float $maximo, ?string $unidade = 'un'): static
    {
        return $this->state([
            'tipo_resposta' => TipoResposta::Numero,
            'valor_minimo' => $minimo,
            'valor_maximo' => $maximo,
            'unidade' => $unidade,
        ]);
    }

    public function temperatura(?float $minimo, ?float $maximo): static
    {
        return $this->state([
            'tipo_resposta' => TipoResposta::Temperatura,
            'valor_minimo' => $minimo,
            'valor_maximo' => $maximo,
            'unidade' => '°C',
        ]);
    }

    public function texto(): static
    {
        return $this->state(['tipo_resposta' => TipoResposta::Texto]);
    }

    /** @param  array<int, array{valor: string, rotulo: string, conforme: bool}>|null  $opcoes */
    public function selecao(?array $opcoes = null): static
    {
        return $this->state([
            'tipo_resposta' => TipoResposta::Selecao,
            'opcoes' => $opcoes ?? [
                ['valor' => 'ok', 'rotulo' => 'OK', 'conforme' => true],
                ['valor' => 'bateria_baixa', 'rotulo' => 'Bateria baixa', 'conforme' => false],
            ],
        ]);
    }

    public function foto(): static
    {
        return $this->state(['tipo_resposta' => TipoResposta::Foto]);
    }

    public function producao(?string $criterio = null, bool $exigeFotoEstoque = false): static
    {
        return $this->state([
            'tipo_resposta' => TipoResposta::Producao,
            'criterio' => $criterio,
            'exige_foto_estoque' => $exigeFotoEstoque,
        ]);
    }

    public function critico(): static
    {
        return $this->state(['critico' => true]);
    }

    public function opcional(): static
    {
        return $this->state(['obrigatorio' => false]);
    }

    public function exigeFoto(ExigenciaFoto $exigencia = ExigenciaFoto::Sempre): static
    {
        return $this->state(['exige_foto' => $exigencia]);
    }
}
