<?php

namespace Tests\Feature;

use App\Filament\Resources\Caixas\Pages\CreateCaixa;
use App\Filament\Resources\Caixas\Pages\EditCaixa;
use App\Filament\Resources\Caixas\Pages\ListCaixas;
use App\Models\Caixa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CaixaResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_lista_cria_e_edita_caixa(): void
    {
        $admin = User::factory()->admin()->create(['name_first' => 'Admin']);
        $this->actingAs($admin);

        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);

        Livewire::test(ListCaixas::class)
            ->assertCanSeeTableRecords([$caixa]);

        Livewire::test(CreateCaixa::class)
            ->fillForm(['caixa_nome' => 'Caixa 2'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('caixas', ['caixa_nome' => 'Caixa 2']);

        Livewire::test(EditCaixa::class, ['record' => $caixa->getKey()])
            ->fillForm(['caixa_nome' => 'Caixa 1 renomeada'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('caixas', ['id' => $caixa->id, 'caixa_nome' => 'Caixa 1 renomeada']);
    }

    /** Nenhuma role além de Admin recebeu permissão de 'caixa' no ResourcePermissionSeeder — mesmo tratamento de Maquininha/Sessão/Fechamento de Caixa. */
    public function test_gerente_nao_acessa_o_recurso_de_caixa(): void
    {
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);

        $response = $this->actingAs($gerente)->get('/admin/caixas');

        $response->assertForbidden();
    }
}
