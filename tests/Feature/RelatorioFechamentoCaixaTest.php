<?php

namespace Tests\Feature;

use App\Enums\FormaPagamento;
use App\Enums\MotivoSaidaCaixa;
use App\Enums\OperadoraMaquininha;
use App\Enums\ProdutoTipoEnum;
use App\Enums\StatusFechamentoCaixa;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Filament\Pages\RelatorioFechamentoCaixa;
use App\Filament\Resources\Maquininhas\Pages\EditMaquininha;
use App\Filament\Resources\Maquininhas\RelationManagers\TaxasRelationManager;
use App\Filament\Widgets\FechamentoCaixaMaquininhasWidget;
use App\Filament\Widgets\FechamentoCaixaStatsOverview;
use App\Models\Caixa;
use App\Models\CartoesPagamento;
use App\Models\Categoria;
use App\Models\FechamentoCaixa;
use App\Models\FechamentoCaixaMaquininha;
use App\Models\ItensVenda;
use App\Models\Lancamento;
use App\Models\Maquininha;
use App\Models\MaquininhaTaxa;
use App\Models\MovimentacoesSessaoCaixa;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\Produto;
use App\Models\SessaoCaixa;
use App\Models\SessaoCaixaMaquininha;
use App\Models\User;
use App\Models\Venda;
use App\Services\FechamentoCaixaService;
use App\Services\RelatorioFechamentoCaixaService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Relatório de Fechamento de Caixa: maquininha × bandeira com a taxa abatida,
 * previsão D+N, DRE, fluxo de caixa e conferência.
 */
class RelatorioFechamentoCaixaTest extends TestCase
{
    use RefreshDatabase;

    private Caixa $caixa;

    private SessaoCaixa $sessao;

    private Maquininha $stone;

    private CartoesPagamento $visa;

