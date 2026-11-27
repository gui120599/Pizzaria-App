<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Filament\Resources\Produtos\Pages\EditProduto;
use App\Filament\Resources\Produtos\Pages\ListProdutos;
use App\Filament\Resources\Produtos\RelationManagers\LeveTambemRelationManager;
use App\Livewire\PedidoProdutoSelector;
use App\Models\Categoria;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\User;
use App\Services\CardapioService;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Leve também": sugestões configuradas no produto (ex.: a pizza sugere
 * refrigerantes), oferecidas depois de lançar o produto no cardápio e nos
 * painéis (Atender Pedido, garçom), pelo preço normal.
 */
class LeveTambemTest extends TestCase
{
    use RefreshDatabase;

    private Produto $pizza;

    private Produto $coca;

    private Produto $guarana;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        $pizzas = Categoria::create(['categoria_nome' => 'Pizza Grande', 'categoria_cardapio' => true, 'categoria_cardapio_garcom' => true]);
        $bebidas = Categoria::create(['categoria_nome' => 'Bebidas', 'categoria_cardapio' => true, 'categoria_cardapio_garcom' => true]);
        $this->pizza = $this->produto('Calabresa', 59.90, $pizzas);
        $this->coca = $this->produto('Coca-Cola 2L', 16.00, $bebidas);
        $this->guarana = $this->produto('Guaraná 2L', 12.00, $bebidas);
    }

    private function produto(string $nome, float $preco, Categoria $categoria, bool $visivel = true): Produto
    {
        return Produto::create([
            'produto_descricao' => $nome,
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::REVENDA->value,
            'produto_preco_venda' => $preco,
            'produto_cardapio' => $visivel,
            'produto_cardapio_garcom' => $visivel,
        ]);
    }

    private function seletor(): mixed
    {
        return Livewire::test(PedidoProdutoSelector::class, [
            'pedidoId' => Pedido::create(['pedido_status' => 'INICIADO', 'pedido_datahora_abertura' => now()])->id,
        ]);
    }

    private function lancar(mixed $seletor, Produto $produto): mixed
    {
        return $seletor->call('selecionarProduto', $produto->id)->call('confirmarItem');
    }

    public function test_cadastro_vincula_ordena_e_remove_sugestoes(): void
    {
        $rm = fn () => Livewire::test(LeveTambemRelationManager::class, ['ownerRecord' => $this->pizza, 'pageClass' => EditProduto::class]);

        $rm()->callAction(TestAction::make(AttachAction::class)->table(), ['recordId' => [$this->guarana->id, $this->coca->id]])
            ->assertHasNoActionErrors();
        $this->assertEqualsCanonicalizing([$this->coca->id, $this->guarana->id], $this->pizza->leveTambem()->pluck('produtos.id')->all());

        $this->pizza->leveTambem()->updateExistingPivot($this->guarana->id, ['plt_ordem' => 1]);
        $this->pizza->leveTambem()->updateExistingPivot($this->coca->id, ['plt_ordem' => 2]);
        $this->assertSame([$this->guarana->id, $this->coca->id], $this->pizza->leveTambem()->pluck('produtos.id')->all());

        $rm()->callAction(TestAction::make(DetachAction::class)->table($this->coca));
        $this->assertSame([$this->guarana->id], $this->pizza->leveTambem()->pluck('produtos.id')->all());
    }

    public function test_acao_em_massa_define_sugestoes_sem_duplicar_nem_sugerir_o_proprio(): void
    {
        $frango = $this->produto('Frango', 55.00, $this->pizza->categoria);
        $this->pizza->leveTambem()->attach($this->coca->id);

        Livewire::test(ListProdutos::class)
            ->callTableBulkAction('definir_leve_tambem', [$this->pizza, $frango], ['sugeridos' => [$this->coca->id, $this->guarana->id, $frango->id]])
            ->assertHasNoTableBulkActionErrors();

        $this->assertEqualsCanonicalizing([$this->coca->id, $this->guarana->id, $frango->id], $this->pizza->leveTambem()->pluck('produtos.id')->all());
        $this->assertEqualsCanonicalizing([$this->coca->id, $this->guarana->id], $frango->leveTambem()->pluck('produtos.id')->all());
    }

    public function test_acao_em_massa_sugere_categoria_inteira_com_subcategorias(): void
    {
        $bebidas = $this->coca->categoria;
        $sucos = Categoria::create(['categoria_nome' => 'Sucos', 'categoria_pai_id' => $bebidas->id, 'categoria_cardapio' => true]);
        $suco = $this->produto('Suco de Laranja', 9.00, $sucos);
        $foraDoCardapio = $this->produto('Água antiga', 3.00, $bebidas, visivel: false);

        Livewire::test(ListProdutos::class)
            ->callTableBulkAction('definir_leve_tambem', [$this->pizza], ['categorias' => [$bebidas->id]])
            ->assertHasNoTableBulkActionErrors();

        $sugeridos = $this->pizza->leveTambem()->pluck('produtos.id')->all();
        $this->assertEqualsCanonicalizing([$this->coca->id, $this->guarana->id, $suco->id], $sugeridos);
        $this->assertNotContains($foraDoCardapio->id, $sugeridos);
    }

    public function test_acao_em_massa_exige_categoria_ou_produto(): void
    {
        Livewire::test(ListProdutos::class)
            ->callTableBulkAction('definir_leve_tambem', [$this->pizza], ['categorias' => [], 'sugeridos' => []])
            ->assertHasTableBulkActionErrors(['categorias' => 'required', 'sugeridos' => 'required']);

        $this->assertSame([], $this->pizza->leveTambem()->pluck('produtos.id')->all());
    }

    public function test_painel_sugere_a_cada_lancamento_do_produto(): void
    {
        $this->pizza->leveTambem()->attach([$this->coca->id => ['plt_ordem' => 1], $this->guarana->id => ['plt_ordem' => 2]]);

        $seletor = $this->lancar($this->seletor(), $this->pizza)
            ->assertSet('leveTambemModal.origem', 'Calabresa')
            ->assertSee('Leve também');
        $this->assertSame([$this->coca->id, $this->guarana->id], array_column($seletor->get('leveTambemModal.produtos'), 'id'));

        // Escolher abre o produto e mantém a outra sugestão para depois.
        $seletor->call('escolherLeveTambem', $this->coca->id)
            ->assertSet('produtoSelecionadoId', $this->coca->id)
            ->assertSet('leveTambemModal.produtos.0.id', $this->guarana->id)
            ->call('confirmarItem')
            ->call('fecharLeveTambem')
            ->assertSet('leveTambemModal', null);

        // Outra pizza: sugere de novo, inclusive a Coca que já está no pedido (com a quantidade).
        $this->lancar($seletor, $this->pizza)
            ->assertSet('leveTambemModal.origem', 'Calabresa')
            ->assertSee('No pedido: 1');
        $this->assertSame([$this->coca->id, $this->guarana->id], array_column($seletor->get('leveTambemModal.produtos'), 'id'));
    }

    public function test_produto_sem_leve_tambem_nao_abre_o_modal(): void
    {
        $this->lancar($this->seletor(), $this->coca)->assertSet('leveTambemModal', null);
    }

    public function test_cardapio_recebe_as_sugestoes_visiveis_na_ordem(): void
    {
        $oculto = $this->produto('Suco do dia', 8.00, $this->coca->categoria, visivel: false);
        $this->pizza->leveTambem()->attach([$this->guarana->id => ['plt_ordem' => 2], $this->coca->id => ['plt_ordem' => 1], $oculto->id => ['plt_ordem' => 3]]);

        $dados = app(CardapioService::class)->dados();

        $this->assertSame([$this->pizza->id => [$this->coca->id, $this->guarana->id]], $dados['leveTambem']);
        $this->get(route('cardapio'))->assertOk()->assertSee('id="titulo-leve-tambem"', false)
            ->assertSee('"leveTambem":{"'.$this->pizza->id.'":['.$this->coca->id.','.$this->guarana->id.']}', false);
    }
}
