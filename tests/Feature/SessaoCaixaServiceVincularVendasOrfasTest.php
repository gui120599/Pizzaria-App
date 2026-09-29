<?php

namespace Tests\Feature;

use App\Models\Caixa;
use App\Models\MovimentacoesSessaoCaixa;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Models\Venda;
use App\Services\SessaoCaixaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Cobre SessaoCaixaService::vincularVendasOrfas() — vincula vendas FINALIZADA
 * sem sessão de caixa (recebidas pela maquininha Stone antes de alguém abrir
 * o caixa, ver StoneVendaAutomaticaService) a uma sessão recém-aberta.
 */
class SessaoCaixaServiceVincularVendasOrfasTest extends TestCase
{
    use RefreshDatabase;

    private SessaoCaixaService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $this->service = app(SessaoCaixaService::class);
    }

    private function sessaoCaixaAberta(float $saldoInicial = 0.0): SessaoCaixa
    {
        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);

        return SessaoCaixa::create([
            'sessaocaixa_caixa_id' => $caixa->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now(),
            'sessaocaixa_saldo_inicial' => $saldoInicial,
            'sessaocaixa_saldo_final' => $saldoInicial,
            'sessaocaixa_user_id' => auth()->id(),
        ]);
    }

    private function vendaOrfa(float $valorPago = 50.0, string $status = 'FINALIZADA'): Venda
    {
        return Venda::create([
            'venda_status' => $status,
            'venda_sessao_caixa_id' => null,
            'venda_valor_total' => $valorPago,
            'venda_valor_pago' => $valorPago,
        ]);
    }

    public function test_vincula_apenas_as_vendas_selecionadas(): void
    {
        $sessao = $this->sessaoCaixaAberta(100.0);
        $venda1 = $this->vendaOrfa(50.0);
        $venda2 = $this->vendaOrfa(30.0);

        $qtd = $this->service->vincularVendasOrfas($sessao, [$venda1->id]);

        $this->assertSame(1, $qtd);
        $this->assertSame($sessao->id, $venda1->fresh()->venda_sessao_caixa_id);
        $this->assertNull($venda2->fresh()->venda_sessao_caixa_id);

        $this->assertDatabaseHas('movimentacoes_sessao_caixas', [
            'mov_sessaocaixa_id' => $sessao->id,
            'mov_venda_id' => $venda1->id,
            'mov_tipo' => 'ENTRADA',
        ]);
        $this->assertSame(0, MovimentacoesSessaoCaixa::where('mov_venda_id', $venda2->id)->count());

        $this->assertSame(150.0, (float) $sessao->fresh()->sessaocaixa_saldo_final);
    }

    public function test_ignora_venda_ja_vinculada_a_outra_sessao(): void
    {
        $sessaoAntiga = $this->sessaoCaixaAberta();
        $sessaoNova = $this->sessaoCaixaAberta();

        $venda = $this->vendaOrfa(50.0);
        $venda->update(['venda_sessao_caixa_id' => $sessaoAntiga->id]);

        $qtd = $this->service->vincularVendasOrfas($sessaoNova, [$venda->id]);

        $this->assertSame(0, $qtd);
        $this->assertSame($sessaoAntiga->id, $venda->fresh()->venda_sessao_caixa_id);
    }

    public function test_ignora_venda_cancelada(): void
    {
        $sessao = $this->sessaoCaixaAberta();
        $venda = $this->vendaOrfa(50.0, 'CANCELADA');

        $qtd = $this->service->vincularVendasOrfas($sessao, [$venda->id]);

        $this->assertSame(0, $qtd);
        $this->assertNull($venda->fresh()->venda_sessao_caixa_id);
    }

    public function test_bloqueia_se_sessao_nao_esta_aberta(): void
    {
        $sessao = $this->sessaoCaixaAberta();
        $sessao->update(['sessaocaixa_status' => 'FECHADA']);
        $venda = $this->vendaOrfa(50.0);

        $this->expectException(ValidationException::class);

        $this->service->vincularVendasOrfas($sessao, [$venda->id]);
    }

    public function test_lista_vazia_nao_faz_nada(): void
    {
        $sessao = $this->sessaoCaixaAberta(100.0);

        $qtd = $this->service->vincularVendasOrfas($sessao, []);

        $this->assertSame(0, $qtd);
        $this->assertSame(100.0, (float) $sessao->fresh()->sessaocaixa_saldo_final);
    }
}
