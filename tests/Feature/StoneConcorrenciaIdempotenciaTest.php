<?php

namespace Tests\Feature;

use App\Enums\StonePedidoModo;
use App\Enums\StonePedidoStatus;
use App\Exceptions\StoneConnectException;
use App\Exceptions\StoneResultadoDesconhecidoException;
use App\Filament\Pages\AtenderPedido;
use App\Filament\Resources\Pedidos\Pages\ListPedidos;
use App\Models\Caixa;
use App\Models\Maquininha;
use App\Models\PagamentosVenda;
use App\Models\Pedido;
use App\Models\SessaoCaixa;
use App\Models\StonePedido;
use App\Models\User;
use App\Services\Stone\StoneRecebimentoService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\CenarioStone;
use Tests\TestCase;

/**
 * Etapa 3: o envio é seguro contra duplo clique, duas abas e timeout.
 *  - lock + elegibilidade rechecada + índice único (Direto e resultado desconhecido);
 *  - POST /orders com `code` rf-{id} e Idempotency-Key estável;
 *  - timeout/rede/5xx viram "indeterminado" e o MESMO registro é reenviado;
 *  - limite de pedidos abertos por maquininha, também no lote.
 */
class StoneConcorrenciaIdempotenciaTest extends TestCase
{
    use CenarioStone;
    use RefreshDatabase;

    private StoneRecebimentoService $stone;

