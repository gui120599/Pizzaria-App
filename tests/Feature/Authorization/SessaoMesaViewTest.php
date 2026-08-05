<?php

namespace Tests\Feature\Authorization;

use App\Models\Mesa;
use App\Models\SessaoMesa;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessaoMesaViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // view:sessao_mesa (rota agora protegida) só existe via seeder.
        $this->seed(PermissionSeeder::class);
    }

    public function test_dono_da_sessao_ve_botao_fechar_mesa(): void
    {
        $garcom = User::factory()->create(['name_first' => 'Dono']);
        $garcom->assignRole('Atendente');
        $mesa = Mesa::factory()->create(['mesa_nome' => 'Mesa 1']);
        SessaoMesa::factory()->create([
            'sessao_mesa_usuario_id' => $garcom->id,
            'sessao_mesa_mesa_id' => $mesa->id,
            'sessao_mesa_status' => 'ABERTA',
        ]);

        $response = $this->actingAs($garcom)->get(route('sessaoMesa.pedidosMesa', ['mesa_id' => $mesa->id]));

        $response->assertOk();
        $response->assertSee('FECHAR MESA');
    }

    public function test_outro_atendente_nao_ve_botao_fechar_mesa(): void
    {
        $dono = User::factory()->create(['name_first' => 'Dono']);
        $dono->assignRole('Atendente');
        $outro = User::factory()->create(['name_first' => 'Outro']);
        $outro->assignRole('Atendente');
        $mesa = Mesa::factory()->create(['mesa_nome' => 'Mesa 1']);
        SessaoMesa::factory()->create([
            'sessao_mesa_usuario_id' => $dono->id,
            'sessao_mesa_mesa_id' => $mesa->id,
            'sessao_mesa_status' => 'ABERTA',
        ]);

        $response = $this->actingAs($outro)->get(route('sessaoMesa.pedidosMesa', ['mesa_id' => $mesa->id]));

        $response->assertOk();
        $response->assertDontSee('FECHAR MESA');
    }
}
