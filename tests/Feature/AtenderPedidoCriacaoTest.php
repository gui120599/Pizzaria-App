<?php

namespace Tests\Feature;

use App\Enums\PedidoOrigemEnum;
use App\Enums\ProdutoTipoEnum;
use App\Filament\Pages\AtenderPedido;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\OpcoesEntregas;
use App\Models\OpcoesPagamento;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\User;
use App\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AtenderPedidoCriacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    private function produto(float $preco = 50.0): Produto
    {
        $categoria = Categoria::create(['categoria_nome' => 'Pizzas']);

        return Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => $preco,
        ]);
    }

    private function itemCarrinho(Produto $produto, float $quantidade = 1): array
    {
        return [
            'produto_id' => $produto->id,
            'quantidade' => $quantidade,
            'valor_unitario' => (float) $produto->produto_preco_venda,
            'desconto' => 0,
            'valor' => (float) $produto->produto_preco_venda * $quantidade,
            'adicionais_valor' => 0,
            'observacao' => '',
            'adicionais' => [],
        ];
    }

    public function test_cria_pedido_com_frete_persistido_garcom_e_origem_corretos(): void
    {
        $produto = $this->produto(50.0);
        $opcaoEntrega = OpcoesEntregas::create([
            'opcaoentrega_nome' => 'Entrega',
            'opcaoentrega_valor_frete' => 8.0,
            'opcaoentrega_min_valor_frete' => 0,
        ]);
        $dinheiro = OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_desc_nfe' => 'cash']);

        $component = Livewire::test(AtenderPedido::class)
            ->set('itensCarrinho', [$this->itemCarrinho($produto)])
            ->set('clienteData', [
                'semCliente' => false,
                'celular' => '11999998888',
                'nome' => 'João da Silva',
                'enderecoRua' => 'Rua das Flores',
                'enderecoBairro' => 'Centro',
            ])
            ->set('entregaPagamentoData', [
                'opcaoEntregaId' => $opcaoEntrega->id,
                'pagamentos' => [
                    ['opcaoPagamentoId' => $dinheiro->id, 'valor' => '58,00', 'trocoPara' => null],
                ],
            ])
            ->call('save');

        $component->assertHasNoErrors();

        $pedido = Pedido::latest('id')->firstOrFail();

        // Bug 1: frete calculado por TotaisPedido agora é persistido.
        $this->assertEqualsWithDelta(8.0, (float) $pedido->pedido_valor_frete, 0.01);
        $this->assertEqualsWithDelta(58.0, (float) $pedido->pedido_valor_total, 0.01);

        // Bug 2: garçom gravado a partir do usuário autenticado, sem campo hidden órfão.
        $this->assertSame(Auth()->id() ?? auth()->id(), $pedido->pedido_usuario_garcom_id);

        $this->assertSame(PedidoOrigemEnum::ATENDENTE, $pedido->pedido_origem);
        $this->assertSame('João da Silva', $pedido->cliente->cliente_nome);
        $this->assertSame(1, $pedido->item_pedido_pedido_id()->count());

        // Split de pagamento gravado, e nenhuma Venda foi criada — pagamento aqui é só combinado.
        $this->assertSame(1, $pedido->pagamentosCombinados()->count());
        $this->assertSame(0, Venda::count());
    }

    public function test_bloqueia_sem_forma_de_pagamento(): void
    {
        $produto = $this->produto(30.0);

        Livewire::test(AtenderPedido::class)
            ->set('itensCarrinho', [$this->itemCarrinho($produto)])
            ->set('clienteData', ['semCliente' => true])
            ->call('save');

        $this->assertSame(0, Pedido::count());
    }

    public function test_bloqueia_quando_soma_dos_pagamentos_nao_bate_com_o_total(): void
    {
        $produto = $this->produto(30.0);
        $dinheiro = OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_desc_nfe' => 'cash']);

        Livewire::test(AtenderPedido::class)
            ->set('itensCarrinho', [$this->itemCarrinho($produto)])
            ->set('clienteData', ['semCliente' => true])
            ->set('entregaPagamentoData', [
                'opcaoEntregaId' => null,
                'pagamentos' => [
                    ['opcaoPagamentoId' => $dinheiro->id, 'valor' => '10,00', 'trocoPara' => null],
                ],
            ])
            ->call('save');

        $this->assertSame(0, Pedido::count());
    }

    public function test_exige_endereco_quando_opcao_de_entrega_marca_como_obrigatorio(): void
    {
        $produto = $this->produto(30.0);
        $opcaoEntrega = OpcoesEntregas::create([
            'opcaoentrega_nome' => 'Entrega',
            'opcaoentrega_requer_endereco' => true,
        ]);
        $dinheiro = OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_desc_nfe' => 'cash']);

        Livewire::test(AtenderPedido::class)
            ->set('itensCarrinho', [$this->itemCarrinho($produto)])
            ->set('clienteData', ['semCliente' => false, 'celular' => '11999998888', 'nome' => 'Cliente Sem Endereço'])
            ->set('entregaPagamentoData', [
                'opcaoEntregaId' => $opcaoEntrega->id,
                'pagamentos' => [
                    ['opcaoPagamentoId' => $dinheiro->id, 'valor' => '30,00', 'trocoPara' => null],
                ],
            ])
            ->call('save');

        $this->assertSame(0, Pedido::count());
    }

    public function test_pedido_sem_cliente_deixa_pedido_cliente_id_nulo(): void
    {
        $produto = $this->produto(20.0);
        $dinheiro = OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_desc_nfe' => 'cash']);

        Livewire::test(AtenderPedido::class)
            ->set('itensCarrinho', [$this->itemCarrinho($produto)])
            ->set('clienteData', ['semCliente' => true])
            ->set('entregaPagamentoData', [
                'opcaoEntregaId' => null,
                'pagamentos' => [
                    ['opcaoPagamentoId' => $dinheiro->id, 'valor' => '20,00', 'trocoPara' => null],
                ],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $pedido = Pedido::latest('id')->firstOrFail();
        $this->assertNull($pedido->pedido_cliente_id);
    }

    public function test_status_cancelado_nao_esta_disponivel_na_pagina(): void
    {
        $cliente = Cliente::create(['cliente_nome' => 'Alguém', 'cliente_tipo' => 'Física']);
        $pedido = Pedido::create(['pedido_status' => 'ABERTO', 'pedido_cliente_id' => $cliente->id]);

        $component = Livewire::test(AtenderPedido::class, ['pedido' => $pedido]);

        $this->assertArrayNotHasKey('CANCELADO', $component->instance()->getOpcoesStatus());
    }

    public function test_pedido_finalizado_abre_em_modo_somente_leitura(): void
    {
        $cliente = Cliente::create(['cliente_nome' => 'Alguém', 'cliente_tipo' => 'Física']);
        $pedido = Pedido::create(['pedido_status' => 'FINALIZADO', 'pedido_cliente_id' => $cliente->id]);

        Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->assertSet('somenteLeitura', true);
    }
}
