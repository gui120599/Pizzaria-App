<?php

namespace Tests\Feature;

use App\Enums\StonePedidoModo;
use App\Enums\StonePedidoStatus;
use App\Filament\Pages\AtenderPedido;
use App\Filament\Resources\Pedidos\Pages\ListPedidos;
use App\Livewire\MesaStoneCobranca;
use App\Livewire\PainelEntregador;
use App\Models\Categoria;
use App\Models\Maquininha;
use App\Models\OpcoesPagamento;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\StonePedido;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CenarioStone;
use Tests\TestCase;

/**
 * Etapa 4: ações do painel de pedidos e das telas de atendimento conforme a
 * matriz de estados (seção 7.1 do plano), "Salvar e cobrar" e pendência reaberta.
 */
class StoneUxCobrancaTest extends TestCase
{
    use CenarioStone;
    use RefreshDatabase;

    private Maquininha $sunmi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurarStone();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $this->sunmi = $this->maquininhaConnect('Sunmi', '111');
    }

    // ── #9 Matriz de visibilidade no painel ──────────────────────────────────

    /**
     * @return array<string, array{array<string, mixed>, bool, array<int, string>}>
     */
    public static function matriz(): array
    {
        return [
            'Direto aguardando' => [['stp_modo' => StonePedidoModo::Direto], false, ['cancelarStone', 'verCobrancaStone']],
            'Listado aguardando com saldo' => [['stp_modo' => StonePedidoModo::Listado, 'stp_valor_solicitado' => 50], true, ['cancelarStone', 'verCobrancaStone']],
            'Listado aguardando cobrindo tudo' => [['stp_modo' => StonePedidoModo::Listado], false, ['cancelarStone', 'verCobrancaStone']],
            'Pago' => [['stp_status' => StonePedidoStatus::Pago, 'stp_valor_pago' => 80, 'stp_fechado_em' => '2026-10-28 10:00:00'], false, ['verCobrancaStone']],
            'Resultado desconhecido' => [['stp_status' => StonePedidoStatus::Indeterminado, 'stp_order_id' => null], false, ['reconsultarStone', 'verCobrancaStone']],
            'Recusado na criação' => [['stp_status' => StonePedidoStatus::Falha, 'stp_order_id' => null], true, ['verCobrancaStone']],
            'Cancelado' => [['stp_status' => StonePedidoStatus::Cancelado], true, ['verCobrancaStone']],
        ];
    }

    /**
     * @param  array<string, mixed>  $atributos
     * @param  array<int, string>  $visiveis
     */
    #[DataProvider('matriz')]
    public function test_matriz_de_acoes_por_estado_da_cobranca(array $atributos, bool $enviarVisivel, array $visiveis): void
    {
        $pedido = $this->pedidoComTotal(80);
        $this->cobrancaDoPedido($pedido, $this->sunmi, $atributos);

        $tela = Livewire::test(ListPedidos::class);

        $enviarVisivel
            ? $tela->assertActionVisible(TestAction::make('enviarStone')->table($pedido))
            : $tela->assertActionHidden(TestAction::make('enviarStone')->table($pedido));

        foreach (['cancelarStone', 'verCobrancaStone', 'reconsultarStone'] as $acao) {
            in_array($acao, $visiveis, true)
                ? $tela->assertActionVisible(TestAction::make($acao)->table($pedido))
                : $tela->assertActionHidden(TestAction::make($acao)->table($pedido));
        }
    }

    public function test_maquininha_cheia_deixa_o_envio_desabilitado_com_o_motivo(): void
    {
        $this->ocuparVagas($this->sunmi, 30);
        $pedido = $this->pedidoComTotal(80);

        Livewire::test(ListPedidos::class)
            ->assertActionVisible(TestAction::make('enviarStone')->table($pedido))
            ->assertActionDisabled(TestAction::make('enviarStone')->table($pedido));
    }

    public function test_coluna_stone_mostra_o_ultimo_status_da_cobranca(): void
    {
        $pedido = $this->pedidoComTotal(80);
        $this->cobrancaDoPedido($pedido, $this->sunmi, ['stp_status' => StonePedidoStatus::Cancelado]);
        $this->cobrancaDoPedido($pedido, $this->sunmi, ['stp_status' => StonePedidoStatus::Indeterminado, 'stp_order_id' => null]);

        Livewire::test(ListPedidos::class)
            ->assertTableColumnStateSet('stone', 'indeterminado', $pedido);
    }

    public function test_reconsultar_pelo_painel_reenvia_o_mesmo_registro_e_tira_do_indeterminado(): void
    {
        $this->fakeStoneCriaPedidos('or_rec');
        $pedido = $this->pedidoComTotal(80);
        $stonePedido = $this->cobrancaDoPedido($pedido, $this->sunmi, ['stp_status' => StonePedidoStatus::Indeterminado, 'stp_order_id' => null]);

        Livewire::test(ListPedidos::class)
            ->callAction(TestAction::make('reconsultarStone')->table($pedido))
            ->assertNotified();

        $this->assertSame('aguardando', $stonePedido->fresh()->stp_status->value);
        $this->assertSame(1, StonePedido::count());
    }

    public function test_ver_cobranca_mostra_ids_valores_e_historico(): void
    {
        $pedido = $this->pedidoComTotal(80);
        $stonePedido = $this->cobrancaDoPedido($pedido, $this->sunmi, ['stp_order_id' => 'or_visivel', 'stp_charge_id' => 'ch_visivel']);

        $html = view('filament.resources.pedidos.cobrancas-stone', [
            'cobrancas' => $stonePedido->newQuery()->whereKey($stonePedido->id)->with(['maquininha', 'opcaoPagamento', 'webhooks'])->get(),
        ])->render();

        $this->assertStringContainsString('or_visivel', $html);
        $this->assertStringContainsString('Sunmi', $html);
        $this->assertStringContainsString('R$ 80,00', $html);
    }

    public function test_lote_que_nao_cabe_nas_vagas_nao_envia_nenhum_pelo_painel(): void
    {
        $this->ocuparVagas($this->sunmi, 28);
        $pedidos = collect([$this->pedidoComTotal(40), $this->pedidoComTotal(50), $this->pedidoComTotal(60)]);

        Livewire::test(ListPedidos::class)
            ->selectTableRecords($pedidos->pluck('id')->all())
            ->callAction(TestAction::make('enviarStoneBulk')->table()->bulk(), ['maquininha_id' => $this->sunmi->id])
            ->assertNotified('Nenhum pedido foi enviado');

        Http::assertNothingSent();
        $this->assertSame(28, StonePedido::count());
    }

    public function test_lote_que_cabe_envia_todos_pelo_painel(): void
    {
        $this->fakeStoneCriaPedidos('or_1', 'or_2');
        $pedidos = collect([$this->pedidoComTotal(40), $this->pedidoComTotal(50)]);

        Livewire::test(ListPedidos::class)
            ->selectTableRecords($pedidos->pluck('id')->all())
            ->callAction(TestAction::make('enviarStoneBulk')->table()->bulk(), ['maquininha_id' => $this->sunmi->id])
            ->assertNotified();

        Http::assertSentCount(2);
        $this->assertSame(2, StonePedido::count());
    }

    // ── Atendimento: Salvar e cobrar ─────────────────────────────────────────

    private function formularioNovoPedido(): Testable
    {
        $produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Pizzas'])->id,
            'produto_tipo' => 'produzido',
            'produto_preco_venda' => 50.0,
        ]);
        $dinheiro = OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_desc_nfe' => 'cash']);

        return Livewire::test(AtenderPedido::class)
            ->set('itensCarrinho', [[
                'produto_id' => $produto->id, 'quantidade' => 1, 'valor_unitario' => 50.0, 'desconto' => 0, 'valor' => 50.0,
                'adicionais_valor' => 0, 'observacao' => '', 'adicionais' => [],
            ]])
            ->set('clienteData', ['semCliente' => true])
            ->set('entregaPagamentoData', ['opcaoEntregaId' => null, 'pagamentos' => [
                ['opcaoPagamentoId' => $dinheiro->id, 'valor' => '50,00', 'trocoPara' => null],
            ]]);
    }

    public function test_salvar_e_cobrar_grava_o_pedido_e_abre_o_modal_sem_duplicar_no_duplo_clique(): void
    {
        $tela = $this->formularioNovoPedido()
            ->call('salvarECobrar')
            ->assertSet('modalStoneAberta', true)
            ->assertSet('stoneStatusModal', 'form');

        $this->assertSame(1, Pedido::count());
        $tela->call('salvarECobrar'); // segundo clique: trata como edição
        $this->assertSame(1, Pedido::count());
    }

    public function test_salvar_e_cobrar_nao_abre_o_modal_se_a_validacao_falhar(): void
    {
        $this->formularioNovoPedido()
            ->set('itensCarrinho', [])
            ->call('salvarECobrar')
            ->assertSet('modalStoneAberta', false);

        $this->assertSame(0, Pedido::count());
    }

    public function test_formulario_alterado_troca_cobrar_por_salvar_e_cobrar_e_nao_cobra_o_valor_antigo(): void
    {
        $pedido = $this->pedidoComTotal(80);
        $this->combinarPagamento($pedido, $this->formaCredito());

        $tela = Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->assertSet('formularioSujo', false)
            ->assertSee('Cobrar na maquininha')
            ->set('observacao', 'mudou')
            ->assertSet('formularioSujo', true)
            ->assertSee('Salvar e cobrar')
            ->call('abrirModalStone')
            ->assertSet('modalStoneAberta', false);

        $this->assertSame(0, StonePedido::count());
        Http::assertNothingSent();
    }

    public function test_atendimento_reabre_direto_em_aguardando_quando_ha_cobranca_pendente(): void
    {
        $pedido = $this->pedidoComTotal(80);
        $stonePedido = $this->cobrancaDoPedido($pedido, $this->sunmi, ['stp_modo' => StonePedidoModo::Direto]);

        Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->assertSee('Acompanhar cobrança na maquininha')
            ->call('abrirModalStone')
            ->assertSet('modalStoneAberta', true)
            ->assertSet('stoneStatusModal', 'aguardando')
            ->assertSet('stonePedidoId', $stonePedido->id);
    }

    public function test_atendimento_reabre_resultado_desconhecido_para_reconsultar(): void
    {
        $this->fakeStoneCriaPedidos('or_rec');
        $pedido = $this->pedidoComTotal(80);
        $stonePedido = $this->cobrancaDoPedido($pedido, $this->sunmi, ['stp_status' => StonePedidoStatus::Indeterminado, 'stp_order_id' => null]);

        Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->call('abrirModalStone')
            ->assertSet('stoneStatusModal', 'indeterminado')
            ->call('reconsultarCobrancaStone')
            ->assertSet('stoneStatusModal', 'aguardando');

        $this->assertSame('or_rec', $stonePedido->fresh()->stp_order_id);
    }

    // ── Entregador e mesa reabrem a pendência ────────────────────────────────

    public function test_entregador_reabre_a_cobranca_pendente_em_vez_de_oferecer_outro_envio(): void
    {
        $entregador = User::factory()->create(['name_first' => 'Entregador']);
        $pedido = $this->pedidoComTotal(50, 'EM TRANSPORTE');
        $pedido->update(['pedido_usuario_entrega_id' => $entregador->id]);
        $stonePedido = $this->cobrancaDoPedido($pedido, $this->sunmi);
        $this->actingAs($entregador);

        Livewire::test(PainelEntregador::class)
            ->call('abrirModalStone', $pedido->id)
            ->assertSet('modalStoneAberta', true)
            ->assertSet('stoneStatusModal', 'aguardando')
            ->assertSet('stonePedidoId', $stonePedido->id);
    }

    public function test_mesa_avulsa_reabre_a_cobranca_pendente_e_cobra_so_o_devido(): void
    {
        $this->fakeStoneCriaPedidos('or_1');
        $pedido = $this->pedidoComTotal(100, 'PRONTO');
        $stonePedido = $this->cobrancaDoPedido($pedido, $this->sunmi, ['stp_valor_solicitado' => 60]);

        $tela = Livewire::test(MesaStoneCobranca::class, ['pedidoId' => $pedido->id])
            ->call('abrirModal')
            ->assertSet('status', 'aguardando')
            ->assertSet('stonePedidoId', $stonePedido->id);

        $this->assertSame(40.0, $tela->instance()->totalAberto);
    }
}
