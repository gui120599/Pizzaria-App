<?php

namespace Tests\Feature;

use App\Models\Colaborador;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PontoAcessoTest extends TestCase
{
    use RefreshDatabase;

    public function test_colaborador_ve_o_proprio_espelho_e_nao_o_de_outro(): void
    {
        $ana = Colaborador::factory()->comUsuario()->create();
        $bruno = Colaborador::factory()->comUsuario()->create();

        $this->assertTrue($ana->user->can('verEspelho', $ana));
        $this->assertFalse($ana->user->can('verEspelho', $bruno));
    }

    public function test_gerente_ve_o_espelho_de_qualquer_colaborador(): void
    {
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);

        $this->assertTrue($gerente->can('verEspelho', Colaborador::factory()->create()));
    }

    public function test_colaborador_comum_nao_corrige_ponto_nem_o_proprio(): void
    {
        $ana = Colaborador::factory()->comUsuario()->create();
        $bruno = Colaborador::factory()->create();

        $this->assertFalse($ana->user->can('corrigirPonto', $bruno));
        $this->assertFalse($ana->user->can('corrigirPonto', $ana));
    }

    public function test_gerente_corrige_ponto_de_outro_mas_nao_o_proprio(): void
    {
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $proprio = Colaborador::factory()->for($gerente, 'user')->create();

        $this->assertTrue($gerente->can('corrigirPonto', Colaborador::factory()->create()));
        $this->assertFalse($gerente->can('corrigirPonto', $proprio));
    }

    public function test_gerente_nao_ve_remuneracao(): void
    {
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);

        $this->assertFalse($gerente->can('verRemuneracao', Colaborador::factory()->create()));
    }
}
