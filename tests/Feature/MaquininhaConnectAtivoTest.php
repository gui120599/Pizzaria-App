<?php

namespace Tests\Feature;

use App\Enums\OperadoraMaquininha;
use App\Filament\Resources\Maquininhas\Pages\EditMaquininha;
use App\Models\Maquininha;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MaquininhaConnectAtivoTest extends TestCase
{
    use RefreshDatabase;

    /** #11 — uma Stone com número de série mas sem o flag só serve para lançamento manual. */
    public function test_scope_stone_disponivel_exige_connect_ativo_e_numero_de_serie(): void
    {
        $sunmi = Maquininha::create(['nome' => 'Sunmi', 'operadora' => OperadoraMaquininha::Stone, 'maquininha_connect_ativo' => true, 'numero_serie' => 'S1']);
        Maquininha::create(['nome' => 'Tectoy T8', 'operadora' => OperadoraMaquininha::Stone, 'maquininha_connect_ativo' => false, 'numero_serie' => 'T8']);
        Maquininha::create(['nome' => 'Sem série', 'operadora' => OperadoraMaquininha::Stone, 'maquininha_connect_ativo' => true]);
        Maquininha::create(['nome' => 'Série vazia', 'operadora' => OperadoraMaquininha::Stone, 'maquininha_connect_ativo' => true, 'numero_serie' => '']);
        Maquininha::create(['nome' => 'Cielo', 'operadora' => OperadoraMaquininha::Cielo]);

        $this->assertSame([$sunmi->id], Maquininha::stoneDisponivel()->pluck('id')->all());
    }

    public function test_maquininha_nova_nasce_sem_connect(): void
    {
        $this->assertFalse(Maquininha::create(['nome' => 'Nova', 'operadora' => OperadoraMaquininha::Stone, 'numero_serie' => 'X'])->fresh()->maquininha_connect_ativo);
    }

    public function test_toggle_no_cadastro_liga_o_connect_de_uma_maquininha_stone(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $maquininha = Maquininha::create(['nome' => 'Sunmi', 'operadora' => OperadoraMaquininha::Stone, 'numero_serie' => 'S1']);

        Livewire::test(EditMaquininha::class, ['record' => $maquininha->id])
            ->fillForm(['maquininha_connect_ativo' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($maquininha->fresh()->maquininha_connect_ativo);
    }

    public function test_connect_sem_numero_de_serie_e_rejeitado_no_cadastro(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $maquininha = Maquininha::create(['nome' => 'Sunmi', 'operadora' => OperadoraMaquininha::Stone]);

        Livewire::test(EditMaquininha::class, ['record' => $maquininha->id])
            ->fillForm(['maquininha_connect_ativo' => true])
            ->call('save')
            ->assertHasFormErrors(['maquininha_connect_ativo']);

        $this->assertFalse($maquininha->fresh()->maquininha_connect_ativo);
    }

    public function test_trocar_a_operadora_para_nao_stone_desliga_o_connect(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $maquininha = Maquininha::create(['nome' => 'Sunmi', 'operadora' => OperadoraMaquininha::Stone, 'maquininha_connect_ativo' => true, 'numero_serie' => 'S1']);

        Livewire::test(EditMaquininha::class, ['record' => $maquininha->id])
            ->fillForm(['operadora' => OperadoraMaquininha::Cielo->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($maquininha->fresh()->maquininha_connect_ativo);
    }
}
