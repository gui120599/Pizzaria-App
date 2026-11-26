<?php

namespace Tests\Feature;

use App\Enums\StonePedidoStatus;
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
 * A Stone devolve 500 na criação e em toda consulta: a cobrança fica como
 * resultado desconhecido, sem Order ID, travando a venda. O motivo aparece na
 * tela, o reenvio automático tem prazo e há uma liberação manual auditada.
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

    public function test_conciliacao_reenvia_dentro_do_prazo_e_para_depois_dele(): void
    {
        Http::fake(['api.pagar.me/*' => Http::response(['message' => 'Internal Server Error'], 500)]);
        $recente = $this->desconhecida(minutosAtras: 10);
        $this->desconhecida(minutosAtras: 45);

        $this->artisan('stone:conciliar-pedidos')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->header('Idempotency-Key')[0] === "stone-pedido-{$recente->id}");
    }

    public function test_prazo_de_reenvio_e_configuravel(): void
    {
        Http::fake(['api.pagar.me/*' => Http::response(['message' => 'erro'], 500)]);
        $this->desconhecida(minutosAtras: 45);

        $this->artisan('stone:conciliar-pedidos', ['--minutos-reenvio' => 60])->assertSuccessful();

        Http::assertSentCount(1);
    }

    public function test_aguardando_sem_order_id_fora_do_prazo_vira_desconhecido_sem_novo_envio(): void
    {
        Http::fake();
        $cobranca = $this->desconhecida(['stp_status' => StonePedidoStatus::Aguardando], minutosAtras: 45);

        $this->artisan('stone:conciliar-pedidos')->assertSuccessful();

        $this->assertSame(StonePedidoStatus::Indeterminado, $cobranca->fresh()->stp_status);
        $this->assertStringContainsString('Liberar cobrança', $cobranca->fresh()->stp_erro);
        Http::assertNothingSent();
    }

    public function test_desconhecido_com_order_id_continua_sendo_consultado_depois_do_prazo(): void
    {
        Http::fake(['api.pagar.me/*' => Http::response(['id' => 'or_1', 'status' => 'pending', 'charges' => []], 200)]);
        $this->desconhecida(['stp_order_id' => 'or_1'], minutosAtras: 600);

        $this->artisan('stone:conciliar-pedidos')->assertSuccessful();

        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_ends_with($request->url(), '/orders/or_1'));
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
