<?php

namespace Tests\Feature;

use App\Enums\FormatoPizzaEnum;
use App\Enums\ProdutoTipoEnum;
use App\Enums\RegraPrecoSaboresEnum;
use App\Livewire\PedidoProdutoSelector;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\Pedido;
use App\Models\Pergunta;
use App\Models\Produto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Os sabores são escolhidos no navegador (Alpine) e só os ids chegam ao
 * servidor, em confirmarSabores(): ele revalida e cobra pelo próprio preço.
 */
class PedidoProdutoSelectorSaboresNavegadorTest extends TestCase
{
    use RefreshDatabase;

    private Pedido $pedido;

    private Categoria $pizzas;

    private Produto $calabresa;

    private Produto $mussarela;

    private Produto $portuguesa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pedido = Pedido::create(['pedido_status' => 'INICIADO', 'pedido_datahora_abertura' => now()]);
        $this->pizzas = Categoria::create(['categoria_nome' => 'Pizzas', 'categoria_permite_sabores' => true]);
        $this->pizzas->sincronizarQuantidadesSabores(3);
        $this->calabresa = $this->produto('Calabresa', 50.00);
        $this->mussarela = $this->produto('Mussarela', 60.00);
        $this->portuguesa = $this->produto('Portuguesa', 70.00);
    }

    private function produto(string $nome, float $preco, ?Categoria $categoria = null): Produto
    {
        return Produto::create([
            'produto_descricao' => $nome,
            'produto_categoria_id' => ($categoria ?? $this->pizzas)->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => $preco,
            'produto_cardapio' => true,
        ]);
    }

    private function seletor(int $modo)
    {
        return Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $this->pedido->id])
            ->call('selecionarProduto', $this->calabresa->id)
            ->call('setModoSabores', $modo);
    }

    public function test_confirmar_com_ids_lanca_a_pizza_pelo_preco_do_servidor(): void
    {
        $this->seletor(2)->call('confirmarSabores', [$this->calabresa->id, $this->mussarela->id])
            ->assertSet('saboresModalAberta', false);

        $item = ItensPedido::sole();
        $this->assertSame(55.0, (float) $item->item_pedido_valor);
        $this->assertSame(['Calabresa', 'Mussarela'], array_column($item->item_pedido_sabores, 'nome'));
    }

    public function test_regra_maior_valor_cobra_pelo_sabor_mais_caro(): void
    {
        $this->pizzas->update(['categoria_regra_preco_sabores' => RegraPrecoSaboresEnum::MAIOR]);

        $this->seletor(3)->call('confirmarSabores', [$this->calabresa->id, $this->mussarela->id, $this->portuguesa->id]);

        $this->assertSame(70.0, (float) ItensPedido::sole()->item_pedido_valor);
    }

    public function test_modal_expoe_a_regra_de_preco_e_o_formato_da_categoria(): void
    {
        $this->pizzas->update(['categoria_regra_preco_sabores' => RegraPrecoSaboresEnum::MAIOR, 'categoria_formato_pizza' => FormatoPizzaEnum::QUADRADA]);

        $this->seletor(2)
            ->assertSet('saboresRegraPreco', 'MAIOR')
            ->assertSet('saboresFormato', 'quadrada');
    }

    public function test_id_fora_da_categoria_e_recusado_sem_lancar(): void
    {
        $bebida = $this->produto('Guaraná', 7.00, Categoria::create(['categoria_nome' => 'Bebidas']));

        $this->seletor(2)
            ->call('confirmarSabores', [$this->calabresa->id, $bebida->id])
            ->assertSet('saboresModalAberta', true)
            ->assertSet('erroPromocao', fn (?string $erro) => filled($erro));

        $this->assertSame(0, ItensPedido::count());
    }

    public function test_id_repetido_e_recusado_sem_lancar(): void
    {
        $this->seletor(2)
            ->call('confirmarSabores', [$this->calabresa->id, $this->calabresa->id])
            ->assertSet('saboresModalAberta', true);

        $this->assertSame(0, ItensPedido::count());
    }

    public function test_contagem_diferente_do_modo_e_recusada_sem_lancar(): void
    {
        $this->seletor(3)->call('confirmarSabores', [$this->calabresa->id, $this->mussarela->id])->assertSet('saboresModalAberta', true);
        $this->seletor(2)->call('confirmarSabores', [$this->calabresa->id, $this->mussarela->id, $this->portuguesa->id])->assertSet('saboresModalAberta', true);
        $this->seletor(2)->call('confirmarSabores', [])->assertSet('saboresModalAberta', true);

        $this->assertSame(0, ItensPedido::count());
    }

    public function test_ids_que_nao_sao_inteiros_sao_recusados(): void
    {
        $this->seletor(2)
            ->call('confirmarSabores', [$this->calabresa->id, 'abc'])
            ->call('confirmarSabores', ['a' => $this->calabresa->id, 'b' => $this->mussarela->id])
            ->call('confirmarSabores', [$this->calabresa->id, [$this->mussarela->id]])
            ->assertSet('saboresModalAberta', true);

        $this->assertSame(0, ItensPedido::count());
    }

    public function test_inteira_continua_carregando_as_perguntas_do_produto_e_aceita_os_ids(): void
    {
        $borda = Pergunta::factory()->doProduto($this->calabresa)->obrigatoria()->comOpcoes(['Cheddar' => 8.00])->create(['pergunta_texto' => 'Borda']);

        $componente = Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $this->pedido->id])
            ->call('selecionarProduto', $this->calabresa->id)
            ->assertSet('perguntasDisponiveis', fn (array $perguntas) => array_column($perguntas, 'texto') === ['Borda'])
            ->call('alternarResposta', $borda->id, $borda->opcoes->sole()->id);

        $componente->call('confirmarSabores', [$this->calabresa->id]);

        $item = ItensPedido::sole();
        $this->assertSame(58.0, (float) $item->item_pedido_valor);
        $this->assertSame($this->calabresa->id, $item->item_pedido_produto_id);
    }

    public function test_fluxo_antigo_com_toggle_continua_funcionando(): void
    {
        $this->seletor(2)
            ->call('toggleSabor', $this->calabresa->id)
            ->call('toggleSabor', $this->mussarela->id)
            ->call('confirmarSabores');

        $this->assertSame(55.0, (float) ItensPedido::sole()->item_pedido_valor);
    }
}
