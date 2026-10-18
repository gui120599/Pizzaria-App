<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\Pedido;
use App\Models\Produto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditarTotaisPedidosCommandTest extends TestCase
{
    use RefreshDatabase;

    private function divergente(float $frete = 0): Pedido
    {
        $produto = Produto::create(['produto_descricao' => 'P', 'produto_categoria_id' => Categoria::firstOrCreate(['categoria_nome' => 'X'])->id, 'produto_tipo' => 'produzido', 'produto_preco_venda' => 1]);
        $pedido = Pedido::create([
            'pedido_status' => 'ENTREGUE',
            'pedido_valor_itens' => 100,
            'pedido_valor_frete' => $frete,
            'pedido_valor_total' => 100 + $frete,
        ]);
        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $produto->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 126,
            'item_pedido_valor' => 126,
            'item_pedido_desconto' => 0,
            'item_pedido_valor_adicionais' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);

        return $pedido;
    }

    public function test_dry_run_nao_altera_nada(): void
    {
        $pedido = $this->divergente();

        $this->artisan('pedidos:auditar-totais')->assertSuccessful();

        $this->assertSame(100.0, (float) $pedido->refresh()->pedido_valor_total);
    }

    public function test_corrigir_exige_ids(): void
    {
        $this->artisan('pedidos:auditar-totais', ['--corrigir' => true])->assertFailed();
    }

    public function test_corrigir_so_os_ids_informados_e_preserva_o_frete(): void
    {
        $alvo = $this->divergente(7);
        $outro = $this->divergente();

        $this->artisan('pedidos:auditar-totais', ['--corrigir' => true, '--ids' => [$alvo->id]])->assertSuccessful();

        $alvo->refresh();
        $this->assertSame(126.0, (float) $alvo->pedido_valor_itens);
        $this->assertSame(7.0, (float) $alvo->pedido_valor_frete);
        $this->assertSame(133.0, (float) $alvo->pedido_valor_total);
        $this->assertSame(100.0, (float) $outro->refresh()->pedido_valor_total);
    }
}
