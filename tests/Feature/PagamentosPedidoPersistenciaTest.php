<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Filament\Pages\AtenderPedido;
use App\Models\Categoria;
use App\Models\MovimentacoesSessaoCaixa;
use App\Models\OpcoesPagamento;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * O split de pagamento combinado (EntregaPagamentoPicker + pagamentos_pedidos)
 * é dado informativo/planejado — nunca uma cobrança real. Este é o teste mais
 * importante da reescrita: prova que criar/editar um Pedido com split de
 * pagamento não vaza pro domínio financeiro (Venda/SessaoCaixa/
 * MovimentacoesSessaoCaixa), que continua território exclusivo do OperarVenda.
 */
class PagamentosPedidoPersistenciaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    public function test_split_de_duas_linhas_grava_certo_sem_tocar_no_dominio_financeiro(): void
    {
        $categoria = Categoria::create(['categoria_nome' => 'Pizzas']);
        $produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 100.0,
        ]);
        $dinheiro = OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_desc_nfe' => 'cash']);
        $cartao = OpcoesPagamento::create(['opcaopag_nome' => 'Cartão', 'opcaopag_desc_nfe' => 'creditCard']);

        Livewire::test(AtenderPedido::class)
            ->set('itensCarrinho', [[
                'produto_id' => $produto->id,
                'quantidade' => 1,
                'valor_unitario' => 100.0,
                'desconto' => 0,
                'valor' => 100.0,
                'adicionais_valor' => 0,
                'observacao' => '',
                'adicionais' => [],
            ]])
            ->set('clienteData', ['semCliente' => true])
            ->set('entregaPagamentoData', [
                'opcaoEntregaId' => null,
                'pagamentos' => [
                    ['opcaoPagamentoId' => $dinheiro->id, 'valor' => '40,00', 'trocoPara' => '50,00'],
                    ['opcaoPagamentoId' => $cartao->id, 'valor' => '60,00', 'trocoPara' => null],
                ],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $pedido = Pedido::latest('id')->firstOrFail();
        $linhas = $pedido->pagamentosCombinados()->orderBy('pg_pedido_ordem')->get();

        $this->assertCount(2, $linhas);
        $this->assertSame($dinheiro->id, $linhas[0]->pg_pedido_opcaopagamento_id);
        $this->assertEqualsWithDelta(40.0, (float) $linhas[0]->pg_pedido_valor, 0.01);
        $this->assertEqualsWithDelta(50.0, (float) $linhas[0]->pg_pedido_valor_troco_para, 0.01);
        $this->assertSame($cartao->id, $linhas[1]->pg_pedido_opcaopagamento_id);
        $this->assertEqualsWithDelta(60.0, (float) $linhas[1]->pg_pedido_valor, 0.01);
        $this->assertNull($linhas[1]->pg_pedido_valor_troco_para);

        // Nenhum registro financeiro real foi tocado — pagamento aqui é só combinado.
        $this->assertSame(0, Venda::count());
        $this->assertSame(0, SessaoCaixa::count());
        $this->assertSame(0, MovimentacoesSessaoCaixa::count());
    }
}
