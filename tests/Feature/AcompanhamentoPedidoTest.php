<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Livewire\AcompanhamentoPedido;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\ItensPedido;
use App\Models\OpcoesEntregas;
use App\Models\Pedido;
use App\Models\Produto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/** Link assinado mostra o pedido; sem assinatura (links antigos) só o status. */
class AcompanhamentoPedidoTest extends TestCase
{
    use RefreshDatabase;

    private function pedido(string $status = 'PREPARANDO', bool $entrega = true): Pedido
    {
        $opcao = OpcoesEntregas::create([
            'opcaoentrega_nome' => $entrega ? 'Entrega' : 'Retirada',
            'opcaoentrega_requer_endereco' => $entrega,
            'opcaoentrega_valor_frete' => 0,
            'opcaoentrega_min_valor_frete' => 0,
        ]);

        $pedido = Pedido::create([
            'pedido_status' => $status,
            'pedido_cliente_id' => Cliente::create(['cliente_nome' => 'Maria Silva', 'cliente_tipo' => 'PF'])->id,
            'pedido_opcaoentrega_id' => $opcao->id,
            'pedido_endereco_entrega' => $entrega ? 'Rua das Flores, 123' : null,
            'pedido_descricao_pagamento' => 'Cartão de crédito',
            'pedido_valor_itens' => 55,
            'pedido_valor_total' => 55,
        ]);

        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => Produto::create([
                'produto_descricao' => 'Pizza Calabresa Especial',
                'produto_categoria_id' => Categoria::firstOrCreate(['categoria_nome' => 'Pizzas'])->id,
                'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
                'produto_preco_venda' => 55,
            ])->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 55,
            'item_pedido_valor' => 55,
            'item_pedido_desconto' => 0,
            'item_pedido_valor_adicionais' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);

        return $pedido;
    }

    public function test_link_do_sistema_e_assinado(): void
    {
        $pedido = $this->pedido();

        $link = $pedido->linkAcompanhamento();

        $this->assertStringContainsString('signature=', $link);
        $this->assertStringContainsString('/acompanhar/'.$pedido->id, $link);
    }

    public function test_link_assinado_mostra_nome_endereco_itens_e_pagamento(): void
    {
        $pedido = $this->pedido();

        $this->get($pedido->linkAcompanhamento())
            ->assertOk()
            ->assertSee('Maria')
            ->assertSee('Rua das Flores, 123')
            ->assertSee('Pizza Calabresa Especial')
            ->assertSee('Pizzas')
            ->assertSee('Cartão de crédito')
            ->assertSee('Em preparo');
    }

    public function test_sem_assinatura_mostra_so_o_status(): void
    {
        $pedido = $this->pedido();

        $this->get(route('pedido.acompanhar', $pedido->id))
            ->assertOk()
            ->assertSee('Em preparo')
            ->assertDontSee('Maria')
            ->assertDontSee('Rua das Flores')
            ->assertDontSee('Pizza Calabresa')
            ->assertDontSee('Cartão de crédito')
            ->assertDontSee('55,00');
    }

    public function test_assinatura_adulterada_mostra_so_o_status(): void
    {
        $pedido = $this->pedido();
        $outro = $this->pedido();

        // A assinatura do pedido A não vale para o pedido B.
        $adulterado = str_replace('/acompanhar/'.$pedido->id, '/acompanhar/'.$outro->id, $pedido->linkAcompanhamento());

        $this->get($adulterado)
            ->assertOk()
            ->assertDontSee('Maria')
            ->assertDontSee('Rua das Flores');
    }

    public function test_cliente_nao_consegue_liberar_os_dados_pelo_livewire(): void
    {
        $pedido = $this->pedido();

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(AcompanhamentoPedido::class, ['pedidoId' => $pedido->id, 'completo' => false])
            ->assertDontSee('Maria')
            ->set('completo', true);
    }

    public function test_requests_de_atualizacao_mantem_so_o_status(): void
    {
        $pedido = $this->pedido();

        Livewire::test(AcompanhamentoPedido::class, ['pedidoId' => $pedido->id, 'completo' => false])
            ->call('$refresh')
            ->assertDontSee('Maria')
            ->assertDontSee('Rua das Flores');
    }

    public function test_cancelado_tem_tela_propria(): void
    {
        $pedido = $this->pedido('CANCELADO');
        $pedido->update(['pedido_datahora_cancelado' => now()]);

        $this->get($pedido->linkAcompanhamento())
            ->assertOk()
            ->assertSee('Pedido cancelado')
            ->assertDontSee('Andamento');
    }

    public function test_retirada_mostra_onde_retirar_com_endereco_e_como_chegar(): void
    {
        Empresa::create([
            'empresa_razao_social' => 'Pizzaria',
            'empresa_endereco_rua' => 'Av. Brasil',
            'empresa_endereco_numero_endereco' => '10',
            'empresa_endereco_bairro' => 'Centro',
            'empresa_google_maps_url' => 'https://maps.app.goo.gl/abc123',
        ]);
        $pedido = $this->pedido('PRONTO', entrega: false);
        $pedido->update(['pedido_datahora_pronto' => now()]);

        $this->get($pedido->linkAcompanhamento())
            ->assertOk()
            ->assertSee('Pode vir buscar!')
            ->assertSee('Onde retirar')
            ->assertSee('Av. Brasil, 10 – Centro')
            ->assertSee('https://maps.app.goo.gl/abc123', false)
            ->assertDontSee('Saiu para entrega');
    }

    public function test_sem_assinatura_ainda_mostra_onde_retirar_mas_nao_os_dados_do_pedido(): void
    {
        Empresa::create(['empresa_razao_social' => 'Pizzaria', 'empresa_endereco_rua' => 'Av. Brasil']);
        $pedido = $this->pedido('PRONTO', entrega: false);

        $this->get(route('pedido.acompanhar', $pedido->id))
            ->assertOk()
            ->assertSee('Onde retirar')
            ->assertSee('Av. Brasil')
            ->assertDontSee('Maria')
            ->assertDontSee('Pizza Calabresa')
            ->assertDontSee('Cartão de crédito');
    }

    public function test_pedido_inexistente(): void
    {
        $this->get(route('pedido.acompanhar', 999999))
            ->assertOk()
            ->assertSee('Pedido não encontrado');
    }
}
