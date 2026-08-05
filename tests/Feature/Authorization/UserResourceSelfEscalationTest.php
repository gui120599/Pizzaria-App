<?php

namespace Tests\Feature\Authorization;

use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UserResourceSelfEscalationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_nao_ve_acao_de_excluir_a_propria_conta(): void
    {
        $admin = User::factory()->create(['name_first' => 'Admin']);
        $admin->assignRole('Admin');

        $this->actingAs($admin);

        Livewire::test(ManageUsers::class)
            ->assertTableActionHidden('delete', $admin)
            ->assertTableActionHidden('forceDelete', $admin);
    }

    public function test_admin_ve_acao_de_excluir_a_conta_de_outro_usuario(): void
    {
        $admin = User::factory()->create(['name_first' => 'Admin']);
        $admin->assignRole('Admin');
        $outro = User::factory()->create(['name_first' => 'Outro']);
        $outro->assignRole('Atendente');

        $this->actingAs($admin);

        Livewire::test(ManageUsers::class)
            ->assertTableActionVisible('delete', $outro);
    }

    public function test_campo_de_papeis_fica_desabilitado_ao_editar_a_propria_conta(): void
    {
        $admin = User::factory()->create(['name_first' => 'Admin']);
        $admin->assignRole('Admin');

        $this->actingAs($admin);

        Livewire::test(ManageUsers::class)
            ->mountTableAction('edit', $admin)
            ->assertFormFieldDisabled('roles');
    }
}
