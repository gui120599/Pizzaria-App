<?php

namespace Tests\Feature;

use App\Filament\Garcom\Pages\AtenderMesa;
use App\Filament\Garcom\Pages\AtenderRetirada;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Cozinha e Caixa entram no /garcom só para os checklists. */
class GarcomChecklistAcessoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('garcom'));
    }

    public function test_cozinha_que_abre_o_salao_cai_nos_checklists(): void
    {
        $this->actingAs(User::factory()->cozinha()->create(['name_first' => 'Ana']));

        $this->get('/garcom')->assertRedirect('/garcom/checklists');
        $this->get('/garcom/checklists')->assertOk()->assertSeeText('Checklists de hoje');
    }

    public function test_caixa_nao_abre_as_telas_de_mesa_nem_de_retirada(): void
    {
        $this->actingAs(User::factory()->caixa()->create(['name_first' => 'Dani']));

        $this->get(AtenderMesa::getUrl(['sessao' => 1]))->assertForbidden();
        $this->get(AtenderRetirada::getUrl())->assertForbidden();
    }

    public function test_garcom_continua_no_mapa_de_mesas_e_ve_os_checklists(): void
    {
        $this->actingAs(User::factory()->garcom()->create(['name_first' => 'Bruno']));

        $this->get('/garcom')->assertOk()->assertSee('/garcom/checklists');
    }

    public function test_entregador_continua_sem_acesso_ao_salao(): void
    {
        $this->actingAs(User::factory()->entregador()->create(['name_first' => 'Edu']));

        $this->get('/garcom/checklists')->assertForbidden();
    }
}
