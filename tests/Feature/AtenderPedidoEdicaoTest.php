<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Filament\Pages\AtenderPedido;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\ItensPedido;
use App\Models\OpcoesEntregas;
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
            ->assertHasNoErrors()
            ->assertRedirect(AtenderPedido::getUrl());

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

    /**
     * Pedido de entrega cujo endereço gravado difere do cadastro atual do
     * cliente — o caso dos pedidos 59406/57377: cadastro mudou (ou nunca
     * recebeu) o endereço desta entrega.
     */
    private function pedidoDeEntregaComCadastroDiferente(): Pedido
    {
        $pedido = $this->pedidoComItem();
        $pedido->cliente->update([
            'cliente_endereco' => 'Rua do Cadastro',
            'cliente_numero_endereco' => '10',
            'cliente_bairro' => 'Bairro do Cadastro',
        ]);

        $entrega = OpcoesEntregas::create([
            'opcaoentrega_nome' => 'Entregar',
            'opcaoentrega_requer_endereco' => true,
        ]);

        $pedido->update([
            'pedido_opcaoentrega_id' => $entrega->id,
            'pedido_endereco_entrega' => 'Rua do Pedido, Qd 4, Lt 3, perto da igreja',
        ]);

        return $pedido;
    }

    /** @return array{opcaoEntregaId: int, pagamentos: array<int, array<string, mixed>>} */
    private function entregaPagaEmDinheiro(Pedido $pedido): array
    {
        $dinheiro = OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_desc_nfe' => 'cash']);

        return [
            'opcaoEntregaId' => $pedido->pedido_opcaoentrega_id,
            'pagamentos' => [
                ['opcaoPagamentoId' => $dinheiro->id, 'valor' => '40,00', 'trocoPara' => null],
            ],
        ];
    }

    public function test_abre_com_endereco_gravado_no_pedido_e_nao_com_o_do_cadastro(): void
    {
        $pedido = $this->pedidoDeEntregaComCadastroDiferente();

        Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->assertSet('clienteData.enderecoEntrega', 'Rua do Pedido, Qd 4, Lt 3, perto da igreja')
            ->assertSet('clienteData.enderecoRua', '')
            ->assertSet('clienteData.enderecoBairro', '');
    }

    public function test_salvar_sem_mexer_no_endereco_preserva_o_endereco_do_pedido(): void
    {
        $pedido = $this->pedidoDeEntregaComCadastroDiferente();

        Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->set('statusSelecionado', 'PREPARANDO')
            ->set('entregaPagamentoData', $this->entregaPagaEmDinheiro($pedido))
            ->call('save')
            ->assertRedirect(AtenderPedido::getUrl());

        $pedido->refresh();
        $this->assertSame('PREPARANDO', $pedido->pedido_status);
        $this->assertSame('Rua do Pedido, Qd 4, Lt 3, perto da igreja', $pedido->pedido_endereco_entrega);
    }

    public function test_endereco_alterado_na_edicao_vai_para_o_pedido_sem_mexer_no_cadastro(): void
    {
        $pedido = $this->pedidoDeEntregaComCadastroDiferente();

        Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->set('clienteData.enderecoEntrega', '  Rua Nova, 99, Centro  ')
            ->set('entregaPagamentoData', $this->entregaPagaEmDinheiro($pedido))
            ->call('save')
            ->assertRedirect(AtenderPedido::getUrl());

        $this->assertSame('Rua Nova, 99, Centro', $pedido->fresh()->pedido_endereco_entrega);
        $this->assertSame('Rua do Cadastro', $pedido->cliente->fresh()->cliente_endereco);
    }

    public function test_correcao_de_endereco_feita_em_outra_tela_nao_e_desfeita_ao_salvar(): void
    {
        $pedido = $this->pedidoDeEntregaComCadastroDiferente();

        $tela = Livewire::test(AtenderPedido::class, ['pedido' => $pedido]);

        $pedido->update(['pedido_endereco_entrega' => 'Endereço corrigido pelo WhatsApp']);

        $tela->set('entregaPagamentoData', $this->entregaPagaEmDinheiro($pedido))
            ->call('save')
            ->assertRedirect(AtenderPedido::getUrl());

        $this->assertSame('Endereço corrigido pelo WhatsApp', $pedido->fresh()->pedido_endereco_entrega);
    }

    public function test_entrega_com_endereco_apagado_nao_salva(): void
    {
        $pedido = $this->pedidoDeEntregaComCadastroDiferente();

        Livewire::test(AtenderPedido::class, ['pedido' => $pedido])
            ->set('statusSelecionado', 'PREPARANDO')
            ->set('clienteData.enderecoEntrega', '   ')
            ->set('entregaPagamentoData', $this->entregaPagaEmDinheiro($pedido))
            ->call('save')
            ->assertNotified('Não foi possível salvar')
            ->assertNoRedirect();

        $pedido->refresh();
        $this->assertSame('ABERTO', $pedido->pedido_status);
        $this->assertSame('Rua do Pedido, Qd 4, Lt 3, perto da igreja', $pedido->pedido_endereco_entrega);
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
