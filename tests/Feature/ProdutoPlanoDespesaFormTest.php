<?php

namespace Tests\Feature;

use App\Enums\Comportamento;
use App\Enums\Periodicidade;
use App\Filament\Resources\Produtos\Pages\EditProduto;
use App\Models\Categoria;
use App\Models\PlanoDespesa;
use App\Models\Produto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * O plano de despesa classifica o custo de um produto na DRE, mas só produtos que
 * entram numa compra têm custo próprio a classificar. Produto produzido tem o custo
 * derivado dos insumos da ficha técnica, então o campo não se aplica a ele — e
 * exigi-lo travaria a edição de todo o catálogo já cadastrado sem classificação.
 */
class ProdutoPlanoDespesaFormTest extends TestCase
{
    use RefreshDatabase;

    private int $categoriaId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $this->categoriaId = Categoria::create(['categoria_nome' => 'Geral'])->id;
    }

    private function produto(string $tipo, array $attrs = []): Produto
    {
        return Produto::create(array_merge([
            'produto_descricao' => "Produto {$tipo}",
            'produto_categoria_id' => $this->categoriaId,
            'produto_tipo' => $tipo,
            'produto_unidade_estoque' => 'UN',
            'produto_preco_custo' => 10,
            // Obrigatórios na aba Fiscal: sem eles o save falha por validação alheia ao teste.
            'produto_codigo_NCM' => '19059090',
            'produto_CFOP' => '5102',
            'produto_CSOSN' => '102',
        ], $attrs));
    }

    private function plano(): PlanoDespesa
    {
        return PlanoDespesa::create([
            'nome' => 'CMV / Insumos',
            'comportamento' => Comportamento::Variavel,
            'periodicidade' => Periodicidade::Eventual,
        ]);
    }

    public function test_plano_de_despesa_e_exigido_para_produto_comprado(): void
    {
        $insumo = $this->produto('insumo');

        Livewire::test(EditProduto::class, ['record' => $insumo->getRouteKey()])
            ->assertFormFieldIsVisible('produto_plano_despesa_id')
            ->fillForm(['produto_plano_despesa_id' => null])
            ->call('save')
            ->assertHasFormErrors(['produto_plano_despesa_id' => 'required']);
    }

    public function test_produto_produzido_nao_exibe_nem_exige_plano_de_despesa(): void
    {
        $pizza = $this->produto('produzido', [
            'produto_valor_percentual_venda' => 100,
            'produto_preco_venda' => 20,
        ]);

        Livewire::test(EditProduto::class, ['record' => $pizza->getRouteKey()])
            ->assertFormFieldIsHidden('produto_plano_despesa_id')
            ->fillForm(['produto_descricao' => 'Pizza Calabresa'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Pizza Calabresa', $pizza->refresh()->produto_descricao);
        $this->assertNull($pizza->produto_plano_despesa_id);
    }

    /** Campo escondido não é desidratado: a classificação já gravada não pode ser perdida ao salvar. */
    public function test_salvar_produto_produzido_nao_apaga_plano_ja_gravado(): void
    {
        $plano = $this->plano();
        $pizza = $this->produto('produzido', [
            'produto_valor_percentual_venda' => 100,
            'produto_preco_venda' => 20,
            'produto_plano_despesa_id' => $plano->id,
        ]);

        Livewire::test(EditProduto::class, ['record' => $pizza->getRouteKey()])
            ->fillForm(['produto_descricao' => 'Pizza Portuguesa'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($plano->id, $pizza->refresh()->produto_plano_despesa_id);
    }
}
