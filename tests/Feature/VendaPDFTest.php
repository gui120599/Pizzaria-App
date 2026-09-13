<?php

namespace Tests\Feature;

use App\Models\AdicionaisItemVenda;
use App\Models\Adicional;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\ItensVenda;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\Produto;
use App\Models\User;
use App\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Comprovante de Venda finalizada (route('venda.imprimir') -> PDFController::vendaPDF),
 * espelhando pedidoPDF.blade.php. Ligado ao botão "Imprimir" do
 * VendasRelationManager do ClienteResource.
 */
class VendaPDFTest extends TestCase
{
    use RefreshDatabase;

    public function test_venda_pdf_renderiza_itens_adicionais_e_pagamento(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        $cliente = Cliente::create(['cliente_nome' => 'Cliente Teste', 'cliente_celular' => '11999998888', 'cliente_tipo' => 'Física']);
        $categoria = Categoria::create(['categoria_nome' => 'Pizzas']);
        $produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => 'produzido',
            'produto_preco_venda' => 50.0,
        ]);
        $adicional = Adicional::create(['adicional_nome' => 'Borda Recheada', 'adicional_preco' => 10]);

        $venda = Venda::create([
            'venda_cliente_id' => $cliente->id,
            'venda_status' => 'FINALIZADA',
            'venda_valor_itens' => 60,
            'venda_valor_total' => 60,
            'venda_valor_pago' => 60,
            'venda_datahora_finalizada' => now(),
        ]);

        $item = ItensVenda::create([
            'item_numero' => 1,
            'item_venda_venda_id' => $venda->id,
            'item_venda_produto_id' => $produto->id,
            'item_venda_quantidade' => 1,
            'item_venda_quantidade_tributavel' => 1,
            'item_venda_valor_unitario' => 60,
            'item_venda_desconto' => 0,
            'item_venda_valor' => 60,
            'item_venda_valor_base_calculo' => 60,
            'item_venda_status' => 'INSERIDO',
        ]);

        AdicionaisItemVenda::create([
            'aiv_item_venda_id' => $item->id,
            'aiv_adicional_id' => $adicional->id,
            'aiv_quantidade' => 1,
            'aiv_valor_unitario' => 10,
            'aiv_valor_total' => 10,
        ]);

        $opcao = OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_desc_nfe' => 'cash']);
        PagamentosVenda::create([
            'pg_venda_venda_id' => $venda->id,
            'pg_venda_opcaopagamento_id' => $opcao->id,
            'pg_venda_valor_pagamento' => 60,
        ]);

        $response = $this->get(route('venda.imprimir', ['id' => $venda->id]));
        $response->assertOk();
        $response->assertSee('Comprovante de Venda');
        $response->assertSee('Calabresa', false);
        $response->assertSee('Borda Recheada', false);
    }
}
