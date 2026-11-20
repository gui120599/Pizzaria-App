<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Filament\Resources\Adicionais\Pages\ListAdicionais;
use App\Filament\Resources\Produtos\Pages\EditProduto;
use App\Filament\Resources\Produtos\RelationManagers\AdicionaisRelationManager;
use App\Models\Adicional;
use App\Models\Categoria;
use App\Models\Produto;
use App\Models\User;
use App\Services\CardapioService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ordem dos adicionais: a geral (Cardápio › Adicionais) vale para todos os
 * produtos; o produto ordenado na aba Adicionais ganha a ordem própria, e
 * "Usar a ordem geral" desfaz.
 */
class AdicionalOrdemTest extends TestCase
{
    use RefreshDatabase;

    private Adicional $bacon;

    private Adicional $cheddar;

    private Adicional $catupiry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        $this->bacon = Adicional::factory()->create(['adicional_nome' => 'Bacon']);
        $this->cheddar = Adicional::factory()->create(['adicional_nome' => 'Cheddar']);
        $this->catupiry = Adicional::factory()->create(['adicional_nome' => 'Catupiry']);
    }

    private function produto(string $descricao): Produto
    {
        return Produto::create([
            'produto_descricao' => $descricao,
            'produto_categoria_id' => Categoria::firstOrCreate(['categoria_nome' => 'Lanches'], ['categoria_cardapio' => true])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 30.00,
            'produto_cardapio' => true,
        ]);
    }

    /** @return list<string> */
    private function nomes(Produto $produto): array
    {
        return $produto->fresh()->adicionais->pluck('adicional_nome')->all();
    }

    private function abaAdicionais(Produto $produto): mixed
    {
        return Livewire::test(AdicionaisRelationManager::class, ['ownerRecord' => $produto, 'pageClass' => EditProduto::class]);
    }

    public function test_adicional_novo_entra_no_fim_da_ordem_geral(): void
    {
        $this->assertSame([1, 2, 3], [$this->bacon->adicional_ordem, $this->cheddar->adicional_ordem, $this->catupiry->adicional_ordem]);
        $this->assertSame(4, Adicional::factory()->create()->adicional_ordem);
    }

    public function test_produto_segue_a_ordem_geral_e_nao_a_ordem_em_que_foi_vinculado(): void
    {
        $lanche = $this->produto('X-Burguer');
        $lanche->adicionais()->attach([$this->catupiry->id, $this->bacon->id, $this->cheddar->id]);

        $this->assertSame(['Bacon', 'Cheddar', 'Catupiry'], $this->nomes($lanche));

        Livewire::test(ListAdicionais::class)->call('reorderTable', [$this->catupiry->id, $this->bacon->id, $this->cheddar->id]);

        $this->assertSame(['Catupiry', 'Bacon', 'Cheddar'], $this->nomes($lanche));
    }

    public function test_ordenar_na_aba_do_produto_vale_so_para_ele_e_vinculo_novo_entra_no_fim(): void
    {
        $lanche = $this->produto('X-Burguer');
        $outro = $this->produto('X-Salada');
        $lanche->adicionais()->attach([$this->bacon->id, $this->cheddar->id]);
        $outro->adicionais()->attach([$this->bacon->id, $this->cheddar->id]);

        $this->abaAdicionais($lanche)->call('reorderTable', [$this->cheddar->id, $this->bacon->id]);

        $this->assertTrue($lanche->temOrdemPropriaDeAdicionais());
        $this->assertSame(['Cheddar', 'Bacon'], $this->nomes($lanche));
        $this->assertSame(['Bacon', 'Cheddar'], $this->nomes($outro));

        $lanche->adicionais()->attach($this->catupiry->id);

        $this->assertSame(['Cheddar', 'Bacon', 'Catupiry'], $this->nomes($lanche));
    }

    public function test_usar_a_ordem_geral_desfaz_a_ordem_propria(): void
    {
        $lanche = $this->produto('X-Burguer');
        $lanche->adicionais()->attach([$this->bacon->id, $this->cheddar->id]);
        $this->abaAdicionais($lanche)->call('reorderTable', [$this->cheddar->id, $this->bacon->id]);

        $this->abaAdicionais($lanche->fresh())->callAction(TestAction::make('usarOrdemGeral')->table());

        $this->assertFalse($lanche->temOrdemPropriaDeAdicionais());
        $this->assertSame(['Bacon', 'Cheddar'], $this->nomes($lanche));
    }

    public function test_cardapio_entrega_os_adicionais_na_ordem_do_produto(): void
    {
        $lanche = $this->produto('X-Burguer');
        $lanche->adicionais()->attach([$this->bacon->id, $this->cheddar->id, $this->catupiry->id]);
        $this->abaAdicionais($lanche)->call('reorderTable', [$this->catupiry->id, $this->bacon->id, $this->cheddar->id]);

        $adicionais = app(CardapioService::class)->dados()['adicionaisProdutos'][$lanche->id];

        $this->assertSame(['Catupiry', 'Bacon', 'Cheddar'], array_column($adicionais, 'nome'));
    }
}
