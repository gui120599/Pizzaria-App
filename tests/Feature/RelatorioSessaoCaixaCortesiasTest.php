<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Filament\Widgets\SessaoCaixaCortesiasWidget;
use App\Filament\Widgets\SessaoCaixaStatsOverview;
use App\Models\Caixa;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\ItensVenda;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Models\Venda;
use App\Services\CortesiaService;
use App\Services\LancamentoItensVendaService;
use App\Services\RelatorioSessaoCaixaService;
use App\Services\VendaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cortesia no Relatório de Sessão de Caixa: aparece à parte (tabela e custo),
 * com motivo e autorizador, e fica fora do vendido e do recebido.
 */
class RelatorioSessaoCaixaCortesiasTest extends TestCase
{
    use RefreshDatabase;

    private SessaoCaixa $sessao;

    private Venda $venda;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::factory()->admin()->create(['name_first' => 'Ana', 'name' => 'Ana']);
        $this->actingAs($admin);

        $this->sessao = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => Caixa::create(['caixa_nome' => 'Caixa 1'])->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now()->subHours(2),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => $admin->id,
        ]);

        $categoria = Categoria::create(['categoria_nome' => 'Geral']);
        $pizza = Produto::create(['produto_descricao' => 'Calabresa', 'produto_categoria_id' => $categoria->id, 'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value, 'produto_preco_venda' => 40, 'produto_custo_medio' => 12.5]);
        $refri = Produto::create(['produto_descricao' => 'Refrigerante', 'produto_categoria_id' => $categoria->id, 'produto_tipo' => ProdutoTipoEnum::REVENDA->value, 'produto_preco_venda' => 10]);

        $this->venda = Venda::create(['venda_status' => 'INICIADA', 'venda_sessao_caixa_id' => $this->sessao->id, 'venda_datahora_iniciada' => now()]);

        $itens = collect([[$pizza, 40], [$refri, 10]])->map(function (array $dados) {
            $pedido = Pedido::create(['pedido_status' => 'ENTREGUE', 'pedido_valor_total' => $dados[1]]);

            return ItensPedido::create([
                'item_pedido_pedido_id' => $pedido->id, 'item_pedido_produto_id' => $dados[0]->id,
                'item_pedido_quantidade' => 1, 'item_pedido_valor_unitario' => $dados[1], 'item_pedido_desconto' => 0,
                'item_pedido_valor_adicionais' => 0, 'item_pedido_valor' => $dados[1], 'item_pedido_status' => 'INSERIDO',
            ]);
        });

        app(CortesiaService::class)->concederItem($itens[0], $admin, null, null, 'Cliente do dia');
        app(LancamentoItensVendaService::class)->lancarItens($this->venda, $itens->map->fresh());
        app(VendaService::class)->atualizarValoresdaVenda($this->venda->id);

        $pix = OpcoesPagamento::create(['opcaopag_nome' => 'Pix', 'opcaopag_desc_nfe' => 'InstantPayment']);
        PagamentosVenda::create(['pg_venda_venda_id' => $this->venda->id, 'pg_venda_opcaopagamento_id' => $pix->id, 'pg_venda_valor_pagamento' => 10, 'pg_venda_valor_pago_pelo_cliente' => 10]);
        app(VendaService::class)->atualizarValoresdaVenda($this->venda->id);
        $this->venda->update(['venda_status' => 'FINALIZADA', 'venda_datahora_finalizada' => now()]);
    }

    public function test_resumo_e_linhas_trazem_a_cortesia_fora_do_vendido(): void
    {
        $relatorio = new RelatorioSessaoCaixaService(['sessoes' => [$this->sessao->id]]);
        $resumo = $relatorio->resumo();

        $this->assertEqualsWithDelta(10.0, $resumo['total'], 0.001);
        $this->assertSame(1, $resumo['cortesias']);
        $this->assertEqualsWithDelta(40.0, $resumo['cortesias_valor'], 0.001);
        $this->assertEqualsWithDelta(12.5, $resumo['cortesias_custo'], 0.001);

        $linha = $relatorio->cortesias()->first();
        $this->assertSame('Calabresa', $linha['produto']);
        $this->assertSame('Cliente do dia', $linha['motivo']);
        $this->assertSame('Ana', $linha['autorizador']);
        $this->assertSame($this->venda->id, $linha['venda_id']);
    }

    public function test_widgets_e_impressao_mostram_a_cortesia(): void
    {
        $filtros = ['sessoes' => [$this->sessao->id]];

        Livewire::test(SessaoCaixaStatsOverview::class, ['pageFilters' => $filtros])->assertSeeText('R$ 40,00');
        Livewire::test(SessaoCaixaCortesiasWidget::class, ['pageFilters' => $filtros])
            ->assertSeeText('Calabresa')
            ->assertSeeText('Cliente do dia');

        $this->get(route('relatorios.sessao_caixa.imprimir', ['filters' => $filtros]))
            ->assertOk()
            ->assertSeeText('Cortesias')
            ->assertSeeText('Cliente do dia');
    }

    public function test_linha_avulsa_do_caixa_tambem_aparece(): void
    {
        $refri = Produto::where('produto_descricao', 'Refrigerante')->first();
        $linha = ItensVenda::create([
            'item_numero' => 9, 'item_venda_venda_id' => $this->venda->id, 'item_venda_produto_id' => $refri->id,
            'item_venda_quantidade' => 2, 'item_venda_valor_unitario' => 10, 'item_venda_valor_adicionais' => 0,
            'item_venda_desconto' => 0, 'item_venda_valor' => 20, 'item_venda_status' => 'INSERIDO',
            'item_venda_quantidade_tributavel' => 2, 'item_venda_valor_unitario_tributavel' => 10, 'item_venda_valor_base_calculo' => 20,
        ]);
        app(CortesiaService::class)->alternarLinhaDaVenda($linha, auth()->user(), null, null, 'Dono pediu');

        $cortesias = (new RelatorioSessaoCaixaService(['sessoes' => [$this->sessao->id]]))->cortesias();

        $this->assertCount(2, $cortesias);
        $this->assertSame('Dono pediu', $cortesias->firstWhere('produto', 'Refrigerante')['motivo']);
    }
}
