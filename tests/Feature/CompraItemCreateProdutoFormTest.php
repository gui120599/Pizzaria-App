<?php

namespace Tests\Feature;

use App\Filament\Resources\Compras\Pages\EditCompra;
use App\Filament\Resources\Compras\RelationManagers\ItensRelationManager;
use App\Models\Categoria;
use App\Models\Compra;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CompraItemCreateProdutoFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['name_first' => 'Admin']));
    }

    /**
     * A ProdutoForm é embutida como createOptionForm no Select de produto do relation
     * manager de itens (record = CompraItem). O campo de plano de despesa não pode usar
     * ->relationship('planoDespesa') senão é resolvido em CompraItem e quebra
     * ("The relationship [planoDespesa] does not exist on the model [CompraItem]").
     */
    public function test_abrir_create_option_de_produto_dentro_da_compra_nao_quebra(): void
    {
        $compra = Compra::create([
            'compra_data_entrada' => now()->toDateString(),
            'compra_user_id' => auth()->id(),
        ]);

        Livewire::test(ItensRelationManager::class, [
            'ownerRecord' => $compra,
            'pageClass' => EditCompra::class,
        ])
            ->mountFormComponentAction('ci_produto_id', 'createOption')
            ->assertHasNoFormComponentActionErrors()
            ->assertOk();
    }

    /**
     * O "+" de categoria dentro do cadastro rápido de produto abre o form da
     * CategoriaResource, que tem Repeaters ->relationship() (perguntas,
     * quantidadesSabores). Sem actionSchemaModel(Categoria) eles resolviam em
     * CompraItem e o modal quebrava; e as perguntas não eram gravadas.
     */
    public function test_cadastrar_categoria_dentro_do_cadastro_de_produto_da_compra(): void
    {
        $compra = Compra::create([
            'compra_data_entrada' => now()->toDateString(),
            'compra_user_id' => auth()->id(),
        ]);

        Livewire::test(ItensRelationManager::class, [
            'ownerRecord' => $compra,
            'pageClass' => EditCompra::class,
        ])
            ->mountAction([
                TestAction::make('create')->table(),
                TestAction::make('createOption')->schemaComponent('ci_produto_id', 'mountedActionSchema0'),
                TestAction::make('createOption')->schemaComponent('produto_categoria_id', 'mountedActionSchema1'),
            ])
            ->setActionData([
                'categoria_nome' => 'Embalagens',
                'perguntas' => [
                    ['pergunta_texto' => 'Tamanho?', 'pergunta_minimo' => 0, 'pergunta_maximo' => 1, 'pergunta_ativa' => true],
                ],
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $categoria = Categoria::where('categoria_nome', 'Embalagens')->first();
        $this->assertNotNull($categoria, 'A categoria criada pelo "+" deve existir no banco.');
        $this->assertSame(['Tamanho?'], $categoria->perguntas()->pluck('pergunta_texto')->all());
    }
}
