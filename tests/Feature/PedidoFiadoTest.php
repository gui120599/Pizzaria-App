<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Enums\StatusPedidoEnum;
use App\Exceptions\FiadoNaoPermitidoException;
use App\Filament\Garcom\Pages\AtenderRetirada;
use App\Filament\Pages\AtenderPedido;
use App\Filament\Pages\OperarVenda;
use App\Livewire\EntregaPagamentoPicker;
use App\Models\Caixa;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\ItensPedido;
use App\Models\Lancamento;
use App\Models\OpcoesEntregas;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Services\Garcom\RetiradaService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pedido fiado / a prazo no atendimento (Painel do Garçom e Atender Pedido):
 * sem forma de pagamento, só para cliente com crédito, e o Nova Venda já abre
 * a venda como fiado.
 */
class PedidoFiadoTest extends TestCase
{
    use RefreshDatabase;

    private Produto $produto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Pizzas'])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 50.00,
        ]);
        OpcoesEntregas::create(['opcaoentrega_nome' => 'Retirada', 'opcaoentrega_valor_frete' => 0, 'opcaoentrega_min_valor_frete' => 0, 'opcaoentrega_requer_endereco' => false]);
    }

    private function cliente(?float $limite, ?string $celular = null): Cliente
    {
        return Cliente::create([
            'cliente_nome' => 'Maria Fiado',
            'cliente_tipo' => 'PF',
            'cliente_celular' => $celular,
            'cliente_limite_credito' => $limite,
        ]);
    }

    private function item(Pedido $pedido): ItensPedido
    {
        return ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $this->produto->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 50.00,
            'item_pedido_valor' => 50.00,
            'item_pedido_desconto' => 0,
            'item_pedido_valor_adicionais' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);
    }

    private function rascunhoDoGarcom(): Pedido
    {
        $rascunho = app(RetiradaService::class)->rascunho(User::factory()->garcom()->create(['name_first' => 'Ana']));
        $this->item($rascunho);

        return $rascunho;
    }

    // ── Painel do Garçom: retirada e entrega ────────────────────────────────

    public function test_retirada_fiado_de_cliente_escolhido_sem_celular_envia_sem_pagamento(): void
    {
        $cliente = $this->cliente(limite: 200);
        $rascunho = $this->rascunhoDoGarcom();

        $this->assertTrue(app(RetiradaService::class)->enviar($rascunho, 'Maria Fiado', '', fiado: true, clienteId: $cliente->id));

        $pedido = $rascunho->fresh();
        $this->assertSame(StatusPedidoEnum::ABERTO->value, $pedido->pedido_status);
        $this->assertSame($cliente->id, $pedido->pedido_cliente_id);
        $this->assertTrue($pedido->pedido_fiado);
        $this->assertSame('Fiado / a prazo', $pedido->pedido_descricao_pagamento);
        $this->assertSame(0, $pedido->pagamentosCombinados()->count());
        $this->assertSame(1, Cliente::count());
    }

    public function test_retirada_fiado_e_recusada_sem_limite_ou_com_credito_menor_que_o_total(): void
    {
        foreach ([null, 30.0] as $limite) {
            $cliente = $this->cliente(limite: $limite);
            $rascunho = $this->rascunhoDoGarcom();

            try {
                app(RetiradaService::class)->enviar($rascunho, 'Maria Fiado', '', fiado: true, clienteId: $cliente->id);
                $this->fail('Fiado sem crédito suficiente deveria ser recusado.');
            } catch (FiadoNaoPermitidoException) {
            }

            $this->assertSame(StatusPedidoEnum::INICIADO->value, $rascunho->fresh()->pedido_status);
            $this->assertFalse((bool) $rascunho->fresh()->pedido_fiado);
        }
    }

    public function test_entrega_fiado_dispensa_forma_de_pagamento(): void
    {
        $cliente = $this->cliente(limite: 200, celular: '64999991234');
        $delivery = OpcoesEntregas::create(['opcaoentrega_nome' => 'Delivery', 'opcaoentrega_valor_frete' => 8, 'opcaoentrega_min_valor_frete' => 0, 'opcaoentrega_requer_endereco' => true]);
        $rascunho = $this->rascunhoDoGarcom();

        app(RetiradaService::class)->enviarEntrega(
            $rascunho,
            ['clienteId' => $cliente->id, 'nome' => 'Maria Fiado', 'celular' => '64999991234', 'enderecoRua' => 'Rua A', 'enderecoBairro' => 'Centro'],
            ['opcaoEntregaId' => $delivery->id, 'pagamentos' => [], 'fiado' => true],
        );

        $pedido = $rascunho->fresh();
        $this->assertTrue($pedido->pedido_fiado);
        $this->assertEquals(58.00, $pedido->pedido_valor_total);
        $this->assertSame(0, $pedido->pagamentosCombinados()->count());
    }

    public function test_tela_da_retirada_busca_cliente_pelo_nome_e_vincula_ao_escolher(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('garcom'));
        $this->actingAs(User::factory()->garcom()->create(['name_first' => 'Ana']));
        $cliente = $this->cliente(limite: 200);
        Cliente::create(['cliente_nome' => 'Outro Nome', 'cliente_tipo' => 'PF']);

        $tela = Livewire::test(AtenderRetirada::class)
            ->set('buscaCliente', 'maria');

        $this->assertSame([$cliente->id], $tela->instance()->resultadosBuscaCliente()->pluck('id')->all());

        $tela->call('selecionarCliente', $cliente->id)
            ->assertSet('clienteId', $cliente->id)
            ->assertSet('nome', 'Maria Fiado')
            ->assertSet('buscaClienteAberta', false);
    }

    public function test_botao_fiado_avisa_a_tela_com_o_estado(): void
    {
        Livewire::test(EntregaPagamentoPicker::class, ['total' => 50])
            ->call('alternarFiado')
            ->assertSet('fiado', true)
            ->assertDispatched('pedido-entrega-pagamento-atualizado', fn (string $evento, array $params): bool => $params['dados']['fiado'] === true);
    }

    // ── Atender Pedido (admin) ──────────────────────────────────────────────

    private function atenderPedidoFiado(array $clienteData): Testable
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        return Livewire::test(AtenderPedido::class)
            ->set('itensCarrinho', [[
                'produto_id' => $this->produto->id,
                'quantidade' => 1,
                'valor_unitario' => 50.0,
                'desconto' => 0,
                'valor' => 50.0,
                'adicionais_valor' => 0,
                'observacao' => '',
                'adicionais' => [],
            ]])
            ->set('clienteData', $clienteData)
            ->set('entregaPagamentoData', [
                'opcaoEntregaId' => OpcoesEntregas::firstOrFail()->id,
                'pagamentos' => [['opcaoPagamentoId' => null, 'valor' => '0,00', 'trocoPara' => null]],
                'fiado' => true,
            ])
            ->call('save');
    }

    public function test_atender_pedido_fiado_salva_sem_forma_de_pagamento_para_cliente_com_credito(): void
    {
        $cliente = $this->cliente(limite: 200);

        $this->atenderPedidoFiado(['semCliente' => false, 'clienteId' => $cliente->id, 'nome' => 'Maria Fiado', 'celular' => ''])
            ->assertHasNoErrors();

        $pedido = Pedido::sole();
        $this->assertTrue($pedido->pedido_fiado);
        $this->assertSame($cliente->id, $pedido->pedido_cliente_id);
        $this->assertSame('Fiado / a prazo', $pedido->pedido_descricao_pagamento);
    }

    public function test_atender_pedido_fiado_e_recusado_sem_limite_ou_sem_cliente(): void
    {
        $cliente = $this->cliente(limite: null);

        $this->atenderPedidoFiado(['semCliente' => false, 'clienteId' => $cliente->id, 'nome' => 'Maria Fiado', 'celular' => ''])
            ->assertNotified('Fiado / a prazo');
        $this->atenderPedidoFiado(['semCliente' => true]);

        $this->assertSame(0, Pedido::count());
    }

    // ── Nova Venda ──────────────────────────────────────────────────────────

    public function test_nova_venda_ja_marca_fiado_ao_lancar_pedido_fiado(): void
    {
        $usuario = User::factory()->admin()->create(['name_first' => 'Caixa']);
        $this->actingAs($usuario);
        SessaoCaixa::create([
            'sessaocaixa_caixa_id' => Caixa::create(['caixa_nome' => 'Caixa 1'])->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => $usuario->id,
        ]);
        $cliente = $this->cliente(limite: 200);
        $fiado = Pedido::create(['pedido_status' => 'ENTREGUE', 'pedido_cliente_id' => $cliente->id, 'pedido_fiado' => true]);
        $normal = Pedido::create(['pedido_status' => 'ENTREGUE', 'pedido_cliente_id' => $cliente->id]);
        $this->item($fiado);
        $this->item($normal);

        Livewire::test(OperarVenda::class)
            ->call('lancarPedidoAvulso', $normal->id)
            ->assertSet('vendaFiado', false)
            ->call('lancarPedidoAvulso', $fiado->id)
            ->assertSet('vendaFiado', true)
            ->call('finalizarVenda')
            ->assertHasNoErrors();

        $this->assertSame($cliente->id, Lancamento::sole()->cliente_id);
        $this->assertSame(100.0, (float) Lancamento::sole()->valor);
    }
}
