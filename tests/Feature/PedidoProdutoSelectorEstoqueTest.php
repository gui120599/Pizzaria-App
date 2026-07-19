<?php

namespace Tests\Feature;

use App\Enums\EstoqueModoControleEnum;
use App\Enums\ProdutoTipoEnum;
use App\Livewire\PedidoProdutoSelector;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\Pedido;
use App\Models\Produto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cobre a checagem de disponibilidade de estoque no componente de garçom e o
 * toast que exibe erro/aviso dentro dos modais (bug: o botão de fechar usava
 * atribuição direta de duas props via $wire no Alpine e não funcionava —
 * corrigido para um método fecharToastEstoque() chamado via wire:click).
 */
class PedidoProdutoSelectorEstoqueTest extends TestCase
{
    use RefreshDatabase;

    private Categoria $categoria;

    private Pedido $pedido;

    protected function setUp(): void
    {
        parent::setUp();

        $this->categoria = Categoria::create(['categoria_nome' => 'Pizzas']);
        $this->pedido = Pedido::create([
            'pedido_status' => 'INICIADO',
            'pedido_datahora_abertura' => now(),
        ]);
    }

    private function produto(string $nome, array $attrs = []): Produto
    {
        return Produto::create(array_merge([
            'produto_descricao' => $nome,
            'produto_categoria_id' => $this->categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 30.00,
            'produto_cardapio' => true,
        ], $attrs));
    }

    public function test_confirmar_item_sem_saldo_em_modo_bloquear_mantem_modal_aberto_com_erro(): void
    {
        $produto = $this->produto('Calabresa', [
            'produto_controla_estoque' => true,
            'produto_modo_controle_estoque' => EstoqueModoControleEnum::BLOQUEAR,
            'produto_saldo_estoque' => 0,
        ]);

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $this->pedido->id])
            ->call('selecionarProduto', $produto->id)
            ->call('confirmarItem')
            ->assertSet('erroEstoque', fn ($msg) => ! empty($msg))
            ->assertSet('modalAberta', true);

        $this->assertSame(0, ItensPedido::count());
    }

    public function test_fechar_toast_estoque_limpa_erro_e_aviso(): void
    {
        $produto = $this->produto('Calabresa', [
            'produto_controla_estoque' => true,
            'produto_modo_controle_estoque' => EstoqueModoControleEnum::BLOQUEAR,
            'produto_saldo_estoque' => 0,
        ]);

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $this->pedido->id])
            ->call('selecionarProduto', $produto->id)
            ->call('confirmarItem')
            ->assertSet('erroEstoque', fn ($msg) => ! empty($msg))
            ->call('fecharToastEstoque')
            ->assertSet('erroEstoque', null)
            ->assertSet('avisoEstoque', null);
    }

    public function test_confirmar_item_em_modo_avisar_cria_item_e_seta_aviso(): void
    {
        $produto = $this->produto('Calabresa', [
            'produto_controla_estoque' => true,
            'produto_modo_controle_estoque' => EstoqueModoControleEnum::AVISAR,
            'produto_saldo_estoque' => 0,
        ]);

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $this->pedido->id])
            ->call('selecionarProduto', $produto->id)
            ->call('confirmarItem')
            ->assertSet('avisoEstoque', fn ($msg) => ! empty($msg))
            ->assertSet('modalAberta', false);

        $this->assertSame(1, ItensPedido::count());
    }
}
