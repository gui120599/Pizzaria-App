<?php

namespace Database\Factories;

use App\Enums\Ponto\PeriodicidadeRepasse;
use App\Enums\Ponto\VinculoColaborador;
use App\Enums\TipoChavePix;
use App\Models\Colaborador;
use App\Models\ColaboradorEscala;
use App\Models\ColaboradorRemuneracao;
use App\Models\Escala;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Colaborador>
 */
class ColaboradorFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nome' => fake()->name(),
            'cpf' => fake()->unique()->numerify('###########'),
            'cargo' => 'Atendente',
            'setor' => 'Salão',
            'vinculo' => VinculoColaborador::Diarista,
            'periodicidade_repasse' => PeriodicidadeRepasse::Semanal,
            'data_admissao' => now()->subYear()->toDateString(),
            'data_desligamento' => null,
            'tipo_chave_pix' => TipoChavePix::Email,
            'chave_pix' => fake()->unique()->safeEmail(),
            'entregador' => false,
        ];
    }

    public function vinculo(VinculoColaborador $vinculo): static
    {
        return $this->state(['vinculo' => $vinculo]);
    }

    public function clt(): static
    {
        return $this->state([
            'vinculo' => VinculoColaborador::Clt,
            'periodicidade_repasse' => PeriodicidadeRepasse::Mensal,
        ]);
    }

    public function entregador(): static
    {
        return $this->state(['vinculo' => VinculoColaborador::Freelancer, 'cargo' => 'Entregador', 'entregador' => true]);
    }

    public function desligado(?string $data = null): static
    {
        return $this->state(['data_desligamento' => $data ?? now()->subDay()->toDateString()]);
    }

    public function comPin(string $pin = '1234'): static
    {
        return $this->state(['pin_quiosque' => $pin]);
    }

    /** Cria o login vinculado (o UserFactory não preenche name_first, que é NOT NULL). */
    public function comUsuario(): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => User::factory()->state(['name_first' => $attributes['nome'] ?? 'Colaborador']),
        ]);
    }

    /** Remuneração vigente desde a admissão. */
    public function comRemuneracao(array $atributos = []): static
    {
        return $this->afterCreating(function (Colaborador $colaborador) use ($atributos): void {
            ColaboradorRemuneracao::factory()
                ->for($colaborador)
                ->create(['vigente_desde' => $colaborador->data_admissao, ...$atributos]);
        });
    }

    /** Escala vigente desde a admissão (padrão: "Noite pizzaria"). */
    public function comEscala(?Escala $escala = null): static
    {
        return $this->afterCreating(function (Colaborador $colaborador) use ($escala): void {
            ColaboradorEscala::factory()
                ->for($colaborador)
                ->for($escala ?? Escala::factory()->noitePizzaria()->create())
                ->create(['vigente_desde' => $colaborador->data_admissao]);
        });
    }
}