    private CartoesPagamento $master;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        $this->caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);
        $this->sessao = $this->sessao($this->caixa);
        $this->stone = Maquininha::create(['nome' => 'Stone 1', 'operadora' => OperadoraMaquininha::Stone]);
        $this->visa = CartoesPagamento::create(['cartao_bandeira' => 'Visa']);
        $this->master = CartoesPagamento::create(['cartao_bandeira' => 'MasterCard']);
    }

    private function sessao(Caixa $caixa, ?string $abertura = null): SessaoCaixa
    {
        return SessaoCaixa::create([
            'sessaocaixa_caixa_id' => $caixa->id,
            'sessaocaixa_status' => 'FECHADA',
            'sessaocaixa_data_hora_abertura' => $abertura ?? now()->subHours(8),
            'sessaocaixa_data_hora_fechamento' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => auth()->id(),
        ]);
    }

    private function taxa(string $tipo, float $percentual, ?CartoesPagamento $bandeira = null, ?int $prazo = null, ?Maquininha $maquininha = null): MaquininhaTaxa
    {
        return MaquininhaTaxa::create([
            'mt_maquininha_id' => ($maquininha ?? $this->stone)->id,
            'mt_cartao_id' => $bandeira?->id,
            'mt_tipo' => $tipo,
            'mt_percentual' => $percentual,
            'mt_prazo_recebimento_dias' => $prazo,
        ]);
    }

    private function opcao(string $descNfe): OpcoesPagamento
    {
        return OpcoesPagamento::firstOrCreate(
            ['opcaopag_desc_nfe' => $descNfe],
            ['opcaopag_nome' => $descNfe, 'opcaopag_tipo_taxa' => 'N/A', 'opcaopag_valor_percentual_taxa' => 0],
        );
    }

    private function venda(float $total, ?SessaoCaixa $sessao = null, string $status = 'FINALIZADA', ?string $finalizadaEm = null): Venda
    {
        return Venda::create([
            'venda_sessao_caixa_id' => ($sessao ?? $this->sessao)->id,
            'venda_status' => $status,
            'venda_valor_itens' => $total,
            'venda_valor_total' => $total,
            'venda_datahora_iniciada' => $finalizadaEm ?? now(),
            'venda_datahora_finalizada' => $status === 'FINALIZADA' ? ($finalizadaEm ?? now()) : null,
        ]);
    }

    private function pagar(Venda $venda, string $descNfe, float $valor, ?CartoesPagamento $bandeira = null, ?Maquininha $maquininha = null): PagamentosVenda
    {
        return PagamentosVenda::create([
            'pg_venda_venda_id' => $venda->id,
            'pg_venda_opcaopagamento_id' => $this->opcao($descNfe)->id,
            'pg_venda_cartao_id' => $bandeira?->id,
            'pg_venda_maquininha_id' => $maquininha?->id,
            'pg_venda_valor_pagamento' => $valor,
        ]);
    }

    private function relatorio(array $filtros = []): RelatorioFechamentoCaixaService
    {
        return new RelatorioFechamentoCaixaService($filtros);
    }

    public function test_agrupa_por_maquininha_tipo_e_bandeira_abatendo_a_taxa_de_cada_bandeira(): void
    {
        $this->taxa('credito', 3.00);
        $this->taxa('credito', 2.50, $this->visa);
        $this->taxa('pix', 1.00);
        $venda = $this->venda(390.00);
        $this->pagar($venda, 'creditCard', 100.00, $this->visa, $this->stone);
        $this->pagar($venda, 'creditCard', 200.00, $this->master, $this->stone);
        $this->pagar($venda, 'InstantPayment', 50.00, null, $this->stone);
        $this->pagar($venda, 'debitCard', 40.00, $this->visa, $this->stone);

        $linhas = $this->relatorio()->maquininhas()->keyBy(fn (array $linha): string => $linha['tipo'].' '.$linha['bandeira']);

        $this->assertSame(['bruto' => 100.0, 'taxa_percentual' => 2.5, 'taxa' => 2.5, 'liquido' => 97.5], array_intersect_key($linhas['Crédito Visa'], array_flip(['bruto', 'taxa_percentual', 'taxa', 'liquido'])));
        $this->assertSame(['bruto' => 200.0, 'taxa_percentual' => 3.0, 'taxa' => 6.0, 'liquido' => 194.0], array_intersect_key($linhas['Crédito MasterCard'], array_flip(['bruto', 'taxa_percentual', 'taxa', 'liquido'])));
        $this->assertSame(0.5, $linhas['Pix Pix']['taxa']);
        $this->assertSame(1, $linhas['Débito Visa']['sem_taxa']);
        $this->assertSame(0.0, $linhas['Débito Visa']['taxa']);
        $this->assertSame('Stone 1', $linhas['Crédito Visa']['maquininha']);
    }

    public function test_resumo_soma_o_mdr_e_calcula_o_mdr_efetivo_sem_contar_dinheiro(): void
    {
        $this->taxa('credito', 3.00);
        $venda = $this->venda(300.00);
        $this->pagar($venda, 'creditCard', 200.00, $this->master, $this->stone);
        $this->pagar($venda, 'cash', 100.00);

        $resumo = $this->relatorio()->resumo();

        $this->assertSame(6.0, $resumo['mdr']);
        $this->assertSame(200.0, $resumo['volume_maquininha']);
        $this->assertSame(3.0, $resumo['mdr_efetivo']);
        $this->assertSame(300.0, $resumo['recebido']);
        $this->assertSame(0, $resumo['sem_taxa']);
    }

    public function test_usa_o_retrato_do_pagamento_e_a_taxa_atual_so_quando_nao_ha_retrato(): void
    {
        $this->taxa('credito', 2.00);
        $venda = $this->venda(200.00);
        $this->pagar($venda, 'creditCard', 100.00, $this->visa, $this->stone);
        $semRetrato = $this->pagar($venda, 'debitCard', 100.00, $this->visa, $this->stone);
        MaquininhaTaxa::where('mt_tipo', 'credito')->update(['mt_percentual' => 5.00]);
        $this->taxa('debito', 1.00);

        $linhas = $this->relatorio()->maquininhas()->keyBy('tipo');

        $this->assertNull($semRetrato->fresh()->pg_venda_taxa_maquininha_percentual);
        $this->assertSame(2.0, $linhas['Crédito']['taxa']);
        $this->assertSame(1.0, $linhas['Débito']['taxa']);
        $this->assertSame(0, $linhas['Débito']['sem_taxa']);
    }

    public function test_pagamento_sem_maquininha_conta_na_maquininha_padrao(): void
    {
        $padrao = Maquininha::create(['nome' => 'Cielo', 'operadora' => OperadoraMaquininha::Cielo, 'maquininha_padrao' => true]);
        $this->taxa('credito', 4.00, maquininha: $padrao);
        $this->pagar($this->venda(50.00), 'creditCard', 50.00, $this->visa);

        $porMaquininha = $this->relatorio()->porMaquininha();

        $this->assertSame('Cielo (padrão)', $porMaquininha->sole()['maquininha']);
        $this->assertSame(2.0, $porMaquininha->sole()['taxa']);
    }

    public function test_por_bandeira_consolida_todas_as_maquininhas(): void
    {
        $outra = Maquininha::create(['nome' => 'Stone 2', 'operadora' => OperadoraMaquininha::Stone]);
        $this->taxa('credito', 3.00);
        $this->taxa('credito', 2.00, maquininha: $outra);
        $venda = $this->venda(300.00);
        $this->pagar($venda, 'creditCard', 100.00, $this->visa, $this->stone);
        $this->pagar($venda, 'creditCard', 200.00, $this->visa, $outra);

        $visa = $this->relatorio()->porBandeira()->sole();

        $this->assertSame('Visa', $visa['bandeira']);
        $this->assertSame(300.0, $visa['bruto']);
        $this->assertSame(7.0, $visa['taxa']);
        $this->assertSame(293.0, $visa['liquido']);
        $this->assertSame(100.0, $visa['participacao']);
    }

    public function test_previsao_de_recebimento_soma_o_prazo_a_data_da_venda(): void
    {
        $this->travelTo('2026-10-05 21:30:00');
        $this->taxa('credito', 3.00, prazo: 30);
        $this->taxa('pix', 0.00, prazo: 0);
        $this->taxa('debito', 1.00);
        $venda = $this->venda(300.00);
        $this->pagar($venda, 'creditCard', 100.00, $this->visa, $this->stone);
        $this->pagar($venda, 'InstantPayment', 100.00, null, $this->stone);
        $this->pagar($venda, 'debitCard', 100.00, $this->visa, $this->stone);

        $previsao = $this->relatorio()->previsaoRecebimento();

        $this->assertSame(['05/10/2026', '04/11/2026', null], $previsao->map(fn (array $linha): ?string => $linha['data']?->format('d/m/Y'))->all());
        $this->assertSame([100.0, 97.0, 99.0], $previsao->pluck('liquido')->all());
    }

    public function test_filtro_por_sessao_ignora_o_periodo_e_filtro_por_caixa_restringe_as_vendas(): void
    {
        $antiga = $this->sessao($this->caixa, now()->subMonths(2)->toDateTimeString());
        $this->venda(70.00, $antiga, finalizadaEm: now()->subMonths(2)->toDateTimeString());
        $this->venda(30.00, $this->sessao($outroCaixa = Caixa::create(['caixa_nome' => 'Caixa 2'])));

        $doMes = $this->relatorio()->resumo()['faturamento'];
        $daSessaoAntiga = $this->relatorio(['sessoes' => [$antiga->id]])->resumo()['faturamento'];
        $doCaixa1NoMes = $this->relatorio(['caixa_id' => $this->caixa->id])->resumo()['faturamento'];
        $doCaixa2NoMes = $this->relatorio(['caixa_id' => $outroCaixa->id])->resumo()['faturamento'];

        $this->assertSame(30.0, $doMes);
        $this->assertSame(70.0, $daSessaoAntiga);
        $this->assertSame(0.0, $doCaixa1NoMes);
        $this->assertSame(30.0, $doCaixa2NoMes);
    }

    public function test_dre_separa_a_taxa_de_servico_e_desconta_mdr_e_cmv_ate_a_margem(): void
    {
        $this->taxa('credito', 2.00);
        $produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Pizzas'])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 100.00,
        ]);
        $venda = Venda::create([
            'venda_sessao_caixa_id' => $this->sessao->id,
            'venda_status' => 'FINALIZADA',
            'venda_valor_itens' => 100.00,
            'venda_valor_desconto' => 10.00,
            'venda_valor_frete' => 5.00,
            'venda_valor_taxa_servico' => 10.00,
            'venda_valor_total' => 115.00,
            'venda_datahora_finalizada' => now(),
        ]);
        ItensVenda::create([
            'item_numero' => 1,
            'item_venda_venda_id' => $venda->id,
            'item_venda_produto_id' => $produto->id,
            'item_venda_quantidade' => 2,
            'item_venda_quantidade_tributavel' => 2,
            'item_venda_valor_unitario' => 55.00,
            'item_venda_custo_unitario' => 15.00,
            'item_venda_desconto' => 10.00,
            'item_venda_valor' => 100.00,
            'item_venda_status' => 'INSERIDO',
        ]);
        $this->pagar($venda, 'creditCard', 115.00, $this->visa, $this->stone);
        $this->venda(40.00, status: 'CANCELADA');

        $relatorio = $this->relatorio();
        $dre = $relatorio->dre()->pluck('valor', 'key');
        $resumo = $relatorio->resumo();

        $this->assertSame(110.0, $dre['produtos_bruto']);
        $this->assertSame(115.0, $dre['faturamento']);
        $this->assertSame(105.0, $dre['receita_operacional']);
        $this->assertSame(-2.3, $dre['mdr']);
        $this->assertSame(-30.0, $dre['cmv']);
        $this->assertSame(72.7, $dre['margem']);
        $this->assertSame(30.0, $resumo['cmv_percentual']);
        $this->assertSame(100.0, $resumo['cobertura_custo']);
        $this->assertSame(1, $resumo['canceladas']);
        $this->assertSame(40.0, $resumo['valor_cancelado']);
    }

    public function test_fluxo_de_caixa_separa_as_origens_e_bate_com_o_esperado_do_fechamento(): void
    {
        $venda = $this->venda(150.00);
        $this->pagar($venda, 'cash', 100.00);
        $this->pagar($venda, 'InstantPayment', 50.00, null, $this->stone);
        $movimento = fn (string $tipo, ?MotivoSaidaCaixa $motivo, float $valor) => MovimentacoesSessaoCaixa::create([
            'mov_sessaocaixa_id' => $this->sessao->id,
            'mov_descricao' => 'Teste',
            'mov_tipo' => $tipo,
            'mov_forma_pagamento' => FormaPagamento::Dinheiro,
            'mov_motivo' => $motivo,
            'mov_valor' => $valor,
        ]);
        $movimento('ENTRADA', null, 200.00);
        $movimento('ENTRADA', MotivoSaidaCaixa::Suprimento, 50.00);
        $movimento('SAIDA', MotivoSaidaCaixa::Sangria, 120.00);
        Lancamento::create([
            'tipo' => TipoLancamento::Receber,
            'descricao' => 'Fiado',
            'valor' => 1000,
            'vencimento' => now()->addDays(7),
            'status' => StatusLancamento::Pendente,
        ])->registrarPagamento(25, forma: FormaPagamento::Pix, sessaoCaixaId: $this->sessao->id);

        $fluxo = $this->relatorio(['sessoes' => [$this->sessao->id]])->fluxoCaixa()->keyBy('key');
        $esperadoDoFechamento = app(FechamentoCaixaService::class)->calcularEsperado($this->sessao);

        $this->assertSame(
            ['abertura' => 200.0, 'vendas' => 100.0, 'fiado' => 0.0, 'suprimentos' => 50.0, 'saidas' => 120.0, 'esperado' => 230.0],
            array_intersect_key($fluxo['dinheiro'], array_flip(['abertura', 'vendas', 'fiado', 'suprimentos', 'saidas', 'esperado'])),
        );
        $this->assertSame(75.0, $fluxo['pix']['esperado']);
        $this->assertEqualsWithDelta($esperadoDoFechamento['dinheiro'], $fluxo['dinheiro']['esperado'], 0.001);
        $this->assertEqualsWithDelta($esperadoDoFechamento['pix'], $fluxo['pix']['esperado'], 0.001);
        $this->assertNull($fluxo['dinheiro']['apurado']);
    }

    public function test_conferencia_da_maquininha_compara_o_sistema_com_a_leitura_menos_a_abertura(): void
    {
        $venda = $this->venda(150.00);
        $this->pagar($venda, 'debitCard', 100.00, $this->visa, $this->stone);
        $this->pagar($venda, 'creditCard', 50.00, $this->master, $this->stone);
        SessaoCaixaMaquininha::create(['sessao_caixa_id' => $this->sessao->id, 'maquininha_id' => $this->stone->id, 'valor_debito' => 30, 'valor_credito' => 0, 'valor_pix' => 0]);
        $fechamento = FechamentoCaixa::create(['sessao_caixa_id' => $this->sessao->id, 'user_id' => auth()->id(), 'status' => StatusFechamentoCaixa::Rascunho]);
        FechamentoCaixaMaquininha::create(['fechamento_caixa_id' => $fechamento->id, 'maquininha_id' => $this->stone->id, 'valor_debito' => 130, 'valor_credito' => 45, 'valor_pix' => 0]);

        $linha = $this->relatorio(['sessoes' => [$this->sessao->id]])->conferenciaMaquininhas()->sole();

        $this->assertSame(150.0, $linha['sistema']);
        $this->assertSame(145.0, $linha['leitura']);
        $this->assertSame(0.0, $linha['diferenca_debito']);
        $this->assertSame(-5.0, $linha['diferenca_credito']);
        $this->assertSame(-5.0, $linha['diferenca']);
    }

    public function test_cadastro_de_taxa_grava_o_prazo_de_recebimento_e_copiar_taxas_leva_o_prazo(): void
    {
        $destino = Maquininha::create(['nome' => 'Stone 2', 'operadora' => OperadoraMaquininha::Stone]);

        Livewire::test(TaxasRelationManager::class, ['ownerRecord' => $this->stone, 'pageClass' => EditMaquininha::class])
            ->callAction(TestAction::make('create')->table(), ['mt_tipo' => 'credito', 'mt_percentual' => 3.19, 'mt_prazo_recebimento_dias' => 30])
            ->assertHasNoFormErrors();
        $destino->copiarTaxasDe($this->stone);

        $this->assertSame(30, $this->stone->taxas()->sole()->mt_prazo_recebimento_dias);
        $this->assertSame(30, $destino->taxas()->sole()->mt_prazo_recebimento_dias);
    }

    public function test_pagina_e_widgets_renderizam_para_o_gerente_e_a_impressao_para_o_admin(): void
    {
        $this->taxa('credito', 3.00, $this->visa);
        $this->pagar($this->venda(100.00), 'creditCard', 100.00, $this->visa, $this->stone);
        $this->actingAs(User::factory()->gerente()->create(['name_first' => 'Gerente']));

        Livewire::test(RelatorioFechamentoCaixa::class)->assertOk();
        Livewire::test(FechamentoCaixaStatsOverview::class)->assertSeeText('R$ 3,00');
        Livewire::test(FechamentoCaixaMaquininhasWidget::class)->assertSeeText('Stone 1')->assertSeeText('Visa');
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']))
            ->get(route('relatorios.fechamento_caixa.imprimir', ['filters' => ['sessoes' => [$this->sessao->id]]]))
            ->assertOk()
            ->assertSeeText('Maquininhas por bandeira')
            ->assertSeeText('R$ 97,00');
    }

    public function test_impressao_e_negada_sem_permissao_de_relatorio_financeiro(): void
    {
        $this->actingAs(User::factory()->garcom()->create(['name_first' => 'Garçom']))
            ->get(route('relatorios.fechamento_caixa.imprimir'))
            ->assertForbidden();
    }
}
