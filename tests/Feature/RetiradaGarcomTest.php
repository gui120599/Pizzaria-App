<?php

namespace Tests\Feature;

use App\Enums\PedidoOrigemEnum;
use App\Enums\ProdutoTipoEnum;
use App\Enums\StatusPedidoEnum;
use App\Filament\Garcom\Pages\AtenderRetirada;
use App\Filament\Garcom\Pages\MapaMesas;
use App\Filament\Pages\PainelPedidos;
use App\Livewire\MesaStoneCobranca;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\ItensPedido;
use App\Models\Maquininha;
use App\Models\OpcoesEntregas;
use App\Models\OpcoesPagamento;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\StonePedido;
use App\Models\User;
use App\Services\Garcom\AtendimentoMesaService;
use App\Services\Garcom\RetiradaService;
use Database\Seeders\PermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RetiradaGarcomTest extends TestCase
{
    use RefreshDatabase;

    private RetiradaService $service;

    private User $garcom;

    private Produto $produto;

    private OpcoesEntregas $retirada;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('garcom'));

        $this->service = app(RetiradaService::class);
        $this->garcom = User::factory()->garcom()->create(['name_first' => 'Ana']);
        $this->produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Pizzas'])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 50.00,
        ]);
        OpcoesEntregas::create(['opcaoentrega_nome' => 'Delivery', 'opcaoentrega_valor_frete' => 8, 'opcaoentrega_min_valor_frete' => 0, 'opcaoentrega_requer_endereco' => true]);
        $this->retirada = OpcoesEntregas::create(['opcaoentrega_nome' => 'Retirada', 'opcaoentrega_valor_frete' => 0, 'opcaoentrega_min_valor_frete' => 0, 'opcaoentrega_requer_endereco' => false]);
    }

    private function item(Pedido $pedido, float $valor = 50.00): ItensPedido
    {
        return ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $this->produto->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => $valor,
            'item_pedido_valor' => $valor,
            'item_pedido_desconto' => 0,
            'item_pedido_valor_adicionais' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);
    }

    private function retiradaEnviada(int $itens = 1): Pedido
    {
        $rascunho = $this->service->rascunho($this->garcom);

        for ($i = 0; $i < $itens; $i++) {
            $this->item($rascunho);
        }

        $this->service->enviar($rascunho, 'João Silva', '(64) 99999-1234');

        return $rascunho->fresh();
    }

    public function test_cada_garcom_tem_seu_rascunho_de_retirada(): void
    {
        $colega = User::factory()->garcom()->create(['name_first' => 'Bia']);

        $meu = $this->service->rascunho($this->garcom);

        $this->assertSame($meu->id, $this->service->rascunho($this->garcom)->id);
        $this->assertNotSame($meu->id, $this->service->rascunho($colega)->id);
        $this->assertNull($meu->pedido_sessao_mesa_id);
        $this->assertSame(PedidoOrigemEnum::GARCOM, $meu->pedido_origem);
    }

    public function test_envio_exige_nome_e_celular(): void
    {
        $rascunho = $this->service->rascunho($this->garcom);
        $this->item($rascunho);

        foreach ([['', '64999991234'], ['João', '9999']] as [$nome, $celular]) {
            try {
                $this->service->enviar($rascunho, $nome, $celular);
                $this->fail('Envio sem nome/celular válido deveria ser recusado.');
            } catch (RuntimeException) {
            }
        }

        $this->assertSame(StatusPedidoEnum::INICIADO->value, $rascunho->fresh()->pedido_status);
    }

    public function test_envio_vincula_cliente_abre_o_pedido_e_reenvio_e_inofensivo(): void
    {
        $existente = Cliente::create(['cliente_nome' => 'João', 'cliente_celular' => '64999991234', 'cliente_tipo' => 'Física']);
        $rascunho = $this->service->rascunho($this->garcom);
        $this->item($rascunho, 50.00);
        $this->item($rascunho, 14.00);

        $this->assertTrue($this->service->enviar($rascunho, 'João Silva', '(64) 99999-1234'));
        $this->assertFalse($this->service->enviar($rascunho, 'João Silva', '(64) 99999-1234'));

        $pedido = $rascunho->fresh();
        $this->assertSame(StatusPedidoEnum::ABERTO->value, $pedido->pedido_status);
        $this->assertSame($existente->id, $pedido->pedido_cliente_id);
        $this->assertSame('João Silva', $existente->fresh()->cliente_nome);
        $this->assertSame($this->retirada->id, $pedido->pedido_opcaoentrega_id);
        $this->assertEquals(64.00, $pedido->pedido_valor_total);
        $this->assertFalse($pedido->exigeEntrega());
    }

    public function test_entregar_ao_cliente_e_finalizado_se_ja_pago(): void
    {
        $this->actingAs($this->garcom);
        $pedido = $this->retiradaEnviada();
        $pedido->update(['pedido_status' => StatusPedidoEnum::PRONTO->value]);

        Livewire::test(AtenderRetirada::class, ['pedido' => $pedido->id])
            ->assertSee('Entregue ao cliente')
            ->call('entregarAoCliente');
        $this->assertSame(StatusPedidoEnum::ENTREGUE->value, $pedido->fresh()->pedido_status);

        $paga = $this->retiradaEnviada();
        $paga->update(['pedido_status' => StatusPedidoEnum::PRONTO->value, 'pedido_datahora_finalizado' => now()]);
        $this->service->marcarEntregue($paga, $this->garcom);
        $this->assertSame(StatusPedidoEnum::FINALIZADO->value, $paga->fresh()->pedido_status);
    }

    public function test_cancelar_item_da_retirada_com_pin_de_gerente(): void
    {
        $gerente = User::factory()->gerente()->comPin('4321')->create(['name_first' => 'Gerente']);
        $pedido = $this->retiradaEnviada(itens: 2);

        app(AtendimentoMesaService::class)->cancelarItem(
            $pedido->item_pedido_pedido_id()->first(), $this->garcom, $gerente->id, '4321', 'Desistiu',
        );

        $this->assertEquals(50.00, $pedido->fresh()->pedido_valor_total);
    }

    public function test_tela_nova_retirada_envia_e_vai_para_o_acompanhamento(): void
    {
        $this->actingAs($this->garcom);

        $tela = Livewire::test(AtenderRetirada::class)
            ->set('celular', '(64) 98888-7777')
            ->set('nome', 'Maria');
        $rascunho = Pedido::findOrFail($tela->get('rascunhoId'));
        $this->item($rascunho);

        $tela->call('enviar')->assertRedirect(AtenderRetirada::getUrl(['pedido' => $rascunho->id]));

        $this->assertSame(StatusPedidoEnum::ABERTO->value, $rascunho->fresh()->pedido_status);
        $this->assertSame('Maria', $rascunho->fresh()->cliente->cliente_nome);
    }

    public function test_aba_retiradas_lista_e_avisa_quando_fica_pronta(): void
    {
        $this->actingAs($this->garcom);
        $pedido = $this->retiradaEnviada();

        $mapa = Livewire::test(MapaMesas::class)
            ->set('tipo', 'RETIRADA')
            ->assertSee('João Silva')
            ->assertSee('Nova retirada');

        $pedido->update(['pedido_status' => StatusPedidoEnum::PRONTO->value]);

        $mapa->call('verificarProntas')->assertNotified('Pedido pronto!');
    }

    public function test_cobranca_stone_da_retirada_envia_o_valor_do_pedido(): void
    {
        $this->actingAs($this->garcom);
        config([
            'services.stone.secret_key' => 'sk_test_abc',
            'services.stone.service_referer_name' => 'ref-123',
            'services.stone.base_url' => 'https://api.pagar.me/core/v5',
        ]);
        Http::preventStrayRequests();
        Http::fake(['api.pagar.me/*' => Http::response(['id' => 'or_ret', 'code' => 'RET1'], 200)]);
        OpcoesPagamento::create(['opcaopag_nome' => 'Cartão Stone', 'opcaopag_desc_nfe' => 'creditCard', 'opcaopag_tipo_taxa' => 'N/A', 'opcaopag_valor_percentual_taxa' => 0, 'opcaopag_stone_integrada' => true]);
        $maquininha = Maquininha::create(['nome' => 'Salão', 'operadora' => 'stone', 'numero_serie' => '777']);
        $pedido = $this->retiradaEnviada();

        Livewire::test(MesaStoneCobranca::class, ['pedidoId' => $pedido->id])
            ->assertSet('totalAberto', 50.0)
            ->call('abrirModal')
            ->set('maquininhaId', $maquininha->id)
            ->call('enviarCobranca')
            ->assertSet('status', 'aguardando');

        $stonePedido = StonePedido::sole();
        $this->assertSame($pedido->id, $stonePedido->stp_pedido_id);
        $this->assertSame(50.0, (float) $stonePedido->stp_valor_solicitado);
    }

    public function test_retirada_enviada_entra_na_auto_impressao_da_cozinha(): void
    {
        $this->seed(PermissionSeeder::class);
        $gerente = User::factory()->create(['name_first' => 'Gerente']);
        $gerente->assignRole('Gerente');
        $gerente->givePermissionTo(Permission::firstOrCreate(['name' => 'view_any:pedido', 'guard_name' => 'web']));
        $this->actingAs($gerente);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $painel = Livewire::test(PainelPedidos::class);
        Carbon::setTestNow(Carbon::now()->addSeconds(5));
        $pedido = $this->retiradaEnviada();

        $painel->call('atualizar')
            ->assertDispatched('imprimir-rodadas-mesa', urls: [route('pedido.imprimir', ['id' => $pedido->id])]);

        Carbon::setTestNow();
    }
}
