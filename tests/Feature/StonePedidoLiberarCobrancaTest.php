<?php

namespace Tests\Feature;

use App\Enums\StonePedidoStatus;
use App\Exceptions\StoneConnectException;
use App\Exceptions\StoneResultadoDesconhecidoException;
use App\Filament\Resources\StonePedidos\Pages\ManageStonePedidos;
use App\Models\Maquininha;
use App\Models\StonePedido;
use App\Models\StoneWebhook;
use App\Models\User;
use App\Services\Stone\StoneRecebimentoService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\Support\CenarioStone;
use Tests\TestCase;

/**
 * A Stone devolve 500 na criação: a cobrança fica como resultado desconhecido,
 * sem Order ID, travando a venda. O motivo aparece na tela, o pedido nunca é
 * reenviado, e há como informar o Order ID do painel ou liberar manualmente.
 */
class StonePedidoLiberarCobrancaTest extends TestCase
{
    use CenarioStone;
    use RefreshDatabase;

    private Maquininha $sunmi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurarStone();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Ana', 'name' => 'Ana']));
        $this->sunmi = $this->maquininhaConnect('Sunmi', '111');
    }

    private function desconhecida(array $atributos = [], ?int $minutosAtras = null): StonePedido
    {
        $cobranca = $this->cobrancaDoPedido($this->pedidoComTotal(80), $this->sunmi, $atributos + ['stp_status' => StonePedidoStatus::Indeterminado, 'stp_order_id' => null]);

        if ($minutosAtras !== null) {
            StonePedido::whereKey($cobranca->id)->update(['created_at' => now()->subMinutes($minutosAtras)]);
        }

        return $cobranca;
    }

    public function test_erro_500_grava_o_status_http_para_aparecer_na_tela(): void
    {
        Http::fake(['api.pagar.me/*' => Http::response(['message' => 'Internal Server Error'], 500)]);

        try {
            app(StoneRecebimentoService::class)->iniciarCobrancaDePedido($this->pedidoComTotal(80), $this->formaCredito(), $this->sunmi);
            $this->fail('Deveria lançar resultado desconhecido.');
        } catch (StoneResultadoDesconhecidoException) {
        }

        $this->assertStringContainsString('a Stone respondeu HTTP 500: Internal Server Error', StonePedido::sole()->stp_erro);
    }

    public function test_falha_de_conexao_grava_o_motivo(): void
    {
        Http::fake(['api.pagar.me/*' => Http::sequence()->pushFailedConnection()]);

        try {
            app(StoneRecebimentoService::class)->iniciarCobrancaDePedido($this->pedidoComTotal(80), $this->formaCredito(), $this->sunmi);
            $this->fail('Deveria lançar resultado desconhecido.');
        } catch (StoneResultadoDesconhecidoException) {
        }

        $this->assertStringContainsString('sem resposta da Stone: falha de conexão', StonePedido::sole()->stp_erro);
    }

    public function test_conciliacao_nunca_reenvia_so_procura_pelo_code(): void
    {
        Http::fake(['api.pagar.me/*' => Http::response(['message' => 'Internal Server Error'], 500)]);
        $this->desconhecida(minutosAtras: 10);

        $this->artisan('stone:conciliar-pedidos')->assertSuccessful();
        $this->artisan('stone:conciliar-pedidos')->assertSuccessful();

        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        Http::assertSentCount(2);
    }

    public function test_conciliacao_para_de_procurar_depois_de_24h(): void
    {
        Http::fake();
        $this->desconhecida(minutosAtras: 25 * 60);

        $this->artisan('stone:conciliar-pedidos')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_aguardando_sem_order_id_nao_encontrado_vira_desconhecido(): void
    {
        $this->fakeStoneAchaPorCodigo();
        $cobranca = $this->desconhecida(['stp_status' => StonePedidoStatus::Aguardando], minutosAtras: 45);

        $this->artisan('stone:conciliar-pedidos')->assertSuccessful();

        $this->assertSame(StonePedidoStatus::Indeterminado, $cobranca->fresh()->stp_status);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_desconhecido_com_order_id_continua_sendo_consultado_depois_de_24h(): void
    {
        Http::fake(['api.pagar.me/core/v5/orders/or_1' => Http::response(['id' => 'or_1', 'status' => 'pending', 'charges' => []], 200)]);
        $this->desconhecida(['stp_order_id' => 'or_1'], minutosAtras: 30 * 60);

        $this->artisan('stone:conciliar-pedidos')->assertSuccessful();

        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_ends_with($request->url(), '/orders/or_1'));
    }

    // ── Informar Order ID (copiado do painel da Pagar.me) ────────────────────

    private function fakePedidoNaStone(array $order, int $status = 200): void
    {
        Http::fake(['api.pagar.me/core/v5/orders/*' => fn ($request) => $request->method() === 'PATCH'
            ? Http::response(['status' => 'canceled'], 200)
            : Http::response($order, $status)]);
    }

    public function test_informar_order_id_conferido_libera_o_cancelamento_pela_tela(): void
    {
        $cobranca = $this->desconhecida();
        $this->fakePedidoNaStone(['id' => 'or_q3ZY033UEJsnLaXK', 'code' => "rf-{$cobranca->id}", 'amount' => 8000, 'status' => 'pending', 'charges' => []]);

        Livewire::test(ManageStonePedidos::class)
            ->assertTableActionHidden('cancelarPedido', $cobranca)
            ->callAction(TestAction::make('informarOrderId')->table($cobranca), ['order_id' => ' or_q3ZY033UEJsnLaXK '])
            ->assertNotified('Order ID gravado.')
            ->assertTableActionVisible('cancelarPedido', $cobranca)
            ->callAction(TestAction::make('cancelarPedido')->table($cobranca));

        $cobranca->refresh();
        $this->assertSame('or_q3ZY033UEJsnLaXK', $cobranca->stp_order_id);
        $this->assertSame(StonePedidoStatus::Cancelado, $cobranca->stp_status);
        Http::assertSent(fn ($request) => $request->method() === 'PATCH' && str_ends_with($request->url(), '/orders/or_q3ZY033UEJsnLaXK/closed'));
    }

    public function test_informar_order_id_recusa_pedido_de_outro_code_ou_outro_valor(): void
    {
        $cobranca = $this->desconhecida();
        $servico = app(StoneRecebimentoService::class);

        foreach ([
            ['code' => 'rf-999999', 'amount' => 8000],
            ['code' => "rf-{$cobranca->id}", 'amount' => 9999],
        ] as $indice => $diferenca) {
            $id = "or_errado000000{$indice}";
            Http::fake(["api.pagar.me/core/v5/orders/{$id}" => Http::response($diferenca + ['id' => $id, 'status' => 'pending'], 200)]);

            try {
                $servico->informarOrderId($cobranca, $id);
                $this->fail('O pedido não confere com a cobrança.');
            } catch (StoneConnectException $e) {
                $this->assertStringContainsString($indice === 0 ? 'code' : 'valor', $e->getMessage());
            }
        }

        $this->assertNull($cobranca->fresh()->stp_order_id);
    }

    public function test_informar_order_id_grava_com_aviso_quando_a_stone_nao_responde(): void
    {
        $cobranca = $this->desconhecida();
        $this->fakePedidoNaStone(['message' => 'Internal Server Error'], 500);

        $conferido = app(StoneRecebimentoService::class)->informarOrderId($cobranca, 'or_semConferencia01');

        $this->assertFalse($conferido);
        $this->assertSame('or_semConferencia01', $cobranca->fresh()->stp_order_id);
        $this->assertSame(StonePedidoStatus::Aguardando, $cobranca->fresh()->stp_status);
    }

    public function test_informar_order_id_recusa_formato_invalido_e_id_ja_usado(): void
    {
        Http::fake();
        $this->desconhecida(['stp_order_id' => 'or_jaUsado000001']);
        $cobranca = $this->desconhecida();
        $servico = app(StoneRecebimentoService::class);

        foreach (['rf-432', 'or_', 'or_abc/closed', 'or_jaUsado000001'] as $invalido) {
            try {
                $servico->informarOrderId($cobranca, $invalido);
                $this->fail("Deveria recusar {$invalido}.");
            } catch (StoneConnectException) {
            }
        }

        $this->assertNull($cobranca->fresh()->stp_order_id);
        Http::assertNothingSent();
    }

    public function test_informar_order_id_so_aparece_sem_order_id_e_exige_permissao(): void
    {
        $semOrder = $this->desconhecida();
        $comOrder = $this->desconhecida(['stp_order_id' => 'or_x']);

        Livewire::test(ManageStonePedidos::class)
            ->assertTableActionVisible('informarOrderId', $semOrder)
            ->assertTableActionHidden('informarOrderId', $comOrder);

        $semPermissao = User::factory()->create(['name_first' => 'Sem']);
        $semPermissao->givePermissionTo(Permission::firstOrCreate(['name' => 'view_any:venda', 'guard_name' => 'web']));
        $this->actingAs($semPermissao);

        Livewire::test(ManageStonePedidos::class)
            ->assertTableActionHidden('informarOrderId', $semOrder);
    }

    public function test_libera_a_cobranca_exigindo_confirmacao_e_registrando_quem_liberou(): void
    {
        Http::fake();
        $cobranca = $this->desconhecida();

        Livewire::test(ManageStonePedidos::class)
            ->callAction(TestAction::make('liberarCobranca')->table($cobranca), ['conferido' => false])
            ->assertHasActionErrors(['conferido']);

        $this->assertSame(StonePedidoStatus::Indeterminado, $cobranca->fresh()->stp_status);

        Livewire::test(ManageStonePedidos::class)
            ->callAction(TestAction::make('liberarCobranca')->table($cobranca), ['conferido' => true])
            ->assertNotified('Cobrança liberada.');

        $cobranca->refresh();
        $this->assertSame(StonePedidoStatus::Cancelado, $cobranca->stp_status);
        $this->assertStringContainsString('Liberada manualmente por Ana', $cobranca->stp_erro);
        Http::assertNothingSent();
    }

    public function test_acao_so_aparece_para_desconhecido_sem_order_id_e_sem_pagamento(): void
    {
        $comOrder = $this->desconhecida(['stp_order_id' => 'or_x']);
        $aguardando = $this->desconhecida(['stp_status' => StonePedidoStatus::Aguardando]);
        $comPagamento = $this->desconhecida(['stp_valor_pago' => 10]);
        $comWebhook = $this->desconhecida();
        StoneWebhook::create([
            'stw_evento' => 'charge.paid',
            'stw_payload' => ['type' => 'charge.paid', 'data' => ['order' => ['code' => "rf-{$comWebhook->id}"]]],
        ]);

        $tela = Livewire::test(ManageStonePedidos::class);

        foreach ([$comOrder, $aguardando, $comPagamento, $comWebhook] as $registro) {
            $tela->assertTableActionHidden('liberarCobranca', $registro);
        }
    }

    public function test_liberar_exige_permissao_de_operar_venda(): void
    {
        $cobranca = $this->desconhecida();
        $semPermissao = User::factory()->create(['name_first' => 'Sem']);
        $semPermissao->givePermissionTo(Permission::firstOrCreate(['name' => 'view_any:venda', 'guard_name' => 'web']));
        $this->actingAs($semPermissao);

        Livewire::test(ManageStonePedidos::class)
            ->assertTableActionHidden('liberarCobranca', $cobranca);
    }

    public function test_pagamento_que_chega_depois_da_liberacao_ainda_e_reconhecido_pelo_code(): void
    {
        $cobranca = $this->desconhecida();
        app(StoneRecebimentoService::class)->liberar($cobranca);

        $achado = app(StoneRecebimentoService::class)->resolverPedido(['id' => 'or_depois', 'code' => "rf-{$cobranca->id}"]);

        $this->assertSame($cobranca->id, $achado?->id);
    }
}
