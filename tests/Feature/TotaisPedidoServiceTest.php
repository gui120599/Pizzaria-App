<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\OpcoesEntregas;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosPedido;
use App\Models\Pedido;
use App\Models\Produto;
use App\Services\TotaisPedidoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TotaisPedidoServiceTest extends TestCase
{
    use RefreshDatabase;

    private function pedido(string $status = 'ABERTO', ?OpcoesEntregas $opcao = null): Pedido
    {
        return Pedido::create([
            'pedido_status' => $status,
            'pedido_opcaoentrega_id' => $opcao?->id,
            'pedido_valor_itens' => 1,
            'pedido_valor_total' => 1,
        ]);
    }

    private function item(Pedido $pedido, float $valor, float $desconto = 0, string $status = 'INSERIDO'): void
    {
        $produto = Produto::first() ?? Produto::create(['produto_descricao' => 'P', 'produto_categoria_id' => Categoria::firstOrCreate(['categoria_nome' => 'X'])->id, 'produto_tipo' => 'produzido', 'produto_preco_venda' => 1]);

        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $produto->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => $valor,
            'item_pedido_valor' => $valor,
            'item_pedido_desconto' => $desconto,
            'item_pedido_valor_adicionais' => 0,
            'item_pedido_status' => $status,
        ]);
    }

    public function test_recalcula_a_partir_dos_itens_inseridos_e_ignora_removidos(): void
    {
        $pedido = $this->pedido();
        $this->item($pedido, 40, 10);
        $this->item($pedido, 30);
        $this->item($pedido, 99, 0, 'REMOVIDO');

        app(TotaisPedidoService::class)->recalcular($pedido);

        $pedido->refresh();
        $this->assertSame(80.0, (float) $pedido->pedido_valor_itens);
        $this->assertSame(10.0, (float) $pedido->pedido_valor_desconto);
        $this->assertSame(70.0, (float) $pedido->pedido_valor_total);
    }

    public function test_cobra_frete_abaixo_do_minimo_e_zera_acima(): void
    {
        $opcao = OpcoesEntregas::create([
            'opcaoentrega_nome' => 'Entrega',
            'opcaoentrega_valor_frete' => 8,
            'opcaoentrega_min_valor_frete' => 50,
        ]);
        $pedido = $this->pedido('ABERTO', $opcao);
        $this->item($pedido, 30);

        app(TotaisPedidoService::class)->recalcular($pedido);
        $this->assertSame(8.0, (float) $pedido->refresh()->pedido_valor_frete);
        $this->assertSame(38.0, (float) $pedido->pedido_valor_total);

        $this->item($pedido, 30);
        app(TotaisPedidoService::class)->recalcular($pedido);
        $this->assertSame(0.0, (float) $pedido->refresh()->pedido_valor_frete);
        $this->assertSame(60.0, (float) $pedido->pedido_valor_total);
    }

    public function test_pedido_iniciado_nao_e_alterado(): void
    {
        $pedido = $this->pedido('INICIADO');
        $this->item($pedido, 40);

        app(TotaisPedidoService::class)->recalcular($pedido);

        $this->assertSame(1.0, (float) $pedido->refresh()->pedido_valor_total);
    }

    public function test_acompanha_um_pagamento_combinado_nao_pago(): void
    {
        $pedido = $this->pedido();
        $this->item($pedido, 40);
        $pagamento = $this->pagamento($pedido, 1);

        app(TotaisPedidoService::class)->recalcular($pedido);

        $this->assertSame(40.0, (float) $pagamento->refresh()->pg_pedido_valor);
    }

    public function test_nao_mexe_em_varias_linhas_nem_em_linha_paga(): void
    {
        $varias = $this->pedido();
        $this->item($varias, 40);
        $a = $this->pagamento($varias, 10);
        $b = $this->pagamento($varias, 10);

        $paga = $this->pedido();
        $this->item($paga, 40);
        $c = $this->pagamento($paga, 10, true);

        $service = app(TotaisPedidoService::class);
        $service->recalcular($varias);
        $service->recalcular($paga);

        $this->assertSame([10.0, 10.0, 10.0], [
            (float) $a->refresh()->pg_pedido_valor,
            (float) $b->refresh()->pg_pedido_valor,
            (float) $c->refresh()->pg_pedido_valor,
        ]);
    }

    public function test_nao_executa_update_quando_nada_mudou(): void
    {
        $pedido = $this->pedido();
        $this->item($pedido, 40);
        $service = app(TotaisPedidoService::class);
        $service->recalcular($pedido);

        DB::enableQueryLog();
        $service->recalcular($pedido);
        $updates = collect(DB::getQueryLog())->filter(fn ($q) => str_starts_with($q['query'], 'update'));
        DB::disableQueryLog();

        $this->assertCount(0, $updates);
    }

    private function pagamento(Pedido $pedido, float $valor, bool $pago = false): PagamentosPedido
    {
        $opcao = OpcoesPagamento::first() ?? OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_desc_nfe' => 'cash']);

        return PagamentosPedido::create([
            'pg_pedido_pedido_id' => $pedido->id,
            'pg_pedido_opcaopagamento_id' => $opcao->id,
            'pg_pedido_opcaopagamento_nome' => $opcao->opcaopag_nome,
            'pg_pedido_valor' => $valor,
            'pg_pedido_pago' => $pago,
            'pg_pedido_ordem' => 0,
        ]);
    }
}
