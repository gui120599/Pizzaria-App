<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Filament\Resources\Adicionais\Pages\ManageAdicionais;
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
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\Testing\TestAction;
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

    private function produto(): Produto
    {
        return Produto::create([
            'produto_descricao' => 'Pizza Calabresa',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Pizzas'])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
        ]);
    }

    private function relationManager(Produto $produto): mixed
    {
        return Livewire::test(AdicionaisRelationManager::class, [
            'ownerRecord' => $produto,
            'pageClass' => EditProduto::class,
        ]);
    }

    public function test_cria_adicional_pelo_resource(): void
    {
        Livewire::test(ManageAdicionais::class)
            ->callAction('create', [
                'adicional_nome' => 'Borda de catupiry',
                'adicional_valor' => '8,50',
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('adicionais', [
            'adicional_nome' => 'Borda de catupiry',
            'adicional_valor' => 8.50,
        ]);
    }

    public function test_editar_adicional_com_foto_legada_preserva_a_foto(): void
    {
        $adicional = Adicional::factory()->create(['adicional_foto' => '1712345678.jpg']);

        Livewire::test(ManageAdicionais::class)
            ->callAction(TestAction::make(EditAction::class)->table($adicional), [
                'adicional_nome' => 'Bacon extra',
            ])
            ->assertHasNoActionErrors();

        $adicional->refresh();
        $this->assertSame('Bacon extra', $adicional->adicional_nome);
        $this->assertSame('1712345678.jpg', $adicional->adicional_foto);
    }

    public function test_inativa_e_reativa_adicional(): void
    {
        $adicional = Adicional::factory()->create();

        Livewire::test(ManageAdicionais::class)
            ->callAction(TestAction::make(DeleteAction::class)->table($adicional));

        $this->assertSoftDeleted($adicional);

        Livewire::test(ManageAdicionais::class)
            ->filterTable('trashed', true)
            ->callAction(TestAction::make(RestoreAction::class)->table($adicional));

        $this->assertNotSoftDeleted($adicional);
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
