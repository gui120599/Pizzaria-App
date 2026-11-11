<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Filament\Resources\Adicionais\AdicionalResource;
use App\Filament\Resources\Adicionais\Pages\CreateAdicional;
use App\Filament\Resources\Adicionais\Pages\EditAdicional;
use App\Filament\Resources\Adicionais\Pages\ListAdicionais;
use App\Filament\Resources\Adicionais\RelationManagers\ProdutosRelationManager;
use App\Filament\Resources\Produtos\Pages\EditProduto;
use App\Filament\Resources\Produtos\RelationManagers\AdicionaisRelationManager;
use App\Models\AdicionaisProduto;
use App\Models\Adicional;
use App\Models\Categoria;
use App\Models\Produto;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Filament\Actions\AttachAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DetachAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AdicionalFilamentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    private function produto(string $descricao = 'Pizza Calabresa', ProdutoTipoEnum $tipo = ProdutoTipoEnum::PRODUZIDO): Produto
    {
        return Produto::create([
            'produto_descricao' => $descricao,
            'produto_categoria_id' => Categoria::firstOrCreate(['categoria_nome' => 'Pizzas'])->id,
            'produto_tipo' => $tipo->value,
            'produto_preco_venda' => 45.90,
        ]);
    }

    private function relationManager(Produto $produto): mixed
    {
        return Livewire::test(AdicionaisRelationManager::class, [
            'ownerRecord' => $produto,
            'pageClass' => EditProduto::class,
        ]);
    }

    private function produtosDoAdicional(Adicional $adicional): mixed
    {
        return Livewire::test(ProdutosRelationManager::class, [
            'ownerRecord' => $adicional,
            'pageClass' => EditAdicional::class,
        ]);
    }

    public function test_cria_adicional_pelo_resource(): void
    {
        Livewire::test(CreateAdicional::class)
            ->fillForm([
                'adicional_nome' => 'Borda de catupiry',
                'adicional_valor' => '8,50',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('adicionais', [
            'adicional_nome' => 'Borda de catupiry',
            'adicional_valor' => 8.50,
        ]);
    }

    public function test_editar_adicional_com_foto_legada_preserva_a_foto(): void
    {
        $adicional = Adicional::factory()->create(['adicional_foto' => '1712345678.jpg']);

        Livewire::test(EditAdicional::class, ['record' => $adicional->getRouteKey()])
            ->fillForm(['adicional_nome' => 'Bacon extra'])
            ->call('save')
            ->assertHasNoFormErrors();

        $adicional->refresh();
        $this->assertSame('Bacon extra', $adicional->adicional_nome);
        $this->assertSame('1712345678.jpg', $adicional->adicional_foto);
    }

    public function test_inativa_e_reativa_adicional(): void
    {
        $adicional = Adicional::factory()->create();

        Livewire::test(EditAdicional::class, ['record' => $adicional->getRouteKey()])
            ->callAction(DeleteAction::class);

        $this->assertSoftDeleted($adicional);

        Livewire::test(EditAdicional::class, ['record' => $adicional->getRouteKey()])
            ->callAction(RestoreAction::class);

        $this->assertNotSoftDeleted($adicional);
    }

    public function test_lista_abre_a_edicao_do_adicional_em_pagina_propria(): void
    {
        $adicional = Adicional::factory()->create();

        Livewire::test(ListAdicionais::class)
            ->assertCanSeeTableRecords([$adicional])
            ->assertTableActionHasUrl('edit', EditAdicional::getUrl(['record' => $adicional]), $adicional);
    }

    public function test_vincula_varios_adicionais_e_o_pdv_enxerga_o_vinculo(): void
    {
        $produto = $this->produto();
        [$bacon, $cheddar] = Adicional::factory()->count(2)->create();

        $this->relationManager($produto)
            ->callAction(TestAction::make(AttachAction::class)->table(), [
                'recordId' => [$bacon->id, $cheddar->id],
            ])
            ->assertHasNoActionErrors();

        $vinculados = Produto::with('ap_produto_id.adicional')->find($produto->id)
            ->ap_produto_id->pluck('adicional.id')->sort()->values()->all();

        $this->assertSame([$bacon->id, $cheddar->id], $vinculados);
    }

    public function test_desvincula_adicional(): void
    {
        $produto = $this->produto();
        $adicional = Adicional::factory()->create();
        $produto->adicionais()->attach($adicional);

        $this->relationManager($produto)
            ->callAction(TestAction::make(DetachAction::class)->table($adicional));

        $this->assertDatabaseMissing('adicionais_produtos', [
            'ap_produto_id' => $produto->id,
            'ap_adicional_id' => $adicional->id,
        ]);
    }

    public function test_vinculo_inativado_pelo_legado_nao_aparece(): void
    {
        $produto = $this->produto();
        [$ativo, $inativado] = Adicional::factory()->count(2)->create();
        $produto->adicionais()->attach($ativo);
        AdicionaisProduto::create(['ap_produto_id' => $produto->id, 'ap_adicional_id' => $inativado->id])->delete();

        $this->relationManager($produto)
            ->assertCanSeeTableRecords([$ativo])
            ->assertCanNotSeeTableRecords([$inativado]);
    }

    public function test_quem_nao_edita_produto_nao_vincula_nem_desvincula(): void
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->atendente()->create(['name_first' => 'Atendente']);
        $user->givePermissionTo('view_any:adicional');
        $this->actingAs($user);

        $produto = $this->produto();
        $adicional = Adicional::factory()->create();
        $produto->adicionais()->attach($adicional);

        $this->relationManager($produto)
            ->assertActionHidden(TestAction::make(AttachAction::class)->table())
            ->assertActionHidden(TestAction::make(DetachAction::class)->table($adicional));
    }

    public function test_edicao_de_adicional_inativo_abre_com_a_aba_de_produtos(): void
    {
        $adicional = Adicional::factory()->create();
        $adicional->delete();

        $this->get(AdicionalResource::getUrl('edit', ['record' => $adicional]))
            ->assertOk()
            ->assertSeeLivewire(ProdutosRelationManager::class);
    }

    public function test_vincula_varios_produtos_pelo_adicional(): void
    {
        $adicional = Adicional::factory()->create();
        $calabresa = $this->produto('Pizza Calabresa');
        $refrigerante = $this->produto('Refrigerante Lata', ProdutoTipoEnum::REVENDA);

        $this->produtosDoAdicional($adicional)
            ->callAction(TestAction::make(AttachAction::class)->table(), [
                'recordId' => [$calabresa->id, $refrigerante->id],
            ])
            ->assertHasNoActionErrors();

        $this->assertTrue($calabresa->adicionais()->whereKey($adicional->id)->exists());
        $this->assertTrue($refrigerante->adicionais()->whereKey($adicional->id)->exists());
    }

    public function test_select_oferece_so_produtos_vendaveis_ainda_nao_vinculados(): void
    {
        $adicional = Adicional::factory()->create();
        $calabresa = $this->produto('Pizza Calabresa');
        $refrigerante = $this->produto('Refrigerante Lata', ProdutoTipoEnum::REVENDA);
        $jaVinculada = $this->produto('Pizza Mussarela');
        $insumo = $this->produto('Queijo Mussarela', ProdutoTipoEnum::INSUMO);
        $consumoInterno = $this->produto('Detergente', ProdutoTipoEnum::CONSUMO_INTERNO);
        $adicional->produtos()->attach($jaVinculada);

        $this->produtosDoAdicional($adicional)
            ->mountAction(TestAction::make(AttachAction::class)->table())
            ->assertFormFieldExists('recordId', function (Select $select) use ($calabresa, $refrigerante, $jaVinculada, $insumo, $consumoInterno): bool {
                $ofertados = array_keys($select->getOptions());

                $this->assertEqualsCanonicalizing([$calabresa->id, $refrigerante->id], $ofertados);
                $this->assertNotContains($jaVinculada->id, $ofertados);
                $this->assertNotContains($insumo->id, $ofertados);
                $this->assertNotContains($consumoInterno->id, $ofertados);

                return true;
            });
    }

    public function test_escolher_categoria_seleciona_os_produtos_dela_e_das_subcategorias(): void
    {
        $adicional = Adicional::factory()->create();
        $pizzas = Categoria::create(['categoria_nome' => 'Pizzas']);
        $doces = Categoria::create(['categoria_nome' => 'Pizzas Doces', 'categoria_pai_id' => $pizzas->id]);
        $bebidas = Categoria::create(['categoria_nome' => 'Bebidas']);

        $calabresa = $this->produto('Pizza Calabresa');
        $chocolate = $this->produto('Pizza de Chocolate');
        $chocolate->update(['produto_categoria_id' => $doces->id]);
        $jaVinculada = $this->produto('Pizza Mussarela');
        $adicional->produtos()->attach($jaVinculada);
        $this->produto('Massa de Pizza', ProdutoTipoEnum::INSUMO);
        $refrigerante = $this->produto('Refrigerante Lata', ProdutoTipoEnum::REVENDA);
        $refrigerante->update(['produto_categoria_id' => $bebidas->id]);

        $this->produtosDoAdicional($adicional)
            ->mountAction(TestAction::make(AttachAction::class)->table())
            ->assertFormFieldExists('categoria_para_selecionar', function (Select $select) use ($pizzas, $doces, $bebidas): bool {
                $this->assertSame([
                    $bebidas->id => 'Bebidas (1)',
                    $pizzas->id => 'Pizzas (2)',
                    $doces->id => 'Pizzas Doces (1)',
                ], $select->getOptions());

                return true;
            })
            ->fillForm(['categoria_para_selecionar' => $pizzas->id])
            ->assertSchemaStateSet(['categoria_para_selecionar' => null])
            ->assertFormFieldExists('recordId', function (Select $select) use ($calabresa, $chocolate): bool {
                $this->assertEqualsCanonicalizing([$calabresa->id, $chocolate->id], array_map('intval', $select->getState()));

                return true;
            })
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertEqualsCanonicalizing(
            [$calabresa->id, $chocolate->id, $jaVinculada->id],
            $adicional->produtos()->pluck('produtos.id')->all(),
        );
    }

    public function test_opcao_do_select_mostra_categoria_descricao_e_valor(): void
    {
        $html = ProdutosRelationManager::renderOpcaoProduto($this->produto('Pizza Calabresa')->load('categoria'));

        $this->assertStringContainsString('Pizzas', $html);
        $this->assertStringContainsString('Pizza Calabresa', $html);
        $this->assertStringContainsString('R$ 45,90', $html);
    }

    public function test_desvincula_produto_pelo_adicional(): void
    {
        $adicional = Adicional::factory()->create();
        $produto = $this->produto();
        $adicional->produtos()->attach($produto);

        $this->produtosDoAdicional($adicional)
            ->callAction(TestAction::make(DetachAction::class)->table($produto));

        $this->assertDatabaseMissing('adicionais_produtos', [
            'ap_produto_id' => $produto->id,
            'ap_adicional_id' => $adicional->id,
        ]);
    }

    public function test_vinculo_inativado_pelo_legado_nao_aparece_no_adicional(): void
    {
        $adicional = Adicional::factory()->create();
        $ativo = $this->produto('Pizza Calabresa');
        $inativado = $this->produto('Pizza Mussarela');
        $adicional->produtos()->attach($ativo);
        AdicionaisProduto::create(['ap_produto_id' => $inativado->id, 'ap_adicional_id' => $adicional->id])->delete();

        $this->produtosDoAdicional($adicional)
            ->assertCanSeeTableRecords([$ativo])
            ->assertCanNotSeeTableRecords([$inativado]);
    }

    public function test_quem_nao_edita_adicional_nao_vincula_nem_desvincula_produto(): void
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->atendente()->create(['name_first' => 'Atendente']);
        $user->givePermissionTo('view_any:adicional');
        $this->actingAs($user);

        $adicional = Adicional::factory()->create();
        $produto = $this->produto();
        $adicional->produtos()->attach($produto);

        $this->produtosDoAdicional($adicional)
            ->assertActionHidden(TestAction::make(AttachAction::class)->table())
            ->assertActionHidden(TestAction::make(DetachAction::class)->table($produto));
    }

    public function test_aba_produtos_filtra_por_categoria_tipo_cardapio_e_valor(): void
    {
        $adicional = Adicional::factory()->create();
        $pizzas = Categoria::create(['categoria_nome' => 'Pizzas']);
        $doces = Categoria::create(['categoria_nome' => 'Pizzas Doces', 'categoria_pai_id' => $pizzas->id]);
        $bebidas = Categoria::create(['categoria_nome' => 'Bebidas']);

        $calabresa = $this->produto('Pizza Calabresa');
        $calabresa->update(['produto_cardapio' => true]);
        $chocolate = $this->produto('Pizza de Chocolate');
        $chocolate->update(['produto_categoria_id' => $doces->id, 'produto_preco_venda' => 60]);
        $refrigerante = $this->produto('Refrigerante Lata', ProdutoTipoEnum::REVENDA);
        $refrigerante->update(['produto_categoria_id' => $bebidas->id, 'produto_preco_venda' => 7]);
        $adicional->produtos()->attach([$calabresa->id, $chocolate->id, $refrigerante->id]);
        $lanches = Categoria::create(['categoria_nome' => 'Lanches']);
        $this->produto('X-Burguer')->update(['produto_categoria_id' => $lanches->id]);

        $this->produtosDoAdicional($adicional)
            ->assertTableFilterExists('categoria', function (SelectFilter $filtro) use ($pizzas, $doces, $bebidas): bool {
                $this->assertSame([
                    $bebidas->id => 'Bebidas',
                    $pizzas->id => 'Pizzas',
                    $doces->id => 'Pizzas Doces',
                ], $filtro->getOptions());

                return true;
            })
            ->filterTable('categoria', [$pizzas->id])
            ->assertCanSeeTableRecords([$calabresa, $chocolate])
            ->assertCanNotSeeTableRecords([$refrigerante])
            ->resetTableFilters()
            ->filterTable('produto_tipo', ProdutoTipoEnum::REVENDA->value)
            ->assertCanSeeTableRecords([$refrigerante])
            ->assertCanNotSeeTableRecords([$calabresa, $chocolate])
            ->resetTableFilters()
            ->filterTable('produto_cardapio', true)
            ->assertCanSeeTableRecords([$calabresa])
            ->assertCanNotSeeTableRecords([$chocolate, $refrigerante])
            ->resetTableFilters()
            ->filterTable('faixa_valor', ['valor_min' => 40, 'valor_max' => 50])
            ->assertCanSeeTableRecords([$calabresa])
            ->assertCanNotSeeTableRecords([$chocolate, $refrigerante]);
    }

    public function test_aba_produtos_ordena_por_categoria_e_valor(): void
    {
        $adicional = Adicional::factory()->create();
        $bebidas = Categoria::create(['categoria_nome' => 'Bebidas']);
        $calabresa = $this->produto('Pizza Calabresa');
        $refrigerante = $this->produto('Refrigerante Lata', ProdutoTipoEnum::REVENDA);
        $refrigerante->update(['produto_categoria_id' => $bebidas->id, 'produto_preco_venda' => 7]);
        $adicional->produtos()->attach([$calabresa->id, $refrigerante->id]);

        $this->produtosDoAdicional($adicional)
            ->sortTable('categoria.categoria_nome')
            ->assertCanSeeTableRecords([$refrigerante, $calabresa], inOrder: true)
            ->sortTable('produto_preco_venda', 'desc')
            ->assertCanSeeTableRecords([$calabresa, $refrigerante], inOrder: true);
    }

    public function test_lista_de_adicionais_filtra_por_vinculo_categoria_e_valor(): void
    {
        $bebidas = Categoria::create(['categoria_nome' => 'Bebidas']);
        $refrigerantes = Categoria::create(['categoria_nome' => 'Refrigerantes', 'categoria_pai_id' => $bebidas->id]);
        $refrigerante = $this->produto('Refrigerante Lata', ProdutoTipoEnum::REVENDA);
        $refrigerante->update(['produto_categoria_id' => $refrigerantes->id]);
        $pizza = $this->produto('Pizza Calabresa');
        $lanches = Categoria::create(['categoria_nome' => 'Lanches']);
        $this->produto('X-Burguer')->update(['produto_categoria_id' => $lanches->id]);

        $borda = Adicional::factory()->create(['adicional_valor' => 8.50]);
        $gelo = Adicional::factory()->create(['adicional_valor' => 1.00]);
        $semVinculo = Adicional::factory()->create(['adicional_valor' => 20.00]);
        $borda->produtos()->attach($pizza);
        $gelo->produtos()->attach($refrigerante);

        Livewire::test(ListAdicionais::class)
            ->assertTableFilterExists('categoria_produto', function (SelectFilter $filtro) use ($bebidas, $refrigerantes, $pizza): bool {
                // Bebidas entra como mãe de Refrigerantes; Lanches fica de fora (nenhum produto com adicional).
                $this->assertSame([
                    $bebidas->id => 'Bebidas',
                    $pizza->produto_categoria_id => 'Pizzas',
                    $refrigerantes->id => 'Refrigerantes',
                ], $filtro->getOptions());

                return true;
            })
            ->filterTable('vinculado', false)
            ->assertCanSeeTableRecords([$semVinculo])
            ->assertCanNotSeeTableRecords([$borda, $gelo])
            ->resetTableFilters()
            ->filterTable('categoria_produto', [$bebidas->id])
            ->assertCanSeeTableRecords([$gelo])
            ->assertCanNotSeeTableRecords([$borda, $semVinculo])
            ->resetTableFilters()
            ->filterTable('faixa_valor', ['valor_min' => 5, 'valor_max' => 10])
            ->assertCanSeeTableRecords([$borda])
            ->assertCanNotSeeTableRecords([$gelo, $semVinculo]);
    }

    public function test_aba_adicionais_do_produto_filtra_por_valor(): void
    {
        $produto = $this->produto();
        $barato = Adicional::factory()->create(['adicional_valor' => 2.00]);
        $caro = Adicional::factory()->create(['adicional_valor' => 15.00]);
        $produto->adicionais()->attach([$barato->id, $caro->id]);

        $this->relationManager($produto)
            ->filterTable('faixa_valor', ['valor_min' => 10])
            ->assertCanSeeTableRecords([$caro])
            ->assertCanNotSeeTableRecords([$barato]);
    }

    public function test_url_da_foto_cai_no_padrao_sem_arquivo_e_resolve_foto_nova(): void
    {
        $legadoSemArquivo = Adicional::factory()->make(['adicional_foto' => 'semarquivo.jpg']);
        $semFoto = Adicional::factory()->make(['adicional_foto' => null]);

        $this->assertSame(asset('Sem Imagem.png'), $legadoSemArquivo->getImagemUrl());
        $this->assertSame(asset('Sem Imagem.png'), $semFoto->getImagemUrl());

        Storage::fake('public');
        Storage::disk('public')->put('fotos_adicionais/bacon.jpg', 'x');
        $comFoto = Adicional::factory()->make(['adicional_foto' => 'fotos_adicionais/bacon.jpg']);

        $this->assertSame(Storage::disk('public')->url('fotos_adicionais/bacon.jpg'), $comFoto->getImagemUrl());
    }
}