    private Maquininha $sunmi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurarStone();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $this->stone = app(StoneRecebimentoService::class);
        $this->sunmi = $this->maquininhaConnect('Sunmi', '111');
    }

    protected function tearDown(): void
    {
        StonePedido::flushEventListeners();

        parent::tearDown();
    }

    /** @return list<Request> */
    private function postsDeCriacao(): array
    {
        return Http::recorded(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/orders'))
            ->map(fn (array $par): Request => $par[0])
            ->values()
            ->all();
    }

    // ── #1 Envio sai com code, Idempotency-Key e a maquininha certa ──────────

    public function test_envio_pelo_painel_manda_code_idempotency_key_serial_da_sunmi_e_o_valor_devido(): void
    {
        $this->fakeStoneCriaPedidos('or_painel');
        $pedido = $this->pedidoComTotal(100);
        $this->combinarPagamento($pedido, $this->formaCredito(), 40, pago: true);

        Livewire::test(ListPedidos::class)
            ->callAction(TestAction::make('enviarStone')->table($pedido), ['maquininha_id' => $this->sunmi->id])
            ->assertNotified();

        $stonePedido = StonePedido::sole();
        $this->assertSame('aguardando', $stonePedido->stp_status->value);
        $this->assertSame('or_painel', $stonePedido->stp_order_id);
        $this->assertSame(60.0, (float) $stonePedido->stp_valor_solicitado);

        [$post] = $this->postsDeCriacao();
        $this->assertSame("stone-pedido-{$stonePedido->id}", $post->header('Idempotency-Key')[0]);
        $this->assertSame("rf-{$stonePedido->id}", $post['code']);
        $this->assertSame(['111'], $post['poi_payment_settings']['devices_serial_number']);
        $this->assertSame(6000, $post['items'][0]['amount']);
    }

    public function test_envio_pelo_atendimento_manda_code_idempotency_key_serial_da_sunmi_e_o_valor_devido(): void
    {
        $this->fakeStoneCriaPedidos('or_atendimento');
        $pedido = $this->pedidoComTotal(80);

        Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->call('abrirModalStone')
            ->set('stoneMaquininhaId', $this->sunmi->id)
            ->call('enviarCobrancaStone')
            ->assertSet('modalStoneAberta', false)
            ->assertRedirect(AtenderPedido::getUrl(['pedido' => $pedido->id]));

        $stonePedido = StonePedido::sole();
        $this->assertSame(80.0, (float) $stonePedido->stp_valor_solicitado);

        [$post] = $this->postsDeCriacao();
        $this->assertSame("stone-pedido-{$stonePedido->id}", $post->header('Idempotency-Key')[0]);
        $this->assertSame("rf-{$stonePedido->id}", $post['code']);
        $this->assertSame(['111'], $post['poi_payment_settings']['devices_serial_number']);
        $this->assertSame(8000, $post['items'][0]['amount']);
    }

    public function test_nenhuma_chamada_http_acontece_dentro_de_transacao_de_banco(): void
    {
        $nivelDeFora = DB::transactionLevel();
        $niveisNoHttp = [];

        Http::fake(function (Request $request) use (&$niveisNoHttp) {
            $niveisNoHttp[] = DB::transactionLevel();

            return Http::response(['id' => 'or_x', 'code' => 'rf-1'], 200);
        });

        $this->stone->iniciarCobrancaDePedido($this->pedidoComTotal(80), null, $this->sunmi, modo: StonePedidoModo::Listado);

        $this->assertNotEmpty($niveisNoHttp);
        $this->assertSame([$nivelDeFora], array_unique($niveisNoHttp));
    }

    // ── #2 Direto: uma pendente por alvo ─────────────────────────────────────

    public function test_segundo_envio_direto_com_direto_pendente_e_recusado_e_so_um_post_sai(): void
    {
        $this->fakeStoneCriaPedidos('or_1', 'or_2');
        $credito = $this->formaCredito();
        $pedido = $this->pedidoComTotal(80);

        $this->stone->iniciarCobrancaDePedido($pedido, $credito, $this->sunmi, modo: StonePedidoModo::Direto);

        try {
            $this->stone->iniciarCobrancaDePedido($pedido->fresh(), $credito, $this->sunmi, modo: StonePedidoModo::Direto);
            $this->fail('O segundo envio Direto deveria ser recusado.');
        } catch (StoneConnectException $e) {
            $this->assertStringContainsString('Já existe uma cobrança aguardando', $e->getMessage());
        }

        Http::assertSentCount(1);
        $this->assertSame(1, StonePedido::count());

        Livewire::test(ListPedidos::class)->assertActionHidden(TestAction::make('enviarStone')->table($pedido));
    }

    // ── #2a Listado: várias pendentes, até o valor devido ────────────────────

    public function test_listado_aceita_nova_cobranca_ate_o_saldo_devido_e_recusa_o_que_passa(): void
    {
        $this->fakeStoneCriaPedidos('or_a', 'or_b');
        $pedido = $this->pedidoComTotal(80);
        $this->cobrancaDoPedido($pedido, $this->sunmi, ['stp_valor_solicitado' => 50]);

        try {
            $this->stone->iniciarCobrancaDePedido($pedido->fresh(), null, $this->sunmi, 31.0, StonePedidoModo::Listado);
            $this->fail('R$ 31 passa do saldo devido (R$ 30).');
        } catch (StoneConnectException $e) {
            $this->assertStringContainsString('passa do saldo devido', $e->getMessage());
        }
        Http::assertNothingSent();

        $segunda = $this->stone->iniciarCobrancaDePedido($pedido->fresh(), null, $this->sunmi, 30.0, StonePedidoModo::Listado);
        $this->assertSame(30.0, (float) $segunda->stp_valor_solicitado);
        $this->assertSame(2, StonePedido::where('stp_pedido_id', $pedido->id)->count());
    }

    public function test_listado_com_o_total_ja_pendente_recusa_qualquer_valor(): void
    {
        $pedido = $this->pedidoComTotal(80);
        $this->cobrancaDoPedido($pedido, $this->sunmi, ['stp_valor_solicitado' => 80]);

        $this->expectException(StoneConnectException::class);
        $this->expectExceptionMessage('Não há valor a cobrar');

        try {
            $this->stone->iniciarCobrancaDePedido($pedido->fresh(), null, $this->sunmi, 1.0, StonePedidoModo::Listado);
        } finally {
            Http::assertNothingSent();
        }
    }

    // ── #2b Corrida: o índice único é a última barreira ──────────────────────

    public function test_corrida_o_indice_unico_rejeita_o_segundo_direto_e_o_servico_devolve_mensagem_amigavel(): void
    {
        $credito = $this->formaCredito();
        $pedido = $this->pedidoComTotal(80);

        // Outra sessão ganha a corrida DEPOIS da checagem de elegibilidade e ANTES do nosso INSERT.
        $concorrente = false;
        StonePedido::creating(function () use ($pedido, &$concorrente): void {
            if ($concorrente) {
                return;
            }
            $concorrente = true;

            DB::table('stone_pedidos')->insert([
                'stp_pedido_id' => $pedido->id, 'stp_maquininha_id' => $this->sunmi->id, 'stp_origem' => 'pedido',
                'stp_valor_solicitado' => 80, 'stp_status' => 'aguardando', 'stp_modo' => 'direto',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        try {
            $this->stone->iniciarCobrancaDePedido($pedido, $credito, $this->sunmi, modo: StonePedidoModo::Direto);
            $this->fail('O índice único deveria barrar o segundo Direto.');
        } catch (StoneConnectException $e) {
            $this->assertStringContainsString('Já existe uma cobrança aguardando', $e->getMessage());
        }

        // O "concorrente" vive na mesma conexão do teste e some no rollback; o que importa é que nada foi enviado.
        Http::assertNothingSent();
    }

    public function test_indice_unico_cobre_direto_pendente_e_resultado_desconhecido_mas_nao_listado(): void
    {
        $pedido = $this->pedidoComTotal(80);
        $inserir = fn (string $modo, string $status): bool => DB::table('stone_pedidos')->insert([
            'stp_pedido_id' => $pedido->id, 'stp_maquininha_id' => $this->sunmi->id, 'stp_origem' => 'pedido',
            'stp_valor_solicitado' => 10, 'stp_status' => $status, 'stp_modo' => $modo,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Listado pode repetir à vontade.
        $this->assertTrue($inserir('listado', 'aguardando'));
        $this->assertTrue($inserir('listado', 'pago_parcial'));
        // Direto cancelado/pago não ocupa a chave.
        $this->assertTrue($inserir('direto', 'cancelado'));
        $this->assertTrue($inserir('direto', 'pago'));

        $this->assertTrue($inserir('direto', 'aguardando'));

        foreach ([['direto', 'aguardando'], ['direto', 'pago_parcial'], ['listado', 'indeterminado']] as [$modo, $status]) {
            try {
                $inserir($modo, $status);
                $this->fail("Deveria barrar {$modo}/{$status} com um Direto pendente.");
            } catch (UniqueConstraintViolationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ── #2c Limite de pedidos abertos por maquininha ─────────────────────────

    public function test_vagas_contam_pendentes_e_pagos_nao_fechados_e_ignoram_cancelados_falhas_e_fechados(): void
    {
        $this->ocuparVagas($this->sunmi, 5, StonePedidoStatus::Aguardando);
        $this->ocuparVagas($this->sunmi, 3, StonePedidoStatus::Indeterminado);
        $this->ocuparVagas($this->sunmi, 2, StonePedidoStatus::Pago); // pago sem stp_fechado_em: continua no POS
        $this->ocuparVagas($this->sunmi, 4, StonePedidoStatus::Cancelado);
        $this->ocuparVagas($this->sunmi, 4, StonePedidoStatus::Falha);
        $this->ocuparVagas($this->sunmi, 6, StonePedidoStatus::Estornado);
        StonePedido::create([
            'stp_maquininha_id' => $this->sunmi->id, 'stp_origem' => 'pedido', 'stp_order_id' => 'or_pago_fechado',
            'stp_valor_solicitado' => 10, 'stp_valor_pago' => 10, 'stp_status' => 'pago', 'stp_modo' => 'listado', 'stp_fechado_em' => now(),
        ]);

        $this->assertSame(30 - 10, $this->stone->vagasAbertas($this->sunmi));
    }

    public function test_lote_que_cabe_nas_vagas_envia_todos(): void
    {
        $this->fakeStoneCriaPedidos('or_1', 'or_2');
        $this->ocuparVagas($this->sunmi, 28);
        $pedidos = collect([$this->pedidoComTotal(40), $this->pedidoComTotal(50)]);

        $resultado = $this->stone->iniciarCobrancasEmLote($pedidos, null, $this->sunmi);

        $this->assertNull($resultado['recusado']);
        $this->assertCount(2, $resultado['enviados']);
        Http::assertSentCount(2);
        $this->assertSame(0, $this->stone->vagasAbertas($this->sunmi));
    }

    public function test_lote_que_nao_cabe_nas_vagas_nao_envia_nenhum_e_explica(): void
    {
        $this->ocuparVagas($this->sunmi, 28);
        $pedidos = collect([
            $this->pedidoComTotal(40), $this->pedidoComTotal(50), $this->pedidoComTotal(60),
            $this->pedidoComTotal(70, 'CANCELADO'), // não elegível: não entra na conta das vagas
        ]);

        $resultado = $this->stone->iniciarCobrancasEmLote($pedidos, null, $this->sunmi);

        $this->assertStringContainsString('2 vaga(s) livre(s)', $resultado['recusado']);
        $this->assertStringContainsString('3 pedido(s) elegível(eis)', $resultado['recusado']);
        $this->assertStringContainsString('Desmarque 1', $resultado['recusado']);
        $this->assertSame([], $resultado['enviados']);
        Http::assertNothingSent();
        $this->assertSame(28, StonePedido::count());
    }

    public function test_lote_ignora_os_nao_elegiveis_e_diz_o_motivo(): void
    {
        $this->fakeStoneCriaPedidos('or_1');
        $cancelado = $this->pedidoComTotal(70, 'CANCELADO');

        $resultado = $this->stone->iniciarCobrancasEmLote([$this->pedidoComTotal(40), $cancelado], null, $this->sunmi);

        $this->assertCount(1, $resultado['enviados']);
        $this->assertStringContainsString('cancelado', $resultado['ignorados'][$cancelado->id]);
    }

    public function test_envio_individual_com_a_maquininha_cheia_e_recusado(): void
    {
        $this->ocuparVagas($this->sunmi, 30);

        try {
            $this->stone->iniciarCobrancaDePedido($this->pedidoComTotal(80), null, $this->sunmi, modo: StonePedidoModo::Listado);
            $this->fail('Maquininha com 30 pedidos abertos deveria recusar.');
        } catch (StoneConnectException $e) {
            $this->assertStringContainsString('30 pedidos abertos', $e->getMessage());
            $this->assertStringContainsString('Financeiro › Pedidos Stone', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_limite_de_vagas_e_configuravel(): void
    {
        config(['services.stone.limite_pedidos_abertos' => 3]);
        $this->ocuparVagas($this->sunmi, 2);

        $this->assertSame(1, $this->stone->vagasAbertas($this->sunmi));
    }

    // ── #3 Pago não reenvia ──────────────────────────────────────────────────

    public function test_pedido_pago_na_maquininha_nao_oferece_nem_aceita_novo_envio(): void
    {
        $pedido = $this->pedidoComTotal(80);
        $this->cobrancaDoPedido($pedido, $this->sunmi, ['stp_status' => StonePedidoStatus::Pago, 'stp_valor_pago' => 80, 'stp_fechado_em' => now()]);

        Livewire::test(ListPedidos::class)->assertActionHidden(TestAction::make('enviarStone')->table($pedido));

        $this->expectException(StoneConnectException::class);
        try {
            $this->stone->iniciarCobrancaDePedido($pedido->fresh(), null, $this->sunmi, modo: StonePedidoModo::Listado);
        } finally {
            Http::assertNothingSent();
        }
    }

    // ── #4 Cancelamento, recusa de cartão e recusa da API ────────────────────

    public function test_cancelar_antes_de_pagar_fecha_como_canceled_e_libera_novo_envio(): void
    {
        $this->fakeStoneCriaPedidos('or_1', 'or_2');
        $pedido = $this->pedidoComTotal(80);
        $primeira = $this->stone->iniciarCobrancaDePedido($pedido, null, $this->sunmi, modo: StonePedidoModo::Listado);

        $this->stone->cancelarCobranca($primeira);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH' && str_ends_with($request->url(), '/orders/or_1/closed') && $request['status'] === 'canceled');
        $this->assertSame('cancelado', $primeira->fresh()->stp_status->value);
        $this->assertTrue($this->stone->elegibilidade($pedido->fresh())['elegivel']);

        $segunda = $this->stone->iniciarCobrancaDePedido($pedido->fresh(), null, $this->sunmi, modo: StonePedidoModo::Listado);
        $this->assertSame('or_2', $segunda->stp_order_id);
    }

    public function test_recusa_de_cartao_na_maquininha_nao_muda_nada_no_sistema(): void
    {
        // A recusa no POS não gera webhook: o pedido segue aberto na Stone, sem charge paga.
        Http::fake(['api.pagar.me/core/v5/orders/or_1' => Http::response(['id' => 'or_1', 'code' => 'rf-1', 'status' => 'pending', 'charges' => [['id' => 'ch_1', 'status' => 'failed']]], 200)]);
        $pedido = $this->pedidoComTotal(80);
        $stonePedido = $this->cobrancaDoPedido($pedido, $this->sunmi, ['stp_order_id' => 'or_1']);

        $atual = $this->stone->reconsultar($stonePedido);

        $this->assertSame('aguardando', $atual->stp_status->value);
        $this->assertSame(0, PagamentosVenda::count());
    }

    public function test_recusa_comprovada_da_api_vira_falha_com_o_erro_e_libera_nova_tentativa(): void
    {
        Http::fake(['api.pagar.me/core/v5/orders' => Http::sequence()
            ->push(['message' => 'terminal inválido'], 422)
            ->push(['id' => 'or_ok', 'code' => 'rf-2'], 200)]);
        $pedido = $this->pedidoComTotal(80);

        try {
            $this->stone->iniciarCobrancaDePedido($pedido, null, $this->sunmi, modo: StonePedidoModo::Listado);
            $this->fail('A API recusou o pedido.');
        } catch (StoneConnectException $e) {
            $this->assertNotInstanceOf(StoneResultadoDesconhecidoException::class, $e);
        }

        $falha = StonePedido::sole();
        $this->assertSame('falha', $falha->stp_status->value);
        $this->assertStringContainsString('terminal inválido', $falha->stp_erro);

        $nova = $this->stone->iniciarCobrancaDePedido($pedido->fresh(), null, $this->sunmi, modo: StonePedidoModo::Listado);
        $this->assertSame('aguardando', $nova->stp_status->value);
        $this->assertNotSame($falha->id, $nova->id);
    }

    // ── #5 Resultado desconhecido ────────────────────────────────────────────

    public function test_timeout_na_criacao_vira_indeterminado_bloqueia_novo_envio_e_a_reconsulta_reenvia_o_mesmo_registro(): void
    {
        Http::fake(['api.pagar.me/core/v5/orders' => Http::sequence()
            ->pushFailedConnection('timeout')
            ->push(['id' => 'or_recuperado', 'code' => 'rf-1', 'status' => 'pending'], 200)]);
        $pedido = $this->pedidoComTotal(80);

        try {
            $this->stone->iniciarCobrancaDePedido($pedido, null, $this->sunmi, modo: StonePedidoModo::Listado);
            $this->fail('O timeout deveria virar resultado desconhecido.');
        } catch (StoneResultadoDesconhecidoException $e) {
            $this->assertNotNull($e->stonePedidoId());
        }

        $indeterminado = StonePedido::sole();
        $this->assertSame('indeterminado', $indeterminado->stp_status->value);
        $this->assertNull($indeterminado->stp_order_id);

        // Não se envia de novo enquanto não reconsultar.
        $this->assertFalse($this->stone->elegibilidade($pedido->fresh())['elegivel']);
        try {
            $this->stone->iniciarCobrancaDePedido($pedido->fresh(), null, $this->sunmi, modo: StonePedidoModo::Listado);
            $this->fail('Resultado desconhecido bloqueia novo envio.');
        } catch (StoneConnectException $e) {
            $this->assertStringContainsString('reconsulte', $e->getMessage());
        }
        $this->assertSame(1, StonePedido::count());

        $recuperado = $this->stone->reconsultar($indeterminado);

        $this->assertSame($indeterminado->id, $recuperado->id);
        $this->assertSame('aguardando', $recuperado->stp_status->value);
        $this->assertSame('or_recuperado', $recuperado->stp_order_id);
        $this->assertSame(1, StonePedido::count());

        // O reenvio usou a MESMA Idempotency-Key e o mesmo code.
        $posts = $this->postsDeCriacao();
        $this->assertCount(2, $posts);
        $this->assertSame($posts[0]->header('Idempotency-Key'), $posts[1]->header('Idempotency-Key'));
        $this->assertSame("stone-pedido-{$indeterminado->id}", $posts[1]->header('Idempotency-Key')[0]);
        $this->assertSame("rf-{$indeterminado->id}", $posts[1]['code']);
    }

    public function test_erro_5xx_na_criacao_tambem_e_resultado_desconhecido_e_nao_falha(): void
    {
        Http::fake(['api.pagar.me/*' => Http::response(['message' => 'Internal error'], 503)]);

        $this->expectException(StoneResultadoDesconhecidoException::class);

        try {
            $this->stone->iniciarCobrancaDePedido($this->pedidoComTotal(80), null, $this->sunmi, modo: StonePedidoModo::Listado);
        } finally {
            $this->assertSame('indeterminado', StonePedido::sole()->stp_status->value);
        }
    }

    public function test_com_order_id_a_reconsulta_pergunta_a_stone_em_vez_de_reenviar(): void
    {
        Http::fake(['api.pagar.me/core/v5/orders/or_9' => Http::response(['id' => 'or_9', 'code' => 'rf-1', 'status' => 'canceled', 'charges' => []], 200)]);
        $stonePedido = $this->cobrancaDoPedido($this->pedidoComTotal(80), $this->sunmi, ['stp_status' => StonePedidoStatus::Indeterminado, 'stp_order_id' => 'or_9']);

        $atual = $this->stone->reconsultar($stonePedido);

        $this->assertSame('cancelado', $atual->stp_status->value);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET');
    }

    public function test_passadas_24h_sem_order_id_continua_indeterminado_e_pede_conferencia_manual(): void
    {
        $stonePedido = $this->cobrancaDoPedido($this->pedidoComTotal(80), $this->sunmi, ['stp_status' => StonePedidoStatus::Indeterminado, 'stp_order_id' => null]);
        StonePedido::whereKey($stonePedido->id)->update(['created_at' => now()->subHours(25)]);

        try {
            $this->stone->reconsultar($stonePedido);
            $this->fail('Fora da janela da Idempotency-Key não se reenvia.');
        } catch (StoneConnectException $e) {
            $this->assertStringContainsString("rf-{$stonePedido->id}", $e->getMessage());
        }

        $this->assertSame('indeterminado', $stonePedido->fresh()->stp_status->value);
        Http::assertNothingSent();
    }

    public function test_cobranca_de_resultado_desconhecido_sem_order_id_nao_se_cancela(): void
    {
        $stonePedido = $this->cobrancaDoPedido($this->pedidoComTotal(80), $this->sunmi, ['stp_status' => StonePedidoStatus::Indeterminado, 'stp_order_id' => null]);

        $this->expectException(StoneConnectException::class);

        try {
            $this->stone->cancelarCobranca($stonePedido);
        } finally {
            $this->assertSame('indeterminado', $stonePedido->fresh()->stp_status->value);
            Http::assertNothingSent();
        }
    }

    /** #5c — o webhook chega antes de gravarmos o order_id: o `code` rf-{id} resolve a cobrança. */
    public function test_charge_paid_com_order_id_desconhecido_resolve_pelo_code_e_lanca_o_pagamento(): void
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

        $pedido = $this->pedidoComTotal(80);
        $stonePedido = $this->cobrancaDoPedido($pedido, $this->sunmi, ['stp_order_id' => null, 'stp_status' => StonePedidoStatus::Indeterminado, 'stp_valor_solicitado' => 80]);

        $this->postJson('/api/webhook/stone-connect', [
            'id' => 'hook_rf', 'type' => 'charge.paid',
            'data' => [
                'id' => 'ch_rf', 'code' => '555', 'amount' => 8000, 'paid_amount' => 8000, 'status' => 'paid', 'payment_method' => 'credit_card',
                'order' => ['id' => 'or_so_no_webhook', 'code' => "rf-{$stonePedido->id}"],
                'metadata' => ['authorization_code' => 'A1'],
            ],
        ])->assertOk();

        $stonePedido->refresh();
        $this->assertSame('or_so_no_webhook', $stonePedido->stp_order_id);
        $this->assertSame('pago', $stonePedido->stp_status->value);
        $this->assertSame(1, PagamentosVenda::where('pg_venda_tipo_integracao', 'integrated')->count());
        $this->assertSame('FINALIZADO', $pedido->fresh()->pedido_status);
    }

    public function test_resposta_do_envio_que_chega_depois_do_webhook_nao_regride_o_status_pago(): void
    {
        $pedido = $this->pedidoComTotal(80);
        $stonePedido = $this->cobrancaDoPedido($pedido, $this->sunmi, ['stp_order_id' => null, 'stp_status' => StonePedidoStatus::Indeterminado]);

        // O webhook charge.paid é processado enquanto o POST ainda está em andamento.
        Http::fake(['api.pagar.me/core/v5/orders' => function () use ($stonePedido) {
            StonePedido::whereKey($stonePedido->id)->update(['stp_status' => 'pago', 'stp_valor_pago' => 80]);

            return Http::response(['id' => 'or_tardio', 'code' => "rf-{$stonePedido->id}"], 200);
        }]);

        $this->stone->reconsultar($stonePedido);

        $atual = $stonePedido->fresh();
        $this->assertSame('pago', $atual->stp_status->value);
        $this->assertSame('or_tardio', $atual->stp_order_id);
    }

    // ── Conciliação (stone:conciliar-pedidos) ────────────────────────────────

    public function test_conciliacao_resolve_indeterminado_sem_order_id_reenviando_o_mesmo_registro(): void
    {
        Http::fake(['api.pagar.me/core/v5/orders' => Http::response(['id' => 'or_conc', 'code' => 'rf-1'], 200)]);
        $stonePedido = $this->cobrancaDoPedido($this->pedidoComTotal(80), $this->sunmi, ['stp_status' => StonePedidoStatus::Indeterminado, 'stp_order_id' => null]);

        $this->artisan('stone:conciliar-pedidos')->assertSuccessful();

        $atual = $stonePedido->fresh();
        $this->assertSame('aguardando', $atual->stp_status->value);
        $this->assertSame('or_conc', $atual->stp_order_id);
        $this->assertSame(1, StonePedido::count());
        $this->assertSame("stone-pedido-{$stonePedido->id}", $this->postsDeCriacao()[0]->header('Idempotency-Key')[0]);
    }

    public function test_conciliacao_trata_aguardando_sem_order_id_como_resultado_desconhecido_e_nunca_expira_para_falha(): void
    {
        Http::fake(['api.pagar.me/core/v5/orders' => Http::response(['message' => 'fora do ar'], 503)]);
        $stonePedido = $this->cobrancaDoPedido($this->pedidoComTotal(80), $this->sunmi, ['stp_status' => StonePedidoStatus::Aguardando, 'stp_order_id' => null]);
        StonePedido::whereKey($stonePedido->id)->update(['created_at' => now()->subHours(3)]);

        $this->artisan('stone:conciliar-pedidos')->assertSuccessful();

        // A Stone continua fora do ar: o registro segue pendente (não vira falha, que liberaria um segundo pedido).
        $this->assertContains($stonePedido->fresh()->stp_status, [StonePedidoStatus::Aguardando, StonePedidoStatus::Indeterminado]);
        $this->assertNotSame(StonePedidoStatus::Falha, $stonePedido->fresh()->stp_status);
    }

    public function test_conciliacao_fecha_na_stone_o_pedido_pago_que_ficou_sem_fechamento_e_libera_a_vaga(): void
    {
        Http::fake(['api.pagar.me/*' => Http::response([], 200)]);
        $this->ocuparVagas($this->sunmi, 29);
        $pago = StonePedido::create([
            'stp_maquininha_id' => $this->sunmi->id, 'stp_origem' => 'pedido', 'stp_order_id' => 'or_pago_aberto',
            'stp_valor_solicitado' => 10, 'stp_valor_pago' => 10, 'stp_status' => 'pago', 'stp_modo' => 'listado',
        ]);
        $this->assertSame(0, $this->stone->vagasAbertas($this->sunmi));

        $this->artisan('stone:conciliar-pedidos')->assertSuccessful();

        $this->assertNotNull($pago->fresh()->stp_fechado_em);
        $this->assertSame(1, $this->stone->vagasAbertas($this->sunmi));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH' && str_ends_with($request->url(), '/orders/or_pago_aberto/closed') && $request['status'] === 'paid');
    }
}
