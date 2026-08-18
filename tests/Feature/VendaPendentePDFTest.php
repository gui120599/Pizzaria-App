<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Models\Caixa;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\ItensPedido;
use App\Models\ItensVenda;
use App\Models\Lancamento;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendaPendentePDFTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    private function produto(string $nome, float $preco = 10): Produto
    {
        $categoria = Categoria::firstOrCreate(['categoria_nome' => 'Teste']);

        return Produto::create([
            'produto_descricao' => $nome,
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => $preco,
        ]);
    }

    public function test_comprovante_mostra_pedido_avulso_e_produto_lancado_direto(): void
    {
        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);
        $sessaoCaixa = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => $caixa->id, 'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now(), 'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => auth()->id(),
        ]);
        $cliente = Cliente::create(['cliente_nome' => 'João', 'cliente_tipo' => 'PF']);
        $pizza = $this->produto('Pizza Grande', 30);
        $refrigerante = $this->produto('Refrigerante', 10);

        $venda = Venda::create([
            'venda_status' => 'FINALIZADA', 'venda_sessao_caixa_id' => $sessaoCaixa->id,
            'venda_cliente_id' => $cliente->id, 'venda_valor_total' => 40, 'venda_valor_pago' => 0,
        ]);

        // Pedido avulso: 1 Pizza, lançado na venda.
        $pedido = Pedido::create(['pedido_status' => 'FINALIZADO', 'pedido_venda_id' => $venda->id]);
        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id, 'item_pedido_produto_id' => $pizza->id,
            'item_pedido_quantidade' => 1, 'item_pedido_valor' => 30, 'item_pedido_status' => 'INSERIDO',
            'item_pedido_venda_id' => $venda->id,
        ]);

        // ItensVenda: soma da pizza do pedido (30) + o refrigerante lançado direto pela aba Produtos (10).
        ItensVenda::create([
            'item_numero' => 1, 'item_venda_venda_id' => $venda->id, 'item_venda_produto_id' => $pizza->id,
            'item_venda_quantidade' => 1, 'item_venda_quantidade_tributavel' => 1,
            'item_venda_valor_unitario' => 30, 'item_venda_valor' => 30, 'item_venda_status' => 'INSERIDO',
        ]);
        ItensVenda::create([
            'item_numero' => 2, 'item_venda_venda_id' => $venda->id, 'item_venda_produto_id' => $refrigerante->id,
            'item_venda_quantidade' => 1, 'item_venda_quantidade_tributavel' => 1,
            'item_venda_valor_unitario' => 10, 'item_venda_valor' => 10, 'item_venda_status' => 'INSERIDO',
        ]);

        $lancamento = Lancamento::create([
            'tipo' => TipoLancamento::Receber, 'venda_id' => $venda->id, 'cliente_id' => $cliente->id,
            'descricao' => 'fiado', 'valor' => 40, 'vencimento' => now()->addDays(7), 'status' => StatusLancamento::Pendente,
        ]);

        $this->get(route('lancamento.imprimir_fiado', ['id' => $lancamento->id]))
            ->assertOk()
            ->assertSee('João')
            ->assertSee('Pedido #'.$pedido->id)
            ->assertSee('Pizza Grande')
            ->assertSee('Produtos lançados direto')
            ->assertSee('Refrigerante')
            ->assertSee('40,00');
    }
}
