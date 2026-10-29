<?php

namespace Tests\Feature;

use App\Enums\StonePedidoModo;
use App\Enums\StonePedidoStatus;
use App\Exceptions\StoneConnectException;
use App\Models\Caixa;
use App\Models\ItensPedido;
use App\Models\Mesa;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\Pedido;
use App\Models\SessaoCaixa;
use App\Models\SessaoMesa;
use App\Models\StonePedido;
use App\Models\User;
use App\Models\Venda;
use App\Services\SessaoMesaService;
use App\Services\Stone\StoneRecebimentoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CenarioStone;
use Tests\TestCase;

/**
 * Etapa 2: elegibilidade() e valorDevido() do StoneRecebimentoService — o botão
 * de envio deixa de depender da forma combinada no pedido, e o valor cobrado é
 * sempre o devido (total − já pago − cobranças pendentes), calculado no servidor.
 */
class StoneElegibilidadeValorDevidoTest extends TestCase
{
    use CenarioStone;
    use RefreshDatabase;

    private StoneRecebimentoService $stone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurarStone();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $this->stone = app(StoneRecebimentoService::class);
    }

    // ── valorDevido ──────────────────────────────────────────────────────────

    public function test_valor_devido_de_pedido_sem_nada_pago_e_o_total_gravado(): void
    {
        $this->assertSame(80.0, $this->stone->valorDevido($this->pedidoComTotal(80)));
    }

    public function test_valor_devido_desconta_so_o_pagamento_ja_efetuado_no_pedido(): void
    {
        $pedido = $this->pedidoComTotal(100);
        $this->combinarPagamento($pedido, $this->formaCredito(), 40, pago: true);
        $this->combinarPagamento($pedido, $this->formaCredito(), 60, pago: false);

        $this->assertSame(60.0, $this->stone->valorDevido($pedido->fresh()));
    }

    public function test_valor_devido_desconta_o_saldo_das_cobrancas_pendentes_de_qualquer_modo(): void
    {
        $pedido = $this->pedidoComTotal(80);
        $maquininha = $this->maquininhaConnect();
        $this->cobrancaDoPedido($pedido, $maquininha, ['stp_valor_solicitado' => 50, 'stp_modo' => StonePedidoModo::Listado]);

        $this->assertSame(30.0, $this->stone->valorDevido($pedido->fresh()));

        $this->cobrancaDoPedido($pedido, $maquininha, ['stp_valor_solicitado' => 20, 'stp_modo' => StonePedidoModo::Direto, 'stp_status' => StonePedidoStatus::Indeterminado]);

        $this->assertSame(10.0, $this->stone->valorDevido($pedido->fresh()));
    }

    public function test_valor_devido_conta_o_que_a_cobranca_parcial_ja_recebeu(): void
    {
        $pedido = $this->pedidoComTotal(80);
        $this->cobrancaDoPedido($pedido, $this->maquininhaConnect(), [
            'stp_valor_solicitado' => 60, 'stp_valor_pago' => 25, 'stp_status' => StonePedidoStatus::PagoParcial,
        ]);

        // 25 já entraram + 35 ainda aguardando = 60 cobertos.
        $this->assertSame(20.0, $this->stone->valorDevido($pedido->fresh()));
    }

    public function test_cobranca_cancelada_nao_cobre_nada_mas_o_que_ja_foi_pago_antes_continua_abatido(): void
    {
        $pedido = $this->pedidoComTotal(80);
        $maquininha = $this->maquininhaConnect();
        $this->cobrancaDoPedido($pedido, $maquininha, ['stp_status' => StonePedidoStatus::Cancelado, 'stp_valor_solicitado' => 80]);
        $this->cobrancaDoPedido($pedido, $maquininha, ['stp_status' => StonePedidoStatus::Cancelado, 'stp_valor_solicitado' => 50, 'stp_valor_pago' => 20]);

        $this->assertSame(60.0, $this->stone->valorDevido($pedido->fresh()));
    }

    public function test_valor_devido_de_venda_desconta_o_pago_e_as_pendentes(): void
    {
        $venda = Venda::create(['venda_status' => 'INICIADA', 'venda_valor_total' => 100, 'venda_valor_pago' => 30]);
        StonePedido::create([
            'stp_venda_id' => $venda->id, 'stp_maquininha_id' => $this->maquininhaConnect()->id, 'stp_origem' => 'venda',
            'stp_valor_solicitado' => 20, 'stp_status' => 'aguardando', 'stp_modo' => 'listado', 'stp_order_id' => 'or_v1',
        ]);

        $this->assertSame(50.0, $this->stone->valorDevido($venda->fresh()));
    }

    public function test_valor_devido_de_conta_de_mesa_inclui_a_taxa_e_desconta_as_pendentes(): void
    {
        $sessao = $this->sessaoDeMesaComConsumo(100, taxaServico: 10);
        $this->assertSame(110.0, $this->stone->valorDevido($sessao));

        StonePedido::create([
            'stp_sessao_mesa_id' => $sessao->id, 'stp_maquininha_id' => $this->maquininhaConnect()->id, 'stp_origem' => 'sessao_mesa',
            'stp_valor_solicitado' => 40, 'stp_status' => 'aguardando', 'stp_modo' => 'listado', 'stp_order_id' => 'or_m1',
        ]);

        $this->assertSame(70.0, $this->stone->valorDevido($sessao->fresh()));
    }

    // ── elegibilidade ────────────────────────────────────────────────────────

    public function test_pedido_sem_forma_combinada_na_stone_e_elegivel(): void
    {
        $this->maquininhaConnect();
        $pedido = $this->pedidoComTotal(80);
        $this->combinarPagamento($pedido, $this->formaCredito(permiteStone: false));

        $this->assertSame(['elegivel' => true, 'motivo' => null, 'oculto' => false], $this->stone->elegibilidade($pedido->fresh()));
    }

    public function test_pedido_sem_nenhuma_forma_combinada_e_elegivel(): void
    {
        $this->maquininhaConnect();

        $this->assertTrue($this->stone->elegibilidade($this->pedidoComTotal(80))['elegivel']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function statusEncerrados(): array
    {
        return ['cancelado' => ['CANCELADO'], 'finalizado' => ['FINALIZADO']];
    }

    #[DataProvider('statusEncerrados')]
    public function test_pedido_cancelado_ou_finalizado_nao_e_elegivel(string $status): void
    {
        $this->maquininhaConnect();

        $elegibilidade = $this->stone->elegibilidade($this->pedidoComTotal(80, $status));

        $this->assertFalse($elegibilidade['elegivel']);
        $this->assertTrue($elegibilidade['oculto']);
    }

    public function test_pedido_excluido_ou_ja_recebido_no_pdv_nao_e_elegivel(): void
    {
        $this->maquininhaConnect();

        $excluido = $this->pedidoComTotal(80);
        $excluido->delete();
        $this->assertFalse($this->stone->elegibilidade($excluido->fresh() ?? Pedido::withTrashed()->find($excluido->id))['elegivel']);

        $recebido = $this->pedidoComTotal(80);
        $venda = Venda::create(['venda_status' => 'FINALIZADA']);
        $recebido->update(['pedido_venda_id' => $venda->id]);
        $this->assertFalse($this->stone->elegibilidade($recebido->fresh())['elegivel']);
    }

    public function test_pedido_lancado_em_venda_do_pdv_nao_e_elegivel_mas_o_da_propria_cobranca_stone_continua(): void
    {
        $maquininha = $this->maquininhaConnect();
        $alheio = $this->pedidoComTotal(80);
        $propria = $this->pedidoComTotal(80);

        $vendaDoPdv = Venda::create(['venda_status' => 'INICIADA']);
        ItensPedido::where('item_pedido_pedido_id', $alheio->id)->update(['item_pedido_venda_id' => $vendaDoPdv->id]);

        $vendaDaStone = Venda::create(['venda_status' => 'INICIADA']);
        ItensPedido::where('item_pedido_pedido_id', $propria->id)->update(['item_pedido_venda_id' => $vendaDaStone->id]);
        $this->cobrancaDoPedido($propria, $maquininha, ['stp_venda_id' => $vendaDaStone->id, 'stp_status' => StonePedidoStatus::PagoParcial, 'stp_valor_solicitado' => 50, 'stp_valor_pago' => 50]);

        $this->assertFalse($this->stone->elegibilidade($alheio->fresh())['elegivel']);
        $this->assertTrue($this->stone->elegibilidade($propria->fresh())['elegivel']);
    }

    public function test_sem_maquininha_connect_o_envio_fica_oculto(): void
    {
        $elegibilidade = $this->stone->elegibilidade($this->pedidoComTotal(80));

        $this->assertFalse($elegibilidade['elegivel']);
        $this->assertTrue($elegibilidade['oculto']);
    }

    public function test_sem_secret_key_o_envio_aparece_desabilitado_com_o_motivo(): void
    {
        $this->maquininhaConnect();
        config(['services.stone.secret_key' => null]);

        $elegibilidade = $this->stone->elegibilidade($this->pedidoComTotal(80));

        $this->assertFalse($elegibilidade['elegivel']);
        $this->assertFalse($elegibilidade['oculto']);
        $this->assertStringContainsString('não está configurada', $elegibilidade['motivo']);
    }

    public function test_cobranca_direto_pendente_e_resultado_desconhecido_bloqueiam_o_alvo(): void
    {
        $maquininha = $this->maquininhaConnect();

        $comDireto = $this->pedidoComTotal(80);
        $this->cobrancaDoPedido($comDireto, $maquininha, ['stp_modo' => StonePedidoModo::Direto, 'stp_valor_solicitado' => 30]);

        $comIndeterminado = $this->pedidoComTotal(80);
        $this->cobrancaDoPedido($comIndeterminado, $maquininha, ['stp_status' => StonePedidoStatus::Indeterminado, 'stp_valor_solicitado' => 30]);

        $this->assertFalse($this->stone->elegibilidade($comDireto->fresh())['elegivel']);
        $this->assertFalse($this->stone->elegibilidade($comIndeterminado->fresh())['elegivel']);
    }

    public function test_pedido_totalmente_coberto_nao_tem_o_que_cobrar(): void
    {
        $maquininha = $this->maquininhaConnect();

        $pago = $this->pedidoComTotal(80);
        $this->cobrancaDoPedido($pago, $maquininha, ['stp_status' => StonePedidoStatus::Pago, 'stp_valor_pago' => 80, 'stp_fechado_em' => now()]);

        $pagoNoPedido = $this->pedidoComTotal(80);
        $this->combinarPagamento($pagoNoPedido, $this->formaCredito(), 80, pago: true);

        $this->assertFalse($this->stone->elegibilidade($pago->fresh())['elegivel']);
        $this->assertFalse($this->stone->elegibilidade($pagoNoPedido->fresh())['elegivel']);
    }

    public function test_maquininha_sem_vaga_deixa_o_alvo_nao_elegivel_mas_visivel(): void
    {
        $maquininha = $this->maquininhaConnect();
        $this->ocuparVagas($maquininha, 30);

        $elegibilidade = $this->stone->elegibilidade($this->pedidoComTotal(80), $maquininha);

        $this->assertFalse($elegibilidade['elegivel']);
        $this->assertFalse($elegibilidade['oculto']);
        $this->assertStringContainsString('30 pedidos abertos', $elegibilidade['motivo']);
    }

    public function test_venda_e_conta_de_mesa_seguem_as_mesmas_regras(): void
    {
        $this->maquininhaConnect();

        $this->assertTrue($this->stone->elegibilidade(Venda::create(['venda_status' => 'INICIADA', 'venda_valor_total' => 50]))['elegivel']);
        $this->assertFalse($this->stone->elegibilidade(Venda::create(['venda_status' => 'FINALIZADA', 'venda_valor_total' => 50]))['elegivel']);
        $this->assertFalse($this->stone->elegibilidade(Venda::create(['venda_status' => 'INICIADA', 'venda_valor_total' => 0]))['elegivel']);
        $this->assertTrue($this->stone->elegibilidade($this->sessaoDeMesaComConsumo(100, taxaServico: 0))['elegivel']);
    }

    // ── O valor enviado é o devido ───────────────────────────────────────────

    public function test_cobranca_de_pedido_sai_pelo_valor_devido_e_nao_pelo_total(): void
    {
        $this->fakeStoneCriaPedidos('or_1');
        $maquininha = $this->maquininhaConnect();
        $pedido = $this->pedidoComTotal(100);
        $this->combinarPagamento($pedido, $this->formaCredito(), 40, pago: true);

        $stonePedido = $this->stone->iniciarCobrancaDePedido($pedido, null, $maquininha, modo: StonePedidoModo::Listado);

        $this->assertSame(60.0, (float) $stonePedido->stp_valor_solicitado);
        Http::assertSent(fn ($request) => $request->data()['items'][0]['amount'] === 6000);
    }

    public function test_valor_maior_que_o_devido_e_recusado_sem_chamar_a_stone(): void
    {
        $maquininha = $this->maquininhaConnect();
        $pedido = $this->pedidoComTotal(80);

        $this->expectException(StoneConnectException::class);
        $this->expectExceptionMessage('passa do saldo devido');

        try {
            $this->stone->iniciarCobrancaDePedido($pedido, null, $maquininha, 80.01, StonePedidoModo::Listado);
        } finally {
            $this->assertSame(0, StonePedido::count());
            Http::assertNothingSent();
        }
    }

    // ── Venda gerada pelo webhook leva o que já foi pago no pedido (#8) ──────

    public function test_venda_criada_pelo_webhook_recebe_o_pagamento_ja_efetuado_no_pedido_e_finaliza_sem_duplicar(): void
    {
        Http::fake(['api.pagar.me/*' => Http::response([], 200)]);
        $user = User::factory()->admin()->create(['name_first' => 'Caixa']);
        SessaoCaixa::create([
            'sessaocaixa_caixa_id' => Caixa::create(['caixa_nome' => 'Caixa 1'])->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_saldo_final' => 0,
            'sessaocaixa_user_id' => $user->id,
        ]);

        $maquininha = $this->maquininhaConnect();
        $pedido = $this->pedidoComTotal(100);
        $pix = OpcoesPagamento::create(['opcaopag_nome' => 'PIX CNPJ', 'opcaopag_desc_nfe' => 'InstantPayment', 'opcaopag_pix_cnpj' => true, 'opcaopag_tipo_taxa' => 'N/A', 'opcaopag_valor_percentual_taxa' => 0]);
        $this->combinarPagamento($pedido, $pix, 40, pago: true);

        // A cobrança sai pelo devido (60) e o webhook paga os 60.
        Http::fake([
            'api.pagar.me/core/v5/orders' => Http::response(['id' => 'or_dev', 'code' => 'rf-1'], 200),
            'api.pagar.me/*' => Http::response([], 200),
        ]);
        $stonePedido = $this->stone->iniciarCobrancaDePedido($pedido, null, $maquininha, modo: StonePedidoModo::Listado);
        $this->assertSame(60.0, (float) $stonePedido->stp_valor_solicitado);

        $webhook = [
            'id' => 'hook_dev_1', 'type' => 'charge.paid',
            'data' => [
                'id' => 'ch_dev_1', 'code' => '123', 'amount' => 6000, 'paid_amount' => 6000, 'status' => 'paid',
                'payment_method' => 'credit_card',
                'order' => ['id' => 'or_dev', 'code' => 'rf-'.$stonePedido->id],
                'metadata' => ['authorization_code' => 'AUT1'],
            ],
        ];

        $this->postJson('/api/webhook/stone-connect', $webhook)->assertOk();
        $this->postJson('/api/webhook/stone-connect', $webhook)->assertOk(); // entrega repetida

        $venda = Venda::sole();
        $this->assertSame('FINALIZADA', $venda->venda_status);
        $this->assertSame(100.0, (float) $venda->venda_valor_pago);
        $this->assertSame(2, PagamentosVenda::where('pg_venda_venda_id', $venda->id)->count());
        $this->assertSame(1, PagamentosVenda::where('pg_venda_venda_id', $venda->id)->where('pg_venda_tipo_integracao', 'integrated')->count());
        $this->assertSame(1, PagamentosVenda::where('pg_venda_venda_id', $venda->id)->whereNotNull('pg_venda_pagamento_pedido_id')->count());
        $this->assertSame('FINALIZADO', $pedido->fresh()->pedido_status);
    }

    // ── Apoio ────────────────────────────────────────────────────────────────

    private function sessaoDeMesaComConsumo(float $consumo, float $taxaServico): SessaoMesa
    {
        $mesa = Mesa::create(['mesa_nome' => 'Mesa '.uniqid(), 'mesa_status' => 'LIBERADA']);
        $sessao = app(SessaoMesaService::class)->abrir($mesa->id, auth()->id(), taxaServicoPercentual: $taxaServico);

        $pedido = $this->pedidoComTotal($consumo, 'PREPARANDO');
        $pedido->update(['pedido_sessao_mesa_id' => $sessao->id]);

        return $sessao->fresh();
    }
}
