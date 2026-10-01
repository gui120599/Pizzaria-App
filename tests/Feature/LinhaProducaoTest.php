<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Filament\Resources\LinhasProducao\Pages\CreateLinhaProducao;
use App\Filament\Resources\LinhasProducao\Pages\ListLinhasProducao;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\LinhaProducao;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LinhaProducaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    public function test_cria_linha_de_producao_com_categorias_pela_tela(): void
    {
        $pizzas = Categoria::create(['categoria_nome' => 'Pizzas']);
        $bebidas = Categoria::create(['categoria_nome' => 'Bebidas']);

        Livewire::test(CreateLinhaProducao::class)
            ->fillForm([
                'linha_nome' => 'Forno',
                'categorias' => [$pizzas->id, $bebidas->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $linha = LinhaProducao::where('linha_nome', 'Forno')->firstOrFail();

        $this->assertSame([$pizzas->id, $bebidas->id], $linha->categorias->pluck('id')->sort()->values()->all());
    }

    public function test_nome_duplicado_e_recusado(): void
    {
        LinhaProducao::create(['linha_nome' => 'Forno']);

        Livewire::test(CreateLinhaProducao::class)
            ->fillForm(['linha_nome' => 'Forno', 'categorias' => []])
            ->call('create')
            ->assertHasFormErrors(['linha_nome' => 'unique']);
    }

    public function test_listagem_mostra_as_categorias_da_linha(): void
    {
        $categoria = Categoria::create(['categoria_nome' => 'Pizzas']);
        $linha = LinhaProducao::create(['linha_nome' => 'Forno']);
        $linha->categorias()->attach($categoria->id);

        Livewire::test(ListLinhasProducao::class)
            ->assertCanSeeTableRecords([$linha]);
    }

    /**
     * idsCategoriasComDescendentes() expande subcategorias — uma linha ligada
     * à categoria pai "Pizzas" também cobre a subcategoria "Pizzas Doces".
     */
    public function test_ids_categorias_com_descendentes_inclui_subcategorias(): void
    {
        $pai = Categoria::create(['categoria_nome' => 'Pizzas']);
        $filha = Categoria::create(['categoria_nome' => 'Pizzas Doces', 'categoria_pai_id' => $pai->id]);
        $neta = Categoria::create(['categoria_nome' => 'Pizzas Doces Especiais', 'categoria_pai_id' => $filha->id]);

        $linha = LinhaProducao::create(['linha_nome' => 'Forno']);
        $linha->categorias()->attach($pai->id);

        $ids = $linha->idsCategoriasComDescendentes();

        $this->assertEqualsCanonicalizing([$pai->id, $filha->id, $neta->id], $ids);
    }

    /**
     * Um pedido com item de uma subcategoria da linha precisa ser encontrável
     * via whereHas com o resultado de idsCategoriasComDescendentes() — é
     * exatamente o filtro que o Painel de Pedidos usa.
     */
    public function test_filtro_por_linha_encontra_pedido_com_item_de_subcategoria(): void
    {
        $pai = Categoria::create(['categoria_nome' => 'Pizzas']);
        $filha = Categoria::create(['categoria_nome' => 'Pizzas Doces', 'categoria_pai_id' => $pai->id]);
        $outraCategoria = Categoria::create(['categoria_nome' => 'Bebidas']);

        $linha = LinhaProducao::create(['linha_nome' => 'Forno']);
        $linha->categorias()->attach($pai->id);

        $produtoDaLinha = Produto::create([
            'produto_descricao' => 'Chocolate',
            'produto_categoria_id' => $filha->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
        ]);

        $produtoFora = Produto::create([
            'produto_descricao' => 'Refrigerante',
            'produto_categoria_id' => $outraCategoria->id,
            'produto_tipo' => ProdutoTipoEnum::REVENDA->value,
        ]);

        $pedidoDaLinha = Pedido::create(['pedido_status' => 'ABERTO', 'pedido_datahora_abertura' => now()]);
        ItensPedido::create([
            'item_pedido_pedido_id' => $pedidoDaLinha->id,
            'item_pedido_produto_id' => $produtoDaLinha->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 10,
            'item_pedido_valor' => 10,
            'item_pedido_status' => 'INSERIDO',
        ]);

        $pedidoForaDaLinha = Pedido::create(['pedido_status' => 'ABERTO', 'pedido_datahora_abertura' => now()]);
        ItensPedido::create([
            'item_pedido_pedido_id' => $pedidoForaDaLinha->id,
            'item_pedido_produto_id' => $produtoFora->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 5,
            'item_pedido_valor' => 5,
            'item_pedido_status' => 'INSERIDO',
        ]);

        $ids = $linha->idsCategoriasComDescendentes();

        $encontrados = Pedido::whereHas(
            'item_pedido_pedido_id',
            fn ($q) => $q->where('item_pedido_status', 'INSERIDO')
                ->whereHas('produto', fn ($p) => $p->whereIn('produto_categoria_id', $ids))
        )->pluck('id')->all();

        $this->assertSame([$pedidoDaLinha->id], $encontrados);
    }
}
