<?php

namespace Tests\Feature;

use App\Models\Caixa;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendaCancelarVaziaTest extends TestCase
{
    use RefreshDatabase;

    private SessaoCaixa $sessaoCaixa;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->admin()->create(['name_first' => 'Admin']);
        $this->actingAs($user);

        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);
        $this->sessaoCaixa = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => $caixa->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => $user->id,
        ]);
    }

    public function test_cancela_venda_iniciada_vazia(): void
    {
        $venda = Venda::create([
            'venda_status' => 'INICIADA',
            'venda_sessao_caixa_id' => $this->sessaoCaixa->id,
            'venda_valor_total' => 0,
        ]);

        $this->post(route('venda.cancelar_vazia', ['venda' => $venda]))->assertNoContent();

        $this->assertSame('CANCELADA', $venda->fresh()->venda_status);
    }

    public function test_nao_cancela_venda_com_valor(): void
    {
        $venda = Venda::create([
            'venda_status' => 'INICIADA',
            'venda_sessao_caixa_id' => $this->sessaoCaixa->id,
            'venda_valor_total' => 30,
        ]);

        $this->post(route('venda.cancelar_vazia', ['venda' => $venda]))->assertNoContent();

        $this->assertSame('INICIADA', $venda->fresh()->venda_status);
    }

    public function test_nao_cancela_venda_ja_finalizada(): void
    {
        $venda = Venda::create([
            'venda_status' => 'FINALIZADA',
            'venda_sessao_caixa_id' => $this->sessaoCaixa->id,
            'venda_valor_total' => 0,
        ]);

        $this->post(route('venda.cancelar_vazia', ['venda' => $venda]))->assertNoContent();

        $this->assertSame('FINALIZADA', $venda->fresh()->venda_status);
    }
}
