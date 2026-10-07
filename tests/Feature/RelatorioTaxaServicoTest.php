<?php

namespace Tests\Feature;

use App\Enums\OperadoraMaquininha;
use App\Enums\ProdutoTipoEnum;
use App\Filament\Pages\RelatorioTaxaServico;
use App\Filament\Widgets\TaxaServicoDetalhamentoWidget;
use App\Filament\Widgets\TaxaServicoPorGarcomWidget;
use App\Filament\Widgets\TaxaServicoStatsOverview;
use App\Models\Categoria;
use App\Models\Empresa;
use App\Models\ItensPedido;
use App\Models\Maquininha;
use App\Models\MaquininhaTaxa;
use App\Models\Mesa;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
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

    public function test_detalhamento_lista_as_rodadas_da_venda_com_garcom_taxa_e_total(): void
    {
        // Duas mesas pagas na mesma venda.
        $mesa1 = $this->sessao($this->ana, 'Mesa 1');
        $this->rodada($mesa1, $this->ana, 50.00);
        $this->rodada($mesa1, $this->bruno, 30.00);
        $mesa2 = $this->sessao($this->bruno, 'Mesa 2');
        $this->rodada($mesa2, $this->ana, 20.00);

        $venda = Venda::create(['venda_status' => 'INICIADA']);
        ItensPedido::query()->update(['item_pedido_venda_id' => $venda->id]);
        app(VendaService::class)->atualizarValoresdaVenda($venda->id);
        $venda->update(['venda_status' => 'FINALIZADA', 'venda_datahora_finalizada' => now()]);

        $porVenda = (new RelatorioTaxaServicoService)->porVenda();

        $this->assertCount(1, $porVenda);
        $linha = $porVenda->first();
        $this->assertSame('Mesa 1, Mesa 2', $linha['mesas']);
        $this->assertSame(
            [['Mesa 1', 'Ana', 5.0], ['Mesa 1', 'Bruno', 3.0], ['Mesa 2', 'Ana', 2.0]],
            collect($linha['rodadas'])->map(fn (array $r): array => [$r['mesa'], $r['garcom'], $r['taxa']])->all(),
        );
        $this->assertEquals(100.00, $linha['consumo']);
        $this->assertEquals((float) $venda->fresh()->venda_valor_taxa_servico, $linha['taxa']);
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
            ['consumo' => 140.0, 'taxa' => 14.0, 'desconto_maquininha' => 0.0, 'desconto_imposto' => 0.0, 'taxa_liquida' => 14.0, 'mesas' => 2, 'garcons' => 2],
            (new RelatorioTaxaServicoService)->totais(),
        );

        $soDaAna = new RelatorioTaxaServicoService(['garcom_id' => $this->ana->id]);
        $this->assertSame(['Mesa 1'], $soDaAna->linhas()->pluck('mesa')->all());
        $this->assertEquals(10.00, $soDaAna->totais()['taxa']);
    }

    public function test_mesas_sem_taxa_contam_sessao_sem_taxa_e_taxa_tirada_no_caixa(): void
    {
        config(['pizzaria.salao.taxa_servico_percentual' => 10]);

        // Sessão de antes do recurso de taxa (percentual 0 por padrão): fora.
        $antiga = $this->sessao($this->ana, 'Mesa 0');
        $antiga->forceFill(['sessao_mesa_taxa_servico_percentual' => 0, 'created_at' => now()->subDay()])->save();
        $this->rodada($antiga, $this->ana, 200.00);
        $this->vendaFinalizada($antiga);

        $comTaxa = $this->sessao($this->ana, 'Mesa 1');
        $this->rodada($comTaxa, $this->ana, 100.00);
        $this->vendaFinalizada($comTaxa);

        $semTaxa = $this->sessao($this->ana, 'Mesa 2');
        $semTaxa->update(['sessao_mesa_taxa_servico_percentual' => 0]);
        $this->rodada($semTaxa, $this->ana, 80.00);
        $this->vendaFinalizada($semTaxa);

        $tiradaNoCaixa = $this->sessao($this->bruno, 'Mesa 3');
        $this->rodada($tiradaNoCaixa, $this->bruno, 50.00);
        $this->vendaFinalizada($tiradaNoCaixa, taxaRemovida: true);

        $this->assertSame(['mesas' => 2, 'valor' => 13.0], (new RelatorioTaxaServicoService)->semTaxa());
        $this->assertSame(['mesas' => 1, 'valor' => 5.0], (new RelatorioTaxaServicoService(['garcom_id' => $this->bruno->id]))->semTaxa());
    }

    /** Pagamento no crédito, na maquininha com taxa de 3% no crédito. */
    private function pagarNoCredito(Venda $venda, float $valor, bool $comTaxaCadastrada = true): PagamentosVenda
    {
        $maquininha = Maquininha::firstOrCreate(['nome' => 'Stone 1'], ['operadora' => OperadoraMaquininha::Stone]);
        if ($comTaxaCadastrada) {
            MaquininhaTaxa::firstOrCreate(['mt_maquininha_id' => $maquininha->id, 'mt_tipo' => 'credito'], ['mt_percentual' => 3.00]);
        }
        $opcao = OpcoesPagamento::firstOrCreate(
            ['opcaopag_nome' => 'Crédito'],
            ['opcaopag_tipo_taxa' => 'N/A', 'opcaopag_valor_percentual_taxa' => 0, 'opcaopag_desc_nfe' => 'creditCard'],
        );

        return PagamentosVenda::create([
            'pg_venda_venda_id' => $venda->id,
            'pg_venda_opcaopagamento_id' => $opcao->id,
            'pg_venda_maquininha_id' => $maquininha->id,
            'pg_venda_valor_pagamento' => $valor,
        ]);
    }

    public function test_desconta_maquininha_proporcional_e_imposto_da_nfce_da_taxa(): void
    {
        Empresa::create(['empresa_razao_social' => 'Pizzaria', 'empresa_cnpj' => '11222333000181', 'empresa_percentual_imposto_nfe' => 6]);

        $sessao = $this->sessao($this->ana, 'Mesa 1');
        $this->rodada($sessao, $this->ana, 60.00);
        $this->rodada($sessao, $this->bruno, 40.00);
        $venda = $this->vendaFinalizada($sessao); // taxa 10,00
        // A fixture não cria itens_vendas: o total vem só da taxa. Venda real: 100 + 10.
        $venda->update(['venda_valor_total' => 110.00]);
        $this->pagarNoCredito($venda, 110.00); // custo 3,30
        $venda->update(['venda_status_nfe' => 'Issued']);

        $service = new RelatorioTaxaServicoService;
        $totais = $service->totais();

        // Maquininha: 3,30 × 10 ÷ 110 = 0,30. Imposto: 6% de 10,00 = 0,60.
        $this->assertEquals(0.30, $totais['desconto_maquininha']);
        $this->assertEquals(0.60, $totais['desconto_imposto']);
        $this->assertEquals(9.10, $totais['taxa_liquida']);

        $porGarcom = $service->porGarcom()->keyBy('garcom_id');
        $this->assertEquals(5.46, $porGarcom[$this->ana->id]['taxa_liquida']);
        $this->assertEquals(3.64, $porGarcom[$this->bruno->id]['taxa_liquida']);

        $venda = $service->porVenda()->first();
        $this->assertEquals(9.10, $venda['taxa_liquida']);
        $this->assertEquals(9.10, round(collect($venda['rodadas'])->sum('taxa_liquida'), 2));
    }

    public function test_venda_sem_nfce_autorizada_nao_desconta_imposto(): void
    {
        Empresa::create(['empresa_razao_social' => 'Pizzaria', 'empresa_cnpj' => '11222333000181', 'empresa_percentual_imposto_nfe' => 6]);

        $sessao = $this->sessao($this->ana, 'Mesa 1');
        $this->rodada($sessao, $this->ana, 100.00);
        $this->vendaFinalizada($sessao);

        $totais = (new RelatorioTaxaServicoService)->totais();

        $this->assertEquals(0, $totais['desconto_imposto']);
        $this->assertEquals(10.00, $totais['taxa_liquida']);
    }

    public function test_imposto_usa_o_percentual_gravado_na_autorizacao_da_nota(): void
    {
        $empresa = Empresa::create(['empresa_razao_social' => 'Pizzaria', 'empresa_cnpj' => '11222333000181', 'empresa_percentual_imposto_nfe' => 6]);

        $sessao = $this->sessao($this->ana, 'Mesa 1');
        $this->rodada($sessao, $this->ana, 100.00);
        $venda = $this->vendaFinalizada($sessao);
        $venda->update(['venda_status_nfe' => 'Issued']);
        $empresa->update(['empresa_percentual_imposto_nfe' => 10]);

        $this->assertEquals(6.00, $venda->fresh()->venda_imposto_nfe_percentual);
        $this->assertEquals(0.60, (new RelatorioTaxaServicoService)->totais()['desconto_imposto']);
    }

    public function test_pagamento_sem_taxa_gravada_usa_a_taxa_atual_da_maquininha(): void
    {
        $sessao = $this->sessao($this->ana, 'Mesa 1');
        $this->rodada($sessao, $this->ana, 100.00);
        $venda = $this->vendaFinalizada($sessao);
        $venda->update(['venda_valor_total' => 110.00]);
        $pagamento = $this->pagarNoCredito($venda, 110.00, comTaxaCadastrada: false);
        $this->assertNull($pagamento->fresh()->pg_venda_taxa_maquininha_percentual);

        MaquininhaTaxa::create(['mt_maquininha_id' => $pagamento->pg_venda_maquininha_id, 'mt_tipo' => 'credito', 'mt_percentual' => 3.00]);

        $this->assertEquals(0.30, (new RelatorioTaxaServicoService)->totais()['desconto_maquininha']);
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
        Livewire::test(TaxaServicoDetalhamentoWidget::class)
            ->assertSeeText('Mesa 1')
            ->assertSeeText('Total da venda');
    }
}
