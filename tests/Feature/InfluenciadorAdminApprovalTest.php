<?php

namespace Tests\Feature;

use App\Enums\StatusInfluenciadorEnum;
use App\Filament\Resources\Influenciadores\Pages\ManageInfluenciadores;
use App\Models\Empresa;
use App\Models\Influenciador;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cobre a aprovação de influenciadores pelo painel /admin — o autocadastro
 * público (/influenciadores/register) sempre nasce pendente e precisa desse
 * fluxo (ou de forceFill manual) pra virar ativo (ver InfluenciadorPolicy).
 */
class InfluenciadorAdminApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function influenciadorPendente(): Influenciador
    {
        $influenciador = Influenciador::create([
            'influenciador_nome' => 'Fulano',
            'email' => uniqid().'@teste.com',
            'password' => 'senha-segura',
            'influenciador_valor_comissao' => 5,
        ]);

        $influenciador->forceFill(['influenciador_status' => StatusInfluenciadorEnum::PENDENTE])->save();

        return $influenciador;
    }

    public function test_admin_aprova_influenciador_pendente(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        $influenciador = $this->influenciadorPendente();

        Livewire::test(ManageInfluenciadores::class)
            ->callTableAction('aprovar', $influenciador)
            ->assertHasNoTableActionErrors();

        $this->assertSame(StatusInfluenciadorEnum::ATIVO, $influenciador->fresh()->influenciador_status);
    }

    public function test_admin_inativa_influenciador(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        $influenciador = $this->influenciadorPendente();
        $influenciador->forceFill(['influenciador_status' => StatusInfluenciadorEnum::ATIVO])->save();

        Livewire::test(ManageInfluenciadores::class)
            ->callTableAction('inativar', $influenciador)
            ->assertHasNoTableActionErrors();

        $this->assertSame(StatusInfluenciadorEnum::INATIVO, $influenciador->fresh()->influenciador_status);
    }

    public function test_gerente_tambem_pode_acessar_a_listagem(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->actingAs(User::factory()->gerente()->create(['name_first' => 'Gerente']));

        $this->influenciadorPendente();

        Livewire::test(ManageInfluenciadores::class)->assertOk();
    }

    public function test_atendente_nao_acessa_a_listagem(): void
    {
        $this->actingAs(User::factory()->atendente()->create(['name_first' => 'Atendente']));

        $this->get(route('filament.admin.resources.influenciadores.index'))->assertForbidden();
    }

    public function test_aprovar_com_comissao_zerada_aplica_a_comissao_padrao(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        Empresa::create(['empresa_razao_social' => 'Pizzaria', 'empresa_influ_comissao_tipo' => 'percentual', 'empresa_influ_comissao_valor' => 7]);

        $influenciador = $this->influenciadorPendente();
        $influenciador->update(['influenciador_valor_comissao' => 0]);

        Livewire::test(ManageInfluenciadores::class)->callTableAction('aprovar', $influenciador);

        $influenciador->refresh();
        $this->assertSame(StatusInfluenciadorEnum::ATIVO, $influenciador->influenciador_status);
        $this->assertSame('7.00', $influenciador->influenciador_valor_comissao);
    }

    public function test_aprovar_exige_comissao_maior_que_zero(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        Empresa::create(['empresa_razao_social' => 'Pizzaria', 'empresa_influ_comissao_valor' => 0]);

        $influenciador = $this->influenciadorPendente();
        $influenciador->update(['influenciador_valor_comissao' => 0]);

        Livewire::test(ManageInfluenciadores::class)->callTableAction('aprovar', $influenciador);

        $this->assertSame(StatusInfluenciadorEnum::PENDENTE, $influenciador->fresh()->influenciador_status);
    }
}
