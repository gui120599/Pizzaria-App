<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Models\AdicionaisItemPedido;
use App\Models\Adicional;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\ItensVenda;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\User;
use App\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * O adicional é sempre cobrado pela sua própria quantidade (1 porção),
 * independente da fração do produto (inteira, meia, terço...). Bug: ao
 * importar um item de pedido com quantidade 0,5 para a venda, o valor do
 * adicional era dividido por 2 (compensação de uma duplicação que a tela de
 * pedido não faz mais); e ao mudar quantidade/desconto de um item já na
 * venda, o total recalculado descartava o valor dos adicionais por completo.
 */
class ItensVendaAdicionalFracaoTest extends TestCase
{
    use RefreshDatabase;

    private Produto $produto;

    private Adicional $adicional;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['name_first' => 'Admin']));

        $categoria = Categoria::create(['categoria_nome' => 'Pizzas']);
        $this->adicional = Adicional::create(['adicional_nome' => 'Bacon', 'adicional_valor' => 5.00]);
        $this->produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 55.00,
        ]);
    }

    private function pedidoComItem(float $quantidade, float $valorAdicionais): ItensPedido
    {
        $pedido = Pedido::create(['pedido_status' => 'ABERTO']);

        $valorUnitario = 55.00;
        $valor = round(($valorUnitario * $quantidade) + $valorAdicionais, 2);

        $item = ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $this->produto->id,
            'item_pedido_quantidade' => $quantidade,
            'item_pedido_valor_unitario' => $valorUnitario,
            'item_pedido_valor_adicionais' => $valorAdicionais,
            'item_pedido_valor' => $valor,
            'item_pedido_desconto' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);

        AdicionaisItemPedido::create([
            'aip_item_pedido_id' => $item->id,
            'aip_adicional_id' => $this->adicional->id,
            'aip_quantidade' => 1,
            'aip_valor_unitario' => $valorAdicionais,
            'aip_valor_total' => $valorAdicionais,
        ]);

        return $item;
    }

    #[DataProvider('fracoesDeProduto')]
    public function test_importar_item_de_pedido_preserva_valor_integral_do_adicional(float $quantidade): void
    {
        $item = $this->pedidoComItem($quantidade, 5.00);
        $venda = Venda::create(['venda_status' => 'INICIADA']);

        $this->post(route('item_venda.add_item_pedido'), [
            'pedido_id' => $item->item_pedido_pedido_id,
            'venda_id' => $venda->id,
        ])->assertOk();

        $itemVenda = ItensVenda::where('item_venda_venda_id', $venda->id)->firstOrFail();

        $this->assertSame(5.00, (float) $itemVenda->item_venda_valor_adicionais);
        $this->assertSame($item->item_pedido_valor, (float) $itemVenda->item_venda_valor);
    }

    public static function fracoesDeProduto(): array
    {
        return [
            'meia (0,5)' => [0.5],
            'terço (0,3333)' => [0.3333],
            'inteira (1)' => [1.0],
        ];
    }

    public function test_alterar_quantidade_do_item_na_venda_mantem_o_adicional_no_total(): void
    {
        $venda = Venda::create(['venda_status' => 'INICIADA']);
        $itemVenda = ItensVenda::create([
            'item_numero' => 1,
            'item_venda_venda_id' => $venda->id,
            'item_venda_produto_id' => $this->produto->id,
            'item_venda_quantidade' => 1,
            'item_venda_quantidade_tributavel' => 1,
            'item_venda_valor_unitario' => 55.00,
            'item_venda_valor_adicionais' => 5.00,
            'item_venda_desconto' => 0,
            'item_venda_valor' => 60.00,
            'item_venda_status' => 'INSERIDO',
        ]);

        $this->post(route('item_venda.update_qtd_valor'), [
            'item_id' => $itemVenda->id,
            'venda_id' => $venda->id,
            'item_venda_quantidade' => 2,
        ])->assertOk();

        // 55*2 + 5 (adicional cheio, não escalado) - 0 desconto = 115
        $this->assertSame(115.00, (float) $itemVenda->fresh()->item_venda_valor);
    }

    public function test_alterar_desconto_do_item_na_venda_mantem_o_adicional_no_total(): void
    {
        $venda = Venda::create(['venda_status' => 'INICIADA']);
        $itemVenda = ItensVenda::create([
            'item_numero' => 1,
            'item_venda_venda_id' => $venda->id,
            'item_venda_produto_id' => $this->produto->id,
            'item_venda_quantidade' => 1,
            'item_venda_quantidade_tributavel' => 1,
            'item_venda_valor_unitario' => 55.00,
            'item_venda_valor_adicionais' => 5.00,
            'item_venda_desconto' => 0,
            'item_venda_valor' => 60.00,
            'item_venda_status' => 'INSERIDO',
        ]);

        $this->post(route('item_venda.update_desconto'), [
            'item_id' => $itemVenda->id,
            'venda_id' => $venda->id,
            'item_desconto' => 10.00,
        ])->assertOk();

        // 55*1 + 5 (adicional cheio) - 10 desconto = 50
        $this->assertSame(50.00, (float) $itemVenda->fresh()->item_venda_valor);
    }
}
