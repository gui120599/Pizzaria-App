<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Filament\Pages\OperarVenda;
use App\Models\AdicionaisItemVenda;
use App\Models\Adicional;
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

class OperarVendaCarrinhoTest extends TestCase
{
    use RefreshDatabase;

    private Produto $produto;

    private Venda $venda;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->admin()->create(['name_first' => 'Admin']);
        $this->actingAs($user);

        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);
        $sessaoCaixa = SessaoCaixa::create([
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
            'produto_preco_venda' => 10.00,
            'produto_valor_percentual_icms' => 10,
            'produto_valor_percentual_pis' => 1,
            'produto_valor_percentual_cofins' => 2,
        ]);

        $this->venda = Venda::create([
            'venda_status' => 'INICIADA',
            'venda_sessao_caixa_id' => $sessaoCaixa->id,
        ]);
    }

    private function itemNaVenda(float $quantidade = 1, float $desconto = 0): ItensVenda
    {
        $valor = ($this->produto->produto_preco_venda * $quantidade) - $desconto;

        return ItensVenda::create([
            'item_numero' => 1,
            'item_venda_venda_id' => $this->venda->id,
            'item_venda_produto_id' => $this->produto->id,
            'item_venda_quantidade' => $quantidade,
            'item_venda_quantidade_tributavel' => $quantidade,
            'item_venda_valor_unitario' => $this->produto->produto_preco_venda,
            'item_venda_desconto' => $desconto,
            'item_venda_valor' => $valor,
            'item_venda_valor_base_calculo' => $valor,
            'item_venda_status' => 'INSERIDO',
        ]);
    }

    public function test_lista_itens_do_carrinho_da_venda_atual(): void
    {
        $item = $this->itemNaVenda();

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->assertSee('Refrigerante Lata');

        $this->assertSame(1, ItensVenda::where('item_venda_venda_id', $this->venda->id)->count());
        $this->assertSame($item->id, ItensVenda::where('item_venda_venda_id', $this->venda->id)->first()->id);
    }

    public function test_atualizar_quantidade_recalcula_valor_e_tributos(): void
    {
        $item = $this->itemNaVenda();

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('atualizarQtdItem', $item->id, 3);

        $itemFresh = $item->fresh();
        $this->assertSame(3.0, (float) $itemFresh->item_venda_quantidade);
        $this->assertSame(30.00, (float) $itemFresh->item_venda_valor);
        $this->assertSame(3.00, round((float) $itemFresh->item_venda_valor_icms, 2));

        $this->assertSame(30.00, (float) $this->venda->fresh()->venda_valor_total);
    }

    public function test_atualizar_quantidade_escala_o_desconto_por_unidade(): void
    {
        // qtd 1, desconto R$2 (R$2/unidade) → qtd 2 deve virar desconto R$4
        $item = $this->itemNaVenda(quantidade: 1, desconto: 2.00);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('atualizarQtdItem', $item->id, 2);

        $itemFresh = $item->fresh();
        $this->assertSame(4.00, (float) $itemFresh->item_venda_desconto);
        // (10*2) - 4 = 16
        $this->assertSame(16.00, (float) $itemFresh->item_venda_valor);
    }

    public function test_atualizar_quantidade_para_baixo_reduz_o_desconto_proporcionalmente(): void
    {
        // qtd 2, desconto R$4 (R$2/unidade) → qtd 1 deve virar desconto R$2
        $item = $this->itemNaVenda(quantidade: 2, desconto: 4.00);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('atualizarQtdItem', $item->id, 1);

        $itemFresh = $item->fresh();
        $this->assertSame(2.00, (float) $itemFresh->item_venda_desconto);
        $this->assertSame(8.00, (float) $itemFresh->item_venda_valor);
    }

    public function test_atualizar_quantidade_para_zero_ou_negativo_e_ignorado(): void
    {
        $item = $this->itemNaVenda(quantidade: 2);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('atualizarQtdItem', $item->id, 0);

        $this->assertSame(2.0, (float) $item->fresh()->item_venda_quantidade);
    }

    public function test_atualizar_desconto_do_item_recalcula_valor(): void
    {
        $item = $this->itemNaVenda(quantidade: 2);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('atualizarDescontoItem', $item->id, 5.00);

        $itemFresh = $item->fresh();
        $this->assertSame(5.00, (float) $itemFresh->item_venda_desconto);
        $this->assertSame(15.00, (float) $itemFresh->item_venda_valor);
    }

    public function test_remover_item_apaga_adicionais_e_recalcula_venda(): void
    {
        $item = $this->itemNaVenda();
        $adicional = Adicional::create(['adicional_nome' => 'Bacon', 'adicional_valor' => 2.00]);
        AdicionaisItemVenda::create([
            'aiv_item_venda_id' => $item->id,
            'aiv_adicional_id' => $adicional->id,
            'aiv_valor_unitario' => 2.00,
            'aiv_quantidade' => 1,
            'aiv_valor_total' => 2.00,
        ]);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('removerItemCarrinho', $item->id);

        $this->assertSame(0, ItensVenda::where('item_venda_venda_id', $this->venda->id)->count());
        $this->assertSame(0, AdicionaisItemVenda::where('aiv_item_venda_id', $item->id)->count());
        $this->assertSame(0.0, (float) $this->venda->fresh()->venda_valor_total);
    }
}
