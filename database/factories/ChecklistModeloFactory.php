<?php

namespace Database\Factories;

use App\Enums\Checklist\ChecklistSetor;
use App\Enums\Checklist\ChecklistTipo;
use App\Models\ChecklistModelo;
use App\Models\ChecklistModeloItem;
use App\Models\ChecklistModeloSecao;
use Illuminate\Database\Eloquent\Factories\Factory;
use Spatie\Permission\Models\Role;

/**
 * @extends Factory<ChecklistModelo>
 */
class ChecklistModeloFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nome' => 'Checklist '.fake()->unique()->words(2, true),
            'descricao' => null,
            'tipo' => ChecklistTipo::Abertura,
            'setor' => ChecklistSetor::Cozinha,
            'dias_semana' => [1, 2, 3, 4, 5, 6, 7],
            'janela_inicio' => '16:00:00',
            'janela_fim' => '18:30:00',
            'tolerancia_minutos' => 30,
            'exige_aprovacao' => false,
            'permite_multiplas_execucoes_dia' => false,
            'versao' => 1,
            'ativo' => true,
        ];
    }

    public function tipo(ChecklistTipo $tipo): static
    {
        return $this->state(['tipo' => $tipo]);
    }

    public function setor(ChecklistSetor $setor): static
    {
        return $this->state(['setor' => $setor]);
    }

    public function inativo(): static
    {
        return $this->state(['ativo' => false]);
    }

    /** Sem dias da semana nem janela: executado sob demanda. */
    public function sobDemanda(): static
    {
        return $this->state([
            'dias_semana' => null,
            'janela_inicio' => null,
            'janela_fim' => null,
        ]);
    }

    /** @param  array<int, int>  $dias  dias ISO (1=seg … 7=dom) */
    public function dias(array $dias): static
    {
        return $this->state(['dias_semana' => $dias]);
    }

    public function janela(?string $inicio, ?string $fim): static
    {
        return $this->state(['janela_inicio' => $inicio, 'janela_fim' => $fim]);
    }

    public function exigeAprovacao(): static
    {
        return $this->state(['exige_aprovacao' => true]);
    }

    public function multiplasPorDia(): static
    {
        return $this->state(['permite_multiplas_execucoes_dia' => true]);
    }

    /** Uma seção com a quantidade informada de itens "check". */
    public function comItens(int $quantidade = 3): static
    {
        return $this->afterCreating(function (ChecklistModelo $modelo) use ($quantidade): void {
            $secao = ChecklistModeloSecao::factory()->for($modelo, 'modelo')->create();

            ChecklistModeloItem::factory()
                ->count($quantidade)
                ->for($modelo, 'modelo')
                ->for($secao, 'secao')
                ->sequence(fn ($sequence) => ['ordem' => $sequence->index + 1])
                ->create();
        });
    }

    /** @param  array<int, string>  $nomes  nomes das roles (criadas se não existirem) */
    public function paraPapeis(array $nomes): static
    {
        return $this->afterCreating(function (ChecklistModelo $modelo) use ($nomes): void {
            foreach ($nomes as $nome) {
                $role = Role::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
                $modelo->papeis()->create(['role_id' => $role->id]);
            }
        });
    }
}
