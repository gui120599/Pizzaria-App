<?php

namespace Tests\Feature;

use App\Filament\Resources\PlanoDespesas\Pages\CreatePlanoDespesa;
use App\Filament\Resources\PlanoDespesas\Pages\EditPlanoDespesa;
use App\Filament\Resources\PlanoDespesas\Pages\ListPlanoDespesas;
use App\Filament\Resources\PlanoReceitas\Pages\CreatePlanoReceita;
use App\Filament\Resources\PlanoReceitas\Pages\EditPlanoReceita;
use App\Filament\Resources\PlanoReceitas\Pages\ListPlanoReceitas;
use App\Models\PlanoDespesa;
use App\Models\PlanoReceita;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PlanoContasSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    public function test_paginas_de_plano_de_despesas_montam(): void
    {
        Livewire::test(ListPlanoDespesas::class)->assertOk();
        Livewire::test(CreatePlanoDespesa::class)->assertOk();
    }

    public function test_paginas_de_plano_de_receitas_montam(): void
    {
        Livewire::test(ListPlanoReceitas::class)->assertOk();
        Livewire::test(CreatePlanoReceita::class)->assertOk();
    }

    public function test_soft_delete_de_conta_de_despesa_esconde_da_listagem_padrao_e_permite_restaurar(): void
    {
        $conta = PlanoDespesa::create([
            'nome' => 'Conta temporária',
            'comportamento' => \App\Enums\Comportamento::Fixo,
            'periodicidade' => \App\Enums\Periodicidade::Mensal,
        ]);

        $conta->delete();

        $this->assertSoftDeleted($conta);
        $this->assertNull(PlanoDespesa::find($conta->id));               // escopo global esconde
        $this->assertNotNull(PlanoDespesa::withTrashed()->find($conta->id));

        // A página de edição ainda abre o registro apagado (Resource remove o SoftDeletingScope).
        Livewire::test(EditPlanoDespesa::class, ['record' => $conta->getKey()])->assertOk();

        $conta->restore();
        $this->assertNotNull(PlanoDespesa::find($conta->id));
    }

    public function test_soft_delete_de_conta_de_receita(): void
    {
        $conta = PlanoReceita::create(['nome' => 'Receita temporária']);
        $conta->delete();

        $this->assertSoftDeleted($conta);
        $this->assertNull(PlanoReceita::find($conta->id));

        Livewire::test(EditPlanoReceita::class, ['record' => $conta->getKey()])->assertOk();
    }
}
