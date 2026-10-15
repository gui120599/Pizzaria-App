<?php

namespace Database\Factories;

use App\Enums\Ponto\ModalidadeRemuneracao;
use App\Models\Colaborador;
use App\Models\ColaboradorRemuneracao;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ColaboradorRemuneracao>
 */
class ColaboradorRemuneracaoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'colaborador_id' => Colaborador::factory(),
            'modalidade' => ModalidadeRemuneracao::PorDiaria,
            'valor_base' => 120,
            'valor_por_entrega' => 0,
            'carga_diaria_minutos' => 420,
            'carga_semanal_minutos' => null,
            'percentual_extra_util' => 50,
            'percentual_extra_domingo_feriado' => 100,
            'percentual_adicional_noturno' => 20,
            'considera_hora_noturna_reduzida' => true,
            'vigente_desde' => now()->subYear()->toDateString(),
            'vigente_ate' => null,
        ];
    }

    public function mensal(float $salario = 2000, int $cargaSemanalMinutos = 2640): static
    {
        return $this->state([
            'modalidade' => ModalidadeRemuneracao::Mensal,
            'valor_base' => $salario,
            'carga_diaria_minutos' => 480,
            'carga_semanal_minutos' => $cargaSemanalMinutos,
        ]);
    }

    public function porHora(float $valorHora = 15): static
    {
        return $this->state(['modalidade' => ModalidadeRemuneracao::PorHora, 'valor_base' => $valorHora]);
    }

    public function porDiaria(float $diaria = 120): static
    {
        return $this->state(['modalidade' => ModalidadeRemuneracao::PorDiaria, 'valor_base' => $diaria]);
    }

    public function somenteEntregas(float $porEntrega = 6): static
    {
        return $this->state([
            'modalidade' => ModalidadeRemuneracao::SomenteEntregas,
            'valor_base' => 0,
            'valor_por_entrega' => $porEntrega,
        ]);
    }

    public function vigencia(string $desde, ?string $ate = null): static
    {
        return $this->state(['vigente_desde' => $desde, 'vigente_ate' => $ate]);
    }
}
