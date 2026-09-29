<?php

namespace Tests\Feature;

use App\Models\Caixa;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cobre a integração do fluxo legado de abertura de caixa com o modal de
 * "vendas sem sessão" (App\Livewire\VendasSemSessaoCaixa): a flash de sessão
 * recém-aberta dispara o auto-abrir na página de índice, e a página de
 * vendas de uma sessão específica sempre oferece o vínculo enquanto ABERTA.
 */
class SessaoCaixaControllerVendasOrfasTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->admin()->create(['name_first' => 'Admin']);
        $this->actingAs($this->user);
    }

    public function test_abrir_sessao_seta_a_flash_da_sessao_recem_aberta(): void
    {
        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);

        $response = $this->post(route('sessao_caixa.store'), [
            'sessaocaixa_caixa_id' => $caixa->id,
            'sessaocaixa_user_id' => $this->user->id,
            'sessaocaixa_saldo_inicial' => '0,00',
        ]);

        $sessao = SessaoCaixa::sole();
        $response->assertRedirect(route('sessao_caixa'));
        $response->assertSessionHas('sessao_recem_aberta_id', $sessao->id);
    }

    public function test_pagina_index_mostra_modal_de_vendas_orfas_quando_ha_flash_e_orfas(): void
    {
        Venda::create([
            'venda_status' => 'FINALIZADA',
            'venda_sessao_caixa_id' => null,
            'venda_valor_total' => 40.0,
            'venda_valor_pago' => 40.0,
            'venda_datahora_finalizada' => now(),
        ]);
        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);
        $sessao = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => $caixa->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => $this->user->id,
        ]);

        $response = $this->withSession(['sessao_recem_aberta_id' => $sessao->id])->get(route('sessao_caixa'));

        $response->assertOk();
        $response->assertSee('Vendas recebidas antes da abertura deste caixa');
    }

    public function test_pagina_de_vendas_da_sessao_oferece_vinculo_enquanto_aberta(): void
    {
        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);
        $sessao = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => $caixa->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => $this->user->id,
        ]);
        Venda::create([
            'venda_status' => 'FINALIZADA',
            'venda_sessao_caixa_id' => null,
            'venda_valor_total' => 40.0,
            'venda_valor_pago' => 40.0,
            'venda_datahora_finalizada' => now(),
        ]);

        $response = $this->get(route('sessao_caixa.vendas', ['sessao_caixa' => $sessao]));

        $response->assertOk();
        $response->assertSee('Vendas sem sessão de caixa');
    }

    public function test_pagina_de_vendas_nao_oferece_vinculo_quando_sessao_fechada(): void
    {
        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);
        $sessao = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => $caixa->id,
            'sessaocaixa_status' => 'FECHADA',
            'sessaocaixa_data_hora_abertura' => now(),
            'sessaocaixa_data_hora_fechamento' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => $this->user->id,
        ]);
        Venda::create([
            'venda_status' => 'FINALIZADA',
            'venda_sessao_caixa_id' => null,
            'venda_valor_total' => 40.0,
            'venda_valor_pago' => 40.0,
            'venda_datahora_finalizada' => now(),
        ]);

        $response = $this->get(route('sessao_caixa.vendas', ['sessao_caixa' => $sessao]));

        $response->assertOk();
        $response->assertDontSee('Vendas sem sessão de caixa');
    }
}
