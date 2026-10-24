<?php

namespace Tests\Feature;

use App\Enums\MotivoCancelamentoEnum;
use App\Enums\ProdutoTipoEnum;
use App\Enums\StatusComissaoEnum;
use App\Enums\StatusInfluenciadorEnum;
use App\Enums\TipoDescontoEnum;
use App\Enums\TipoMovimentoCarteiraEnum;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Comissao;
use App\Models\Cupom;
use App\Models\Influenciador;
use App\Models\ItensPedido;
use App\Models\OpcoesEntregas;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\User;
use App\Models\Venda;
use App\Services\CancelamentoVendaService;
use App\Services\ComissaoService;
use App\Services\CupomPedidoService;
use App\Services\LancamentoItemPedidoService;
use App\Services\PedidoStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** F2.1: ciclo da comissão (pendente → creditada → estornada/cancelada) e base de cálculo. */
class ComissaoCicloCarteiraTest extends TestCase
{
    use RefreshDatabase;

    private Influenciador $influenciador;

    private Cupom $cupom;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        $this->influenciador = Influenciador::create([
            'influenciador_nome' => 'Fulano',
            'email' => uniqid().'@teste.com',
            'password' => 'senha-segura',
            'influenciador_tipo_comissao' => TipoDescontoEnum::PERCENTUAL->value,
            'influenciador_valor_comissao' => 10,
        ]);
        $this->influenciador->forceFill(['influenciador_status' => StatusInfluenciadorEnum::ATIVO])->save();

