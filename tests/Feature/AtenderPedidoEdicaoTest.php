<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Filament\Pages\AtenderPedido;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\ItensPedido;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosPedido;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AtenderPedidoEdicaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    private function pedidoComItem(string $status = 'ABERTO'): Pedido
    {
        $categoria = Categoria::create(['categoria_nome' => 'Pizzas']);
        $produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 40.0,
        ]);
        $cliente = Cliente::create(['cliente_nome' => 'Cliente Original', 'cliente_tipo' => 'Física']);

        $pedido = Pedido::create(['pedido_status' => $status, 'pedido_cliente_id' => $cliente->id]);

        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $produto->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 40.0,
            'item_pedido_valor' => 40.0,
            'item_pedido_desconto' => 0,
            'item_pedido_valor_adicionais' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);

        return $pedido;
    }

    public function test_abre_com_itens_e_cliente_do_banco(): void
    {
        $pedido = $this->pedidoComItem();

        Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->assertSet('pedidoId', $pedido->id)
            ->assertSet('clienteData.nome', 'Cliente Original')
            ->assertSet('itensCarrinho.0.valor', 40.0);
    }

    public function test_salvar_atualiza_status_e_pagamentos_sem_duplicar_itens(): void
    {
        $pedido = $this->pedidoComItem();
        $dinheiro = OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_desc_nfe' => 'cash']);

        Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->set('statusSelecionado', 'PREPARANDO')
            ->set('entregaPagamentoData', [
                'opcaoEntregaId' => null,
                'pagamentos' => [
                    ['opcaoPagamentoId' => $dinheiro->id, 'valor' => '40,00', 'trocoPara' => null],
                ],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $pedido->refresh();
        $this->assertSame('PREPARANDO', $pedido->pedido_status);
        $this->assertSame(1, $pedido->item_pedido_pedido_id()->count());
        $this->assertSame(1, $pedido->pagamentosCombinados()->count());
    }

    public function test_salvar_de_novo_substitui_pagamentos_em_vez_de_acumular(): void
    {
        $pedido = $this->pedidoComItem();
        $dinheiro = OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_desc_nfe' => 'cash']);
        $cartao = OpcoesPagamento::create(['opcaopag_nome' => 'Cartão', 'opcaopag_desc_nfe' => 'creditCard']);

        PagamentosPedido::create([
            'pg_pedido_pedido_id' => $pedido->id,
            'pg_pedido_opcaopagamento_id' => $dinheiro->id,
            'pg_pedido_valor' => 40.0,
            'pg_pedido_ordem' => 0,
        ]);

        Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->set('entregaPagamentoData', [
                'opcaoEntregaId' => null,
                'pagamentos' => [
                    ['opcaoPagamentoId' => $cartao->id, 'valor' => '40,00', 'trocoPara' => null],
                ],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $pedido->refresh();
        $this->assertSame(1, $pedido->pagamentosCombinados()->count());
        $this->assertSame($cartao->id, $pedido->pagamentosCombinados()->first()->pg_pedido_opcaopagamento_id);
    }

    public function test_nao_e_possivel_salvar_pedido_finalizado(): void
    {
        $pedido = $this->pedidoComItem('FINALIZADO');

        Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->set('statusSelecionado', 'PREPARANDO')
            ->call('save');

        $pedido->refresh();
        $this->assertSame('FINALIZADO', $pedido->pedido_status);
    }
}
