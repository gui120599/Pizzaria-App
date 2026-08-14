<?php

namespace Tests\Feature;

use App\Models\Caixa;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CancelarVendasIniciadasVaziasTest extends TestCase
{
    use RefreshDatabase;

    private SessaoCaixa $sessaoCaixa;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->admin()->create(['name_first' => 'Admin']);
        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);
        $this->sessaoCaixa = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => $caixa->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => $user->id,
        ]);
    }

    private function venda(array $overrides = []): Venda
    {
        return Venda::create(array_merge([
            'venda_status' => 'INICIADA',
            'venda_sessao_caixa_id' => $this->sessaoCaixa->id,
            'venda_valor_total' => 0,
            'venda_datahora_iniciada' => now()->subHours(5),
        ], $overrides));
    }

    public function test_cancela_vendas_iniciadas_vazias_antigas(): void
    {
        $venda = $this->venda();

        $this->artisan('vendas:cancelar-iniciadas-vazias')->assertSuccessful();

        $this->assertSame('CANCELADA', $venda->fresh()->venda_status);
    }

    public function test_nao_cancela_venda_recente(): void
    {
        $venda = $this->venda(['venda_datahora_iniciada' => now()->subMinutes(10)]);

        $this->artisan('vendas:cancelar-iniciadas-vazias')->assertSuccessful();

        $this->assertSame('INICIADA', $venda->fresh()->venda_status);
    }

    public function test_nao_cancela_venda_com_valor(): void
    {
        $venda = $this->venda(['venda_valor_total' => 40]);

        $this->artisan('vendas:cancelar-iniciadas-vazias')->assertSuccessful();

        $this->assertSame('INICIADA', $venda->fresh()->venda_status);
    }

    public function test_respeita_opcao_horas(): void
    {
        $venda = $this->venda(['venda_datahora_iniciada' => now()->subHours(2)]);

        $this->artisan('vendas:cancelar-iniciadas-vazias', ['--horas' => 1])->assertSuccessful();

        $this->assertSame('CANCELADA', $venda->fresh()->venda_status);
    }
}