        $this->cupom = Cupom::create([
            'cupom_influenciador_id' => $this->influenciador->id,
            'cupom_codigo' => 'FULANO10',
            'cupom_tipo_desconto' => TipoDescontoEnum::PERCENTUAL->value,
            'cupom_valor_desconto' => 10,
        ]);
    }

    private function produto(float $preco): Produto
    {
        return Produto::create([
            'produto_descricao' => 'Produto '.uniqid(),
            'produto_categoria_id' => Categoria::firstOrCreate(['categoria_nome' => 'Pizzas'])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => $preco,
        ]);
    }

    /** Pedido de 200 em itens com o cupom de 10% (itens líquidos 180) e frete de 8. */
    private function pedidoComCupomEFrete(string $status = 'PREPARANDO'): Pedido
    {
        $entrega = OpcoesEntregas::create(['opcaoentrega_nome' => 'Entrega', 'opcaoentrega_valor_frete' => 8, 'opcaoentrega_min_valor_frete' => 0]);
        $pedido = Pedido::create([
            'pedido_cliente_id' => Cliente::create(['cliente_nome' => 'Cliente', 'cliente_tipo' => 'PF'])->id,
            'pedido_opcaoentrega_id' => $entrega->id,
            'pedido_status' => $status,
            'pedido_valor_total' => 188,
        ]);
        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $this->produto(200)->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 200,
            'item_pedido_valor' => 200,
            'item_pedido_desconto' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);

        app(CupomPedidoService::class)->aplicar($pedido, $this->cupom);

        return $pedido->fresh();
    }

    private function comissao(Pedido $pedido): Comissao
    {
        return Comissao::where('comissao_pedido_id', $pedido->id)->firstOrFail()->fresh();
    }

    public function test_base_do_credito_e_o_liquido_dos_itens_sem_frete(): void
    {
        $pedido = $this->pedidoComCupomEFrete();

        app(ComissaoService::class)->creditar($pedido);

        // Itens 200 − cupom 20 = 180; 10% = 18,00 (o frete de 8 nunca entra).
        $comissao = $this->comissao($pedido);
        $this->assertSame('180.00', $comissao->comissao_base_calculo);
        $this->assertSame('18.00', $comissao->comissao_valor);
        $this->assertSame(StatusComissaoEnum::CREDITADA, $comissao->comissao_status);
        $this->assertSame(18.0, $this->influenciador->fresh()->saldoCarteira());
    }

    public function test_base_acompanha_a_edicao_feita_antes_do_pagamento(): void
    {
        $pedido = $this->pedidoComCupomEFrete();
        $item = ItensPedido::where('item_pedido_pedido_id', $pedido->id)->sole();

        // O pedido dobra de quantidade antes de pagar: o crédito usa o valor atual, não o estimado.
        app(LancamentoItemPedidoService::class)->atualizarQuantidade($item, 2);
        app(ComissaoService::class)->creditar($pedido->fresh());

        $this->assertSame('360.00', $this->comissao($pedido)->comissao_base_calculo);
        $this->assertSame(36.0, $this->influenciador->fresh()->saldoCarteira());
    }

    public function test_cancelar_entregue_cancela_a_comissao_pendente(): void
    {
        $pedido = $this->pedidoComCupomEFrete('ENTREGUE');

        app(PedidoStatusService::class)->cancelarEntregue($pedido, auth()->user(), MotivoCancelamentoEnum::OUTRO, 'Venda desfeita');

        $this->assertSame(StatusComissaoEnum::CANCELADA, $this->comissao($pedido)->comissao_status);
        $this->assertSame(0.0, $this->influenciador->fresh()->saldoCarteira());
    }

    public function test_venda_reaberta_estorna_o_credito_e_creditar_de_novo_nao_duplica(): void
    {
        $pedido = $this->pedidoComCupomEFrete();
        $venda = Venda::create(['venda_status' => 'FINALIZADA', 'venda_valor_total' => 188]);
        ItensPedido::where('item_pedido_pedido_id', $pedido->id)->update(['item_pedido_venda_id' => $venda->id]);
        $pedido->update(['pedido_venda_id' => $venda->id]);
        $this->assertSame(StatusComissaoEnum::CREDITADA, $this->comissao($pedido)->comissao_status);
        $this->assertSame(18.0, $this->influenciador->fresh()->saldoCarteira());

        app(CancelamentoVendaService::class)->reabrirPedidosEMesas($venda);

        $comissao = $this->comissao($pedido);
        $this->assertSame(StatusComissaoEnum::PENDENTE, $comissao->comissao_status);
        $this->assertSame(0.0, $this->influenciador->fresh()->saldoCarteira());
        $this->assertSame(
            [TipoMovimentoCarteiraEnum::CREDITO_COMISSAO, TipoMovimentoCarteiraEnum::ESTORNO_COMISSAO],
            $this->influenciador->movimentosCarteira()->orderBy('id')->get()->pluck('cm_tipo')->all(),
        );

        // Pago de novo: credita outra vez, uma única vez.
        $pedido->fresh()->update(['pedido_venda_id' => $venda->id]);
        app(ComissaoService::class)->creditar($pedido->fresh());
        $this->assertSame(18.0, $this->influenciador->fresh()->saldoCarteira());
    }

    public function test_pedido_cancelado_estorna_comissao_ja_creditada(): void
    {
        $pedido = $this->pedidoComCupomEFrete();
        app(ComissaoService::class)->creditar($pedido);

        $pedido->fresh()->update(['pedido_status' => 'CANCELADO']);

        $this->assertSame(StatusComissaoEnum::ESTORNADA, $this->comissao($pedido)->comissao_status);
        $this->assertSame(0.0, $this->influenciador->fresh()->saldoCarteira());
    }

    public function test_pedido_pago_inteiro_com_a_carteira_nao_gera_comissao(): void
    {
        $pedido = $this->pedidoComCupomEFrete();
        $venda = Venda::create(['venda_status' => 'FINALIZADA', 'venda_valor_total' => 188]);
        $carteira = OpcoesPagamento::create(['opcaopag_nome' => 'Carteira de influenciador', 'opcaopag_carteira_influenciador' => true]);
        PagamentosVenda::create([
            'pg_venda_venda_id' => $venda->id,
            'pg_venda_opcaopagamento_id' => $carteira->id,
            'pg_venda_valor_pagamento' => 188,
        ]);

        app(ComissaoService::class)->creditar($pedido->fresh()->forceFill(['pedido_venda_id' => $venda->id]));

        $comissao = $this->comissao($pedido);
        $this->assertSame('0.00', $comissao->comissao_valor);
        $this->assertSame(0, $this->influenciador->movimentosCarteira()->count());
    }

    public function test_pagamento_parcial_com_carteira_reduz_a_base(): void
    {
        $pedido = $this->pedidoComCupomEFrete();
        $venda = Venda::create(['venda_status' => 'FINALIZADA', 'venda_valor_total' => 188]);
        $carteira = OpcoesPagamento::create(['opcaopag_nome' => 'Carteira de influenciador', 'opcaopag_carteira_influenciador' => true]);
        PagamentosVenda::create([
            'pg_venda_venda_id' => $venda->id,
            'pg_venda_opcaopagamento_id' => $carteira->id,
            'pg_venda_valor_pagamento' => 94,
        ]);

        app(ComissaoService::class)->creditar($pedido->fresh()->forceFill(['pedido_venda_id' => $venda->id]));

        // Metade da venda foi paga com a carteira: a base cai de 180 para 90.
        $this->assertSame('90.00', $this->comissao($pedido)->comissao_base_calculo);
        $this->assertSame('9.00', $this->comissao($pedido)->comissao_valor);
    }
}
