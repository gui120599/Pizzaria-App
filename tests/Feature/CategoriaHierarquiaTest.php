<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Filament\Resources\Categorias\Pages\ManageCategorias;
use App\Filament\Resources\Produtos\Pages\ListProdutos;
use App\Models\Categoria;
use App\Models\Produto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cobre a hierarquia de categoria pai (categoria_pai_id): proteção contra
 * ciclo em Categoria::idsDescendentes(), a Action de editar no
 * CategoriaResource, e o novo filtro "Categoria Pai" no ProdutosTable.
 */
class CategoriaHierarquiaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    public function test_ids_descendentes_percorre_varios_niveis(): void
    {
        $bebidas = Categoria::create(['categoria_nome' => 'Bebidas']);
        $refrigerantes = Categoria::create(['categoria_nome' => 'Refrigerantes', 'categoria_pai_id' => $bebidas->id]);
        $colas = Categoria::create(['categoria_nome' => 'Colas', 'categoria_pai_id' => $refrigerantes->id]);
        // Categoria sem relação nenhuma com a árvore.
        Categoria::create(['categoria_nome' => 'Sobremesas']);

        $descendentes = $bebidas->idsDescendentes();

        $this->assertEqualsCanonicalizing([$refrigerantes->id, $colas->id], $descendentes);
    }

    public function test_editar_categoria_com_pai_persiste_o_vinculo(): void
    {
        $bebidas = Categoria::create(['categoria_nome' => 'Bebidas']);
        $refrigerantes = Categoria::create(['categoria_nome' => 'Refrigerantes']);

        Livewire::test(ManageCategorias::class)
            ->callTableAction('edit', $refrigerantes, data: [
                'categoria_nome' => 'Refrigerantes',
                'categoria_pai_id' => $bebidas->id,
                'categoria_cardapio' => false,
                'categoria_ordem' => 0,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame($bebidas->id, $refrigerantes->refresh()->categoria_pai_id);
    }

    public function test_bulk_action_define_categoria_pai_em_varias_categorias(): void
    {
        $bebidas = Categoria::create(['categoria_nome' => 'Bebidas']);
        $refrigerantes = Categoria::create(['categoria_nome' => 'Refrigerantes']);
        $sucos = Categoria::create(['categoria_nome' => 'Sucos']);

        Livewire::test(ManageCategorias::class)
            ->callTableBulkAction('definir_categoria_pai', [$refrigerantes, $sucos], data: [
                'categoria_pai_id' => $bebidas->id,
            ])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame($bebidas->id, $refrigerantes->refresh()->categoria_pai_id);
        $this->assertSame($bebidas->id, $sucos->refresh()->categoria_pai_id);
    }

    public function test_bulk_action_sem_categoria_pai_torna_selecionadas_categorias_de_topo(): void
    {
        $bebidas = Categoria::create(['categoria_nome' => 'Bebidas']);
        $refrigerantes = Categoria::create(['categoria_nome' => 'Refrigerantes', 'categoria_pai_id' => $bebidas->id]);

        Livewire::test(ManageCategorias::class)
            ->callTableBulkAction('definir_categoria_pai', [$refrigerantes], data: [
                'categoria_pai_id' => null,
            ])
            ->assertHasNoTableBulkActionErrors();

        $this->assertNull($refrigerantes->refresh()->categoria_pai_id);
    }

    public function test_bulk_action_ignora_categoria_que_criaria_ciclo(): void
    {
        $bebidas = Categoria::create(['categoria_nome' => 'Bebidas']);
        $refrigerantes = Categoria::create(['categoria_nome' => 'Refrigerantes', 'categoria_pai_id' => $bebidas->id]);
        $sucos = Categoria::create(['categoria_nome' => 'Sucos']);

        // Tenta definir "Refrigerantes" (filha de Bebidas) como pai de "Bebidas" e
        // de "Sucos" na mesma operação: o ciclo em Bebidas deve ser ignorado, mas
        // Sucos (sem relação nenhuma) deve ser atualizada normalmente.
        Livewire::test(ManageCategorias::class)
            ->callTableBulkAction('definir_categoria_pai', [$bebidas, $sucos], data: [
                'categoria_pai_id' => $refrigerantes->id,
            ])
            ->assertHasNoTableBulkActionErrors();

        $this->assertNull($bebidas->refresh()->categoria_pai_id);
        $this->assertSame($refrigerantes->id, $sucos->refresh()->categoria_pai_id);
    }

    public function test_filtro_categoria_pai_inclui_produtos_da_propria_pai_e_das_filhas(): void
    {
        $bebidas = Categoria::create(['categoria_nome' => 'Bebidas']);
        $refrigerantes = Categoria::create(['categoria_nome' => 'Refrigerantes', 'categoria_pai_id' => $bebidas->id]);
        $sobremesas = Categoria::create(['categoria_nome' => 'Sobremesas']);

        $produtoDaPai = Produto::create([
            'produto_descricao' => 'Água',
            'produto_categoria_id' => $bebidas->id,
            'produto_tipo' => ProdutoTipoEnum::REVENDA->value,
        ]);
        $produtoDaFilha = Produto::create([
            'produto_descricao' => 'Coca-Cola',
            'produto_categoria_id' => $refrigerantes->id,
            'produto_tipo' => ProdutoTipoEnum::REVENDA->value,
        ]);
        $produtoFora = Produto::create([
            'produto_descricao' => 'Pudim',
            'produto_categoria_id' => $sobremesas->id,
            'produto_tipo' => ProdutoTipoEnum::REVENDA->value,
        ]);

        Livewire::test(ListProdutos::class)
            ->filterTable('categoria_pai_id', $bebidas->id)
            ->assertCanSeeTableRecords([$produtoDaPai, $produtoDaFilha])
            ->assertCanNotSeeTableRecords([$produtoFora]);
    }
}
