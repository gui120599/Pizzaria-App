<?php

namespace Tests\Feature;

use App\Filament\Resources\Compras\Pages\EditCompra;
use App\Filament\Resources\Compras\RelationManagers\ItensRelationManager;
use App\Models\Compra;
use App\Models\User;
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
}
