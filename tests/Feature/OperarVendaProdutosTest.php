<?php

namespace Tests\Feature;

use App\Enums\EstoqueModoControleEnum;
use App\Enums\ProdutoTipoEnum;
use App\Filament\Pages\OperarVenda;
use App\Models\Caixa;
use App\Models\Categoria;
use App\Models\ItensVenda;
use App\Models\Produto;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cobre App\Filament\Pages\OperarVenda::adicionarProdutoAvulso — o handler do
 * evento `produto-selecionado` disparado por App\Livewire\VendaProdutoSelector
 * (que só faz catálogo/busca, sem persistir nada por conta própria). É aqui
 * que mora a criação lazy da Venda (só no primeiro lançamento real).
 */
class OperarVendaProdutosTest extends TestCase
{
    use RefreshDatabase;

    private Produto $produto;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->admin()->create(['name_first' => 'Admin']);
        $this->actingAs($user);

        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);
        SessaoCaixa::create([
            'sessaocaixa_caixa_id' => $caixa->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => $user->id,
        ]);

        $categoria = Categoria::create(['categoria_nome' => 'Bebidas']);
        $this->produto = Produto::create([
            'produto_descricao' => 'Refrigerante Lata',
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::REVENDA->value,
            'produto_preco_venda' => 8.00,
            'produto_valor_percentual_icms' => 10,
            'produto_valor_percentual_pis' => 1,
            'produto_valor_percentual_cofins' => 2,
        ]);
    }

    public function test_primeiro_produto_cria_a_venda_lazy_com_tributos(): void
    {
        $component = Livewire::test(OperarVenda::class)
            ->assertSet('vendaId', null);

        $this->assertSame(0, Venda::count());

        $component->call('adicionarProdutoAvulso', $this->produto->id);

        $vendaId = $component->get('vendaId');
        $this->assertNotNull($vendaId);
        $this->assertSame(1, Venda::count());
        $this->assertSame('INICIADA', Venda::find($vendaId)->venda_status);

        $item = ItensVenda::where('item_venda_venda_id', $vendaId)->firstOrFail();
        $this->assertSame(1.0, (float) $item->item_venda_quantidade);
        $this->assertSame(8.00, (float) $item->item_venda_valor_unitario);
        $this->assertSame(8.00, (float) $item->item_venda_valor);
        $this->assertSame(0.80, round((float) $item->item_venda_valor_icms, 2));
        $this->assertSame(0.08, round((float) $item->item_venda_valor_pis, 2));
        $this->assertSame(0.16, round((float) $item->item_venda_valor_cofins, 2));
    }

    public function test_lancar_o_mesmo_produto_duas_vezes_soma_na_mesma_linha_sem_criar_segunda_venda(): void
    {
        $component = Livewire::test(OperarVenda::class);
        $component->call('adicionarProdutoAvulso', $this->produto->id);
        $vendaId = $component->get('vendaId');
        $component->call('adicionarProdutoAvulso', $this->produto->id);

        $this->assertSame(1, Venda::count());
        $this->assertSame($vendaId, $component->get('vendaId'));
        $this->assertSame(1, ItensVenda::where('item_venda_venda_id', $vendaId)->count());

        $item = ItensVenda::where('item_venda_venda_id', $vendaId)->firstOrFail();
        $this->assertSame(2.0, (float) $item->item_venda_quantidade);
        $this->assertSame(16.00, (float) $item->item_venda_valor);
    }

    public function test_usa_preco_promocional_quando_menor_que_o_preco_de_venda(): void
    {
        $this->produto->update(['produto_preco_promocional' => 6.00]);

        $component = Livewire::test(OperarVenda::class);
        $component->call('adicionarProdutoAvulso', $this->produto->id);

        $item = ItensVenda::where('item_venda_venda_id', $component->get('vendaId'))->firstOrFail();

        $this->assertSame(6.00, (float) $item->item_venda_valor_unitario);
        $this->assertSame(2.00, (float) $item->item_venda_desconto);
        $this->assertSame(6.00, (float) $item->item_venda_valor);
    }

    public function test_atualiza_total_da_venda_apos_lancar_produto(): void
    {
        $component = Livewire::test(OperarVenda::class);
        $component->call('adicionarProdutoAvulso', $this->produto->id);

        $venda = Venda::find($component->get('vendaId'));
        $this->assertSame(8.00, (float) $venda->venda_valor_total);
    }

    public function test_bloqueia_lancamento_quando_estoque_insuficiente_no_modo_bloquear_sem_criar_venda(): void
    {
        $produtoControlado = Produto::create([
            'produto_descricao' => 'Insumo Controlado',
            'produto_categoria_id' => $this->produto->produto_categoria_id,
            'produto_tipo' => ProdutoTipoEnum::REVENDA->value,
            'produto_preco_venda' => 5.00,
            'produto_controla_estoque' => true,
            'produto_saldo_estoque' => 0,
            'produto_modo_controle_estoque' => EstoqueModoControleEnum::BLOQUEAR->value,
        ]);

        Livewire::test(OperarVenda::class)
            ->call('adicionarProdutoAvulso', $produtoControlado->id)
            ->assertSet('erroEstoque', fn ($valor) => ! empty($valor))
            ->assertSet('vendaId', null);

        $this->assertSame(0, Venda::count());
    }
}
