<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Filament\Resources\Categorias\Pages\ManageCategorias;
use App\Livewire\PedidoProdutoSelector;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\User;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Opções de quantidade de sabores da categoria: cadastro no Filament (com o
 * percentual de cada posição) e o seletor do balcão, que só oferece as
 * quantidades cadastradas e grava a pizza como uma linha.
 */
class QuantidadesSaboresCategoriaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    private function dadosCategoria(array $quantidades): array
    {
        return [
            'categoria_nome' => 'Pizzas',
            'categoria_cardapio' => true,
            'categoria_cardapio_garcom' => true,
            'categoria_ordem' => 1,
            'categoria_permite_sabores' => true,
            'quantidadesSabores' => $quantidades,
        ];
    }

    public function test_edicao_da_categoria_grava_opcoes_com_percentuais(): void
    {
        Repeater::fake();
        $categoria = Categoria::create(['categoria_nome' => 'Pizzas']);

        Livewire::test(ManageCategorias::class)
            ->callTableAction('edit', $categoria, data: $this->dadosCategoria([
                ['quantidade_sabor_descricao' => 'Meia a meia', 'quantidade_sabor_quantidade' => 2, 'quantidade_sabor_percentuais' => [60, 40]],
            ]))
            ->assertHasNoTableActionErrors();

        $opcao = $categoria->quantidadesSabores()->sole();
        $this->assertSame(2, $opcao->quantidade_sabor_quantidade);
        $this->assertSame([60.0, 40.0], $opcao->percentuais());
        $this->assertSame(2, $categoria->fresh()->maxSabores());
    }

    public function test_edicao_grava_descricao_de_cada_sabor_inclusive_vazia(): void
    {
        Repeater::fake();
        $categoria = Categoria::create(['categoria_nome' => 'Pizzas']);

        Livewire::test(ManageCategorias::class)
            ->callTableAction('edit', $categoria, data: $this->dadosCategoria([
                ['quantidade_sabor_descricao' => 'Meia a meia', 'quantidade_sabor_quantidade' => 2, 'quantidade_sabor_percentuais' => [50, 50], 'quantidade_sabor_rotulos' => ['1ª METADE', '']],
            ]))
            ->assertHasNoTableActionErrors();

        $this->assertSame(['1ª METADE', ''], $categoria->quantidadesSabores()->sole()->rotulos());
    }

    public function test_atalho_de_nomenclatura_em_fracao_preenche_as_descricoes(): void
    {
        Repeater::fake();
        $categoria = Categoria::create(['categoria_nome' => 'Pizzas']);

        Livewire::test(ManageCategorias::class)
            ->callTableAction('edit', $categoria, data: $this->dadosCategoria([
                ['quantidade_sabor_descricao' => 'Meia a meia', 'quantidade_sabor_quantidade' => 2, 'quantidade_sabor_percentuais' => [50, 50], 'nomenclatura' => 'fracao'],
            ]))
            ->assertHasNoTableActionErrors();

        $this->assertSame(['½', '½'], $categoria->quantidadesSabores()->sole()->rotulos());
    }

    public function test_edicao_recusa_percentuais_que_passam_de_100(): void
    {
        Repeater::fake();
        $categoria = Categoria::create(['categoria_nome' => 'Pizzas']);

        Livewire::test(ManageCategorias::class)
            ->callTableAction('edit', $categoria, data: $this->dadosCategoria([
                ['quantidade_sabor_descricao' => '3 sabores', 'quantidade_sabor_quantidade' => 3, 'quantidade_sabor_percentuais' => [60, 50, 0]],
            ]))
            ->assertHasTableActionErrors();

        $this->assertSame(0, $categoria->quantidadesSabores()->count());
    }

    public function test_balcao_oferece_so_as_quantidades_cadastradas_e_grava_uma_linha(): void
    {
        $categoria = Categoria::create(['categoria_nome' => 'Pizzas', 'categoria_permite_sabores' => true, 'categoria_cardapio_garcom' => true]);
        $categoria->sincronizarQuantidadesSabores(2);
        [$calabresa, $mussarela, $atum] = collect(['Calabresa' => 50.0, 'Mussarela' => 60.0, 'Atum' => 70.0])
            ->map(fn (float $preco, string $nome) => Produto::create([
                'produto_descricao' => $nome,
                'produto_categoria_id' => $categoria->id,
                'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
                'produto_preco_venda' => $preco,
            ]))
            ->values()
            ->all();
        $pedido = Pedido::create(['pedido_status' => 'ABERTO', 'pedido_datahora_abertura' => now()]);

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $pedido->id])
            ->call('selecionarProduto', $calabresa->id)
            ->assertSet('saboresOpcoes', [['quantidade' => 2, 'descricao' => 'Meia a meia']])
            ->call('setModoSabores', 3)
            ->assertSet('saboresModo', 1)
            ->call('setModoSabores', 2)
            ->call('toggleSabor', $calabresa->id)
            ->call('toggleSabor', $mussarela->id)
            ->call('toggleSabor', $atum->id)
            ->call('confirmarSabores')
            ->assertSet('erroPromocao', null);

        $item = ItensPedido::sole();
        $this->assertSame(['MEIA Calabresa', 'MEIA Mussarela'], $item->linhasSabores());
        $this->assertSame(55.0, (float) $item->item_pedido_valor);
        $this->assertSame(1.0, (float) $item->item_pedido_quantidade);
    }
}
