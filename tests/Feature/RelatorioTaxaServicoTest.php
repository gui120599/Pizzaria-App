<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Filament\Pages\RelatorioTaxaServico;
use App\Filament\Widgets\TaxaServicoDetalhamentoWidget;
use App\Filament\Widgets\TaxaServicoPorGarcomWidget;
use App\Filament\Widgets\TaxaServicoStatsOverview;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\SessaoMesa;
use App\Models\User;
use App\Models\Venda;
use App\Services\RelatorioTaxaServicoService;
use App\Services\SessaoMesaService;
use App\Services\VendaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Taxa de serviço por garçom: repartida pelo consumo das rodadas de cada um,
 * somando no centavo a taxa cobrada na venda.
 */
class RelatorioTaxaServicoTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private User $bruno;

    private Produto $produto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ana = User::factory()->garcom()->create(['name' => 'Ana', 'name_first' => 'Ana']);
        $this->bruno = User::factory()->garcom()->create(['name' => 'Bruno', 'name_first' => 'Bruno']);
        $this->produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Pizzas'])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 50.00,
        ]);
    }

    private function sessao(User $garcom, string $mesa): SessaoMesa
    {
        $mesa = Mesa::create(['mesa_nome' => $mesa, 'mesa_status' => 'LIBERADA']);

        return app(SessaoMesaService::class)->abrir($mesa->id, $garcom->id, taxaServicoPercentual: 10.0);
    }

    private function rodada(SessaoMesa $sessao, ?User $garcom, float $valor): void
    {
        $pedido = Pedido::create([
            'pedido_sessao_mesa_id' => $sessao->id,
            'pedido_usuario_garcom_id' => $garcom?->id,
            'pedido_status' => 'ENTREGUE',
        ]);

        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $this->produto->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => $valor,
            'item_pedido_valor' => $valor,
            'item_pedido_desconto' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);
    }

    /** Venda FINALIZADA com todos os itens da sessão (taxa calculada pelo VendaService). */
    private function vendaFinalizada(SessaoMesa $sessao, bool $taxaRemovida = false): Venda
    {
        $venda = Venda::create([
            'venda_status' => 'INICIADA',
            'venda_taxa_servico_removida' => $taxaRemovida,
        ]);

        ItensPedido::whereHas('pedido', fn ($q) => $q->where('pedido_sessao_mesa_id', $sessao->id))
            ->update(['item_pedido_venda_id' => $venda->id]);

        app(VendaService::class)->atualizarValoresdaVenda($venda->id);
        $venda->update(['venda_status' => 'FINALIZADA', 'venda_datahora_finalizada' => now()]);

        return $venda->fresh();
    }

    public function test_reparte_a_taxa_pelas_rodadas_de_cada_garcom_e_soma_igual_a_taxa_cobrada(): void
    {
        $sessao = $this->sessao($this->ana, 'Mesa 1');
        $this->rodada($sessao, $this->ana, 50.00);
        $this->rodada($sessao, $this->bruno, 33.35);
        // Rodada lançada sem garçom (PDV) fica com quem abriu a mesa.
        $this->rodada($sessao, null, 10.00);
        $venda = $this->vendaFinalizada($sessao);

        $porGarcom = (new RelatorioTaxaServicoService)->porGarcom()->keyBy('garcom_id');

        $this->assertEquals(60.00, $porGarcom[$this->ana->id]['consumo']);
        $this->assertEquals(33.35, $porGarcom[$this->bruno->id]['consumo']);
        $this->assertEqualsWithDelta(6.00, $porGarcom[$this->ana->id]['taxa'], 0.011);
        $this->assertEqualsWithDelta(3.34, $porGarcom[$this->bruno->id]['taxa'], 0.011);
        $this->assertEquals(
            (float) $venda->venda_valor_taxa_servico,
            round($porGarcom[$this->ana->id]['taxa'] + $porGarcom[$this->bruno->id]['taxa'], 2),
        );
    }

    public function test_por_sessao_a_taxa_da_mesa_inteira_vai_para_quem_abriu(): void
    {
        $sessao = $this->sessao($this->ana, 'Mesa 1');
        $this->rodada($sessao, $this->ana, 60.00);
        $this->rodada($sessao, $this->bruno, 40.00);
        $this->vendaFinalizada($sessao);

        $porGarcom = (new RelatorioTaxaServicoService(['atribuicao' => 'sessao']))->porGarcom();

        $this->assertCount(1, $porGarcom);
        $this->assertSame($this->ana->id, $porGarcom->first()['garcom_id']);
        $this->assertEquals(10.00, $porGarcom->first()['taxa']);
    }

    public function test_detalhamento_agrupa_o_que_cada_garcom_recebeu_na_venda(): void
    {
        // Duas mesas pagas na mesma venda, com rodadas da Ana nas duas.
        $mesa1 = $this->sessao($this->ana, 'Mesa 1');
        $this->rodada($mesa1, $this->ana, 50.00);
        $this->rodada($mesa1, $this->bruno, 30.00);
        $mesa2 = $this->sessao($this->bruno, 'Mesa 2');
        $this->rodada($mesa2, $this->ana, 20.00);

        $venda = Venda::create(['venda_status' => 'INICIADA']);
        ItensPedido::query()->update(['item_pedido_venda_id' => $venda->id]);
        app(VendaService::class)->atualizarValoresdaVenda($venda->id);
        $venda->update(['venda_status' => 'FINALIZADA', 'venda_datahora_finalizada' => now()]);

        $porVenda = (new RelatorioTaxaServicoService)->porVenda()->keyBy('garcom_id');

        $this->assertCount(2, $porVenda);
        $this->assertSame('Mesa 1, Mesa 2', $porVenda[$this->ana->id]['mesas']);
        $this->assertEquals(70.00, $porVenda[$this->ana->id]['consumo']);
        $this->assertEquals(7.00, $porVenda[$this->ana->id]['taxa']);
        $this->assertEquals(3.00, $porVenda[$this->bruno->id]['taxa']);
    }

    public function test_venda_com_taxa_tirada_no_caixa_fica_fora_e_filtro_por_garcom(): void
    {
        $sessaoAna = $this->sessao($this->ana, 'Mesa 1');
        $this->rodada($sessaoAna, $this->ana, 100.00);
        $this->vendaFinalizada($sessaoAna);

        $sessaoSemTaxa = $this->sessao($this->ana, 'Mesa 2');
        $this->rodada($sessaoSemTaxa, $this->ana, 80.00);
        $this->vendaFinalizada($sessaoSemTaxa, taxaRemovida: true);

        $sessaoBruno = $this->sessao($this->bruno, 'Mesa 3');
        $this->rodada($sessaoBruno, $this->bruno, 40.00);
        $this->vendaFinalizada($sessaoBruno);

        $this->assertSame(
            ['taxa' => 14.0, 'consumo' => 140.0, 'mesas' => 2, 'garcons' => 2],
            (new RelatorioTaxaServicoService)->totais(),
        );

        $soDaAna = new RelatorioTaxaServicoService(['garcom_id' => $this->ana->id]);
        $this->assertSame(['Mesa 1'], $soDaAna->linhas()->pluck('mesa')->all());
        $this->assertEquals(10.00, $soDaAna->totais()['taxa']);
    }

    public function test_impressao_mostra_o_total_por_garcom(): void
    {
        $sessao = $this->sessao($this->ana, 'Mesa 1');
        $this->rodada($sessao, $this->ana, 100.00);
        $this->vendaFinalizada($sessao);

        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']))
            ->get(route('relatorios.taxa_servico.imprimir'))
            ->assertOk()
            ->assertSeeText('Ana')
            ->assertSeeText('R$ 10,00');
    }

    public function test_pagina_e_widgets_do_relatorio_renderizam(): void
    {
        $sessao = $this->sessao($this->ana, 'Mesa 1');
        $this->rodada($sessao, $this->ana, 100.00);
        $this->vendaFinalizada($sessao);
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        Livewire::test(RelatorioTaxaServico::class)->assertOk();
        Livewire::test(TaxaServicoStatsOverview::class)->assertSeeText('R$ 10,00');
        Livewire::test(TaxaServicoPorGarcomWidget::class)->assertSeeText('Ana');
        Livewire::test(TaxaServicoDetalhamentoWidget::class)->assertSeeText('Mesa 1');
    }
}
