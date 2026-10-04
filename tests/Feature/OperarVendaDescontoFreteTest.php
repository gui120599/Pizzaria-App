<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Filament\Pages\OperarVenda;
use App\Models\Caixa;
use App\Models\Categoria;
use App\Models\ItensVenda;
use App\Models\Produto;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Models\Venda;
use App\Services\VendaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OperarVendaDescontoFreteTest extends TestCase
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
        ]);

        $this->venda = Venda::create([
            'venda_status' => 'INICIADA',
            'venda_sessao_caixa_id' => $sessaoCaixa->id,
        ]);
    }

    private function itemNaVenda(float $quantidade = 1): ItensVenda
    {
        $valor = $this->produto->produto_preco_venda * $quantidade;

        return ItensVenda::create([
            'item_numero' => 1,
            'item_venda_venda_id' => $this->venda->id,
            'item_venda_produto_id' => $this->produto->id,
            'item_venda_quantidade' => $quantidade,
            'item_venda_quantidade_tributavel' => $quantidade,
            'item_venda_valor_unitario' => $this->produto->produto_preco_venda,
            'item_venda_desconto' => 0,
            'item_venda_valor' => $valor,
            'item_venda_valor_base_calculo' => $valor,
            'item_venda_status' => 'INSERIDO',
        ]);
    }

    public function test_aplica_desconto_percentual_rateado_entre_os_itens(): void
    {
        $item1 = $this->itemNaVenda(1); // 10,00
        $item2 = $this->itemNaVenda(3); // 30,00

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('aplicarDescontoPercentual', 10);

        $this->assertSame(10.0, (float) $this->venda->fresh()->venda_desconto_percentual);
        // 10% de 40,00 = 4,00 rateado proporcionalmente: 1,00 e 3,00
        $this->assertSame(1.00, (float) $item1->fresh()->item_venda_desconto);
        $this->assertSame(3.00, (float) $item2->fresh()->item_venda_desconto);
        $this->assertSame(36.00, (float) $this->venda->fresh()->venda_valor_total);
    }

    public function test_bloqueia_reaplicacao_de_desconto_percentual(): void
    {
        $this->itemNaVenda(1);

        $component = Livewire::test(OperarVenda::class, ['venda' => $this->venda]);
        $component->call('aplicarDescontoPercentual', 10);
        $component->call('aplicarDescontoPercentual', 20);

        $this->assertSame(10.0, (float) $this->venda->fresh()->venda_desconto_percentual);
    }

    public function test_desfazer_desconto_percentual_restaura_valores(): void
    {
        $item = $this->itemNaVenda(1);

        $component = Livewire::test(OperarVenda::class, ['venda' => $this->venda]);
        $component->call('aplicarDescontoPercentual', 10);
        $component->call('desfazerDescontoPercentual');

        $this->assertNull($this->venda->fresh()->venda_desconto_percentual);
        $this->assertSame(0.0, (float) $item->fresh()->item_venda_desconto);
        $this->assertSame(10.00, (float) $this->venda->fresh()->venda_valor_total);
    }

    public function test_atualizar_frete_soma_ao_total(): void
    {
        $this->itemNaVenda(2); // 20,00
        // Em uso real, venda_valor_itens já está populado por VendaService
        // após a última mutação de item — aqui simula esse pré-estado.
        app(VendaService::class)->atualizarValoresdaVenda($this->venda->id);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('atualizarFrete', 5.00);

        $vendaFresh = $this->venda->fresh();
        $this->assertSame(5.00, (float) $vendaFresh->venda_valor_frete);
        $this->assertSame(25.00, (float) $vendaFresh->venda_valor_total);
    }

    public function test_atualizar_frete_sem_itens_usa_apenas_o_frete_como_total(): void
    {
        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('atualizarFrete', 7.50);

        $this->assertSame(7.50, (float) $this->venda->fresh()->venda_valor_total);
    }

    public function test_atualizar_frete_nao_desconta_de_novo_o_desconto_dos_itens(): void
    {
        $item = $this->itemNaVenda(2); // 20,00
        $item->update(['item_venda_desconto' => 4.00, 'item_venda_valor' => 16.00]);
        app(VendaService::class)->atualizarValoresdaVenda($this->venda->id);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('atualizarFrete', 5.00);

        $this->assertSame(21.00, (float) $this->venda->fresh()->venda_valor_total);
    }

    /**
     * Pizza ½ Calabresa (50,00) + ½ Portuguesa (60,00) pela média: 55,00. O
     * produto da linha é o 1º sabor (Calabresa).
     */
    private function pizzaDeSaboresNaVenda(): ItensVenda
    {
        $categoria = Categoria::create(['categoria_nome' => 'Pizzas']);
        $calabresa = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 50.00,
        ]);
        $portuguesa = Produto::create([
            'produto_descricao' => 'Portuguesa',
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 60.00,
        ]);

        return ItensVenda::create([
            'item_numero' => 1,
            'item_venda_venda_id' => $this->venda->id,
            'item_venda_produto_id' => $calabresa->id,
            'item_venda_quantidade' => 1,
            'item_venda_quantidade_tributavel' => 1,
            'item_venda_valor_unitario' => 55.00,
            'item_venda_desconto' => 0,
            'item_venda_valor' => 55.00,
            'item_venda_valor_base_calculo' => 55.00,
            'item_venda_sabores' => [
                ['produto_id' => $calabresa->id, 'nome' => 'Calabresa', 'percentual' => 50, 'valor_unitario' => 50.00],
                ['produto_id' => $portuguesa->id, 'nome' => 'Portuguesa', 'percentual' => 50, 'valor_unitario' => 60.00],
            ],
            'item_venda_status' => 'INSERIDO',
        ]);
    }

    public function test_desconto_em_pizza_de_sabores_parte_do_preco_da_pizza_e_nao_do_primeiro_sabor(): void
    {
        $pizza = $this->pizzaDeSaboresNaVenda();

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('atualizarDescontoItem', $pizza->id, 5.00);

        $this->assertSame(50.00, (float) $pizza->fresh()->item_venda_valor);
        $this->assertSame(50.00, (float) $this->venda->fresh()->venda_valor_total);
    }

    public function test_quantidade_e_desconto_percentual_em_pizza_de_sabores_mantem_o_preco_da_pizza(): void
    {
        $pizza = $this->pizzaDeSaboresNaVenda();

        $component = Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('atualizarQtdItem', $pizza->id, 2);

        $this->assertSame(110.00, (float) $pizza->fresh()->item_venda_valor);

        $component->call('aplicarDescontoPercentual', 10);
        $this->assertSame(99.00, (float) $this->venda->fresh()->venda_valor_total);

        $component->call('desfazerDescontoPercentual');
        $this->assertSame(110.00, (float) $this->venda->fresh()->venda_valor_total);
    }

    public function test_desconto_maior_que_o_item_e_recusado(): void
    {
        $item = $this->itemNaVenda(2); // 20,00

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('atualizarDescontoItem', $item->id, 25.00)
            ->assertNotified('O desconto passa do valor do item (R$ 20,00).');

        $this->assertSame(0.0, (float) $item->fresh()->item_venda_desconto);
        $this->assertSame(20.00, (float) $item->fresh()->item_venda_valor);
    }
}
