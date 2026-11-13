<?php

namespace Tests\Feature;

use App\Enums\BaseVencimentoEnum;
use App\Enums\Comportamento;
use App\Enums\Compras\OrigemVinculo;
use App\Enums\Compras\RegraBaixaCompra;
use App\Enums\Compras\StatusPendencia;
use App\Enums\Compras\TipoPendenciaCompra;
use App\Enums\CompraStatusEnum;
use App\Enums\FormaPagamento;
use App\Enums\Periodicidade;
use App\Enums\RegraDataEntradaEnum;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Filament\Resources\Compras\Pages\EditCompra;
use App\Models\Caixa;
use App\Models\Categoria;
use App\Models\Compra;
use App\Models\CompraPendencia;
use App\Models\ContaFinanceira;
use App\Models\Empresa;
use App\Models\FornecedorCondicaoCompra;
use App\Models\FornecedorProduto;
use App\Models\Lancamento;
use App\Models\MovimentacaoFinanceira;
use App\Models\MovimentacaoProduto;
use App\Models\MovimentacoesSessaoCaixa;
use App\Models\PlanoDespesa;
use App\Models\PrazoPagamento;
use App\Models\Prestador;
use App\Models\Produto;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Services\Compras\CompraImportacaoService;
use App\Services\Compras\PendenciaService;
use Filament\Actions\DeleteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class ComprasAutomatizadasImportacaoTest extends TestCase
{
    use RefreshDatabase;

    private const CNPJ_PIX = '22333444000155';

    private const CNPJ_BOLETO = '33444555000166';

    private const CNPJ_NOVO = '44555666000177';

    private int $categoriaId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-12 10:00:00');

        Empresa::create(['empresa_razao_social' => 'Pizzaria Teste LTDA', 'empresa_cnpj' => '11222333000181']);
        $this->categoriaId = Categoria::create(['categoria_nome' => 'Insumos'])->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_pix_a_vista_nasce_paga_na_conta_do_fornecedor_sem_tocar_a_sessao_de_caixa(): void
    {
        $stone = ContaFinanceira::factory()->create(['nome' => 'Stone']);
        $this->fornecedorPix($stone);
        $sessao = $this->sessaoAberta();
        $movimentosDaSessao = MovimentacoesSessaoCaixa::count();

        $compra = $this->importar('pix-a-vista');

        $titulo = $compra->lancamentos()->sole();
        $this->assertSame(StatusLancamento::Pago, $titulo->status);
        $this->assertEquals(250.00, $titulo->valor);
        $this->assertSame('2026-11-10', $titulo->data_pagamento->toDateString());
        $this->assertSame(FormaPagamento::Pix, $titulo->forma_pagamento);

        $movimentacao = MovimentacaoFinanceira::sole();
        $this->assertSame($stone->id, $movimentacao->conta_financeira_id);
        $this->assertNull($movimentacao->sessao_caixa_id);
        $this->assertSame($movimentosDaSessao, MovimentacoesSessaoCaixa::count());
        $this->assertSame('ABERTA', $sessao->fresh()->sessaocaixa_status);

        // Saldo negativo permitido na baixa automática (decisão de 2026-11-13).
        $this->assertEquals(-250.00, $stone->fresh()->saldo_atual);
    }

    public function test_reimportar_a_mesma_nota_nao_duplica_compra_titulo_nem_estoque(): void
    {
        $stone = ContaFinanceira::factory()->create();
        $this->fornecedorPix($stone, ['fcc_entrada_automatica' => true]);

        $primeira = $this->importar('pix-a-vista');
        $segunda = $this->importar('pix-a-vista');

        $this->assertSame($primeira->id, $segunda->id);
        $this->assertSame(1, Compra::count());
        $this->assertSame(1, Lancamento::count());
        $this->assertSame(1, MovimentacaoFinanceira::count());
        $this->assertSame(2, MovimentacaoProduto::count());
    }

    public function test_entrada_automatica_no_mesmo_dia_da_emissao_confirma_e_atualiza_o_de_para(): void
    {
        $stone = ContaFinanceira::factory()->create();
        $this->fornecedorPix($stone, ['fcc_entrada_automatica' => true, 'fcc_entrada_dias_apos_emissao' => 0]);

        $compra = $this->importar('pix-a-vista');

        $this->assertSame(CompraStatusEnum::CONFIRMADA, $compra->compra_status);
        $this->assertSame('2026-11-10', $compra->compra_data_entrada->toDateString());
        $this->assertSame('conferida', $compra->situacaoImportacao());

        $mussarela = MovimentacaoProduto::query()->where('mov_produto_id', Produto::where('produto_descricao', 'Muçarela')->value('id'))->sole();
        $this->assertEquals(8.0, $mussarela->mov_quantidade);
        $this->assertEquals(25.0, round((float) FornecedorProduto::where('fp_codigo_fornecedor', 'MUS-4KG')->value('fp_ultimo_custo_unitario'), 2));
    }

    public function test_entrada_automatica_com_dias_apos_a_emissao_espera_o_dia_e_o_comando_confirma(): void
    {
        $stone = ContaFinanceira::factory()->create();
        $this->fornecedorPix($stone, ['fcc_entrada_automatica' => true, 'fcc_entrada_dias_apos_emissao' => 5]);

        $compra = $this->importar('pix-a-vista');
        $this->assertSame(CompraStatusEnum::RASCUNHO, $compra->compra_status);

        Carbon::setTestNow('2026-11-15 00:30:00');
        $this->artisan('compras:conferir-automaticas')->assertSuccessful();

        $compra->refresh();
        $this->assertSame(CompraStatusEnum::CONFIRMADA, $compra->compra_status);
        $this->assertSame('2026-11-15', $compra->compra_data_entrada->toDateString());
    }

    public function test_sem_entrada_automatica_a_compra_fica_em_rascunho(): void
    {
        $this->fornecedorPix(ContaFinanceira::factory()->create());

        $compra = $this->importar('pix-a-vista');

        $this->assertSame(CompraStatusEnum::RASCUNHO, $compra->compra_status);
        $this->assertSame(0, MovimentacaoProduto::count());
    }

    public function test_caixa_pdv_fechado_deixa_o_titulo_aberto_e_abre_pendencia(): void
    {
        $caixaPdv = ContaFinanceira::factory()->caixaPdv()->create();
        $this->fornecedorPix($caixaPdv, ['fcc_forma_pagamento' => FormaPagamento::Dinheiro]);

        $compra = $this->importar('pix-a-vista');

        $this->assertSame(StatusLancamento::Pendente, $compra->lancamentos()->sole()->status);
        $this->assertSame(0, MovimentacaoFinanceira::count());
        $this->assertTrue($compra->pendencias()->doTipo(TipoPendenciaCompra::CaixaPdvFechado)->emAberto()->exists());
    }

    public function test_boleto_com_tres_duplicatas_gera_tres_parcelas_sem_baixa(): void
    {
        $fornecedor = $this->fornecedor(self::CNPJ_BOLETO, 'Moinho', [
            'fcc_forma_pagamento' => FormaPagamento::Boleto,
            'fcc_conta_financeira_id' => ContaFinanceira::factory()->banco()->create()->id,
            'fcc_regra_baixa' => RegraBaixaCompra::Manual,
        ]);
        $this->dePara($fornecedor, 'FAR-25', $this->insumo('Farinha'), 25);

        $compra = $this->importar('boleto-3-duplicatas');

        $titulos = $compra->lancamentos()->orderBy('parcela_numero')->get();
        $this->assertSame(['2026-11-24', '2026-12-08', '2026-12-22'], $titulos->map(fn ($t) => $t->vencimento->toDateString())->all());
        $this->assertSame([300.0, 300.0, 300.0], $titulos->map(fn ($t) => (float) $t->valor)->all());
        $this->assertTrue($titulos->every(fn ($t) => $t->status === StatusLancamento::Pendente && $t->forma_pagamento === FormaPagamento::Boleto));
        $this->assertSame(0, MovimentacaoFinanceira::count());
    }

    public function test_sem_duplicatas_e_prazo_de_28_dias_da_emissao_gera_uma_parcela(): void
    {
        $prazo = PrazoPagamento::create(['prazo_pagamento_nome' => '28 dias', 'prazo_pagamento_ativo' => true]);
        $prazo->parcelas()->create(['parcela_numero' => 1, 'parcela_dias' => 28, 'parcela_percentual' => 100]);
        $fornecedor = $this->fornecedor(self::CNPJ_PIX, 'Laticínios', [
            'fcc_prazo_pagamento_id' => $prazo->id,
            'fcc_prazo_base' => BaseVencimentoEnum::Emissao,
            'fcc_forma_pagamento' => FormaPagamento::Boleto,
        ]);
        $this->dePara($fornecedor, 'MUS-4KG', $this->insumo('Muçarela'), 4);
        $this->dePara($fornecedor, 'ORE-1KG', $this->insumo('Orégano'), 1);

        $titulo = $this->importar('pix-a-vista')->lancamentos()->sole();

        $this->assertSame('2026-12-08', $titulo->vencimento->toDateString());
        $this->assertEquals(250.00, $titulo->valor);
        $this->assertSame($prazo->id, $titulo->prazo_pagamento_id);
    }

    public function test_lancamento_manual_ja_pago_e_vinculado_sem_nova_baixa(): void
    {
        $stone = ContaFinanceira::factory()->comSaldo(1000)->create();
        $fornecedor = $this->fornecedorPix($stone);
        $manual = $this->lancamentoManual($fornecedor, 250.02, '2026-11-11');
        $manual->registrarPagamento(250.02, Carbon::parse('2026-11-11'), FormaPagamento::Pix, contaFinanceiraId: $stone->id);
        $movimentacoes = MovimentacaoFinanceira::count();

        $compra = $this->importar('pix-a-vista');

        $this->assertSame([$manual->id], $compra->lancamentos()->pluck('id')->all());
        $this->assertSame($movimentacoes, MovimentacaoFinanceira::count());
        $this->assertSame(StatusLancamento::Pago, $manual->fresh()->status);
    }

    public function test_dois_lancamentos_candidatos_abrem_pendencia_sem_vincular(): void
    {
        $fornecedor = $this->fornecedorPix(ContaFinanceira::factory()->create());
        $this->lancamentoManual($fornecedor, 250.00, '2026-11-12');
        $this->lancamentoManual($fornecedor, 250.00, '2026-11-14');

        $compra = $this->importar('pix-a-vista');

        $this->assertSame(0, $compra->lancamentos()->count());
        $pendencia = $compra->pendencias()->doTipo(TipoPendenciaCompra::LancamentoAmbiguo)->sole();
        $this->assertCount(2, $pendencia->dados['candidatos']);
        $this->assertSame(2, Lancamento::whereNull('compra_id')->count());
    }

    public function test_item_por_de_para_usa_o_fator_e_por_ean_cria_o_de_para_de_origem_ean(): void
    {
        $catchup = $this->insumo('Catchup sachê', ['produto_codigo_EAN' => '7891234567895']);

        $compra = $this->importar('item-desconhecido');

        $itemCatchup = $compra->itens()->where('ci_codigo_fornecedor', 'CAT-200')->sole();
        $this->assertSame($catchup->id, $itemCatchup->ci_produto_id);
        $vinculo = FornecedorProduto::where('fp_codigo_fornecedor', 'CAT-200')->sole();
        $this->assertSame(OrigemVinculo::Ean, $vinculo->fp_origem);
        $this->assertSame('7891234567895', $vinculo->fp_ean);
        $this->assertSame($vinculo->id, $itemCatchup->ci_vinculo_id);
    }

    public function test_item_desconhecido_e_fornecedor_novo_viram_pendencias_e_o_titulo_nasce_mesmo_assim(): void
    {
        $compra = $this->importar('item-desconhecido');

        $tipos = $compra->pendencias()->emAberto()->get()->map(fn (CompraPendencia $p) => $p->tipo)->all();
        $this->assertContains(TipoPendenciaCompra::FornecedorNovo, $tipos);
        $this->assertContains(TipoPendenciaCompra::FornecedorSemParametros, $tipos);
        $this->assertSame(2, collect($tipos)->filter(fn ($t) => $t === TipoPendenciaCompra::ProdutoNaoIdentificado)->count());
        $this->assertSame('com_pendencias', $compra->situacaoImportacao());

        // Sem parâmetros: regra manual, título aberto à vista na emissão.
        $titulo = $compra->lancamentos()->sole();
        $this->assertSame(StatusLancamento::Pendente, $titulo->status);
        $this->assertEquals(180.00, $titulo->valor);
        $this->assertSame('2026-11-10', $titulo->vencimento->toDateString());
    }

    public function test_resolver_a_pendencia_grava_o_de_para_e_a_proxima_nota_reconhece_o_item(): void
    {
        $compra = $this->importar('item-desconhecido');
        $caixaPizza = $this->insumo('Caixa de pizza 35 cm', ['produto_unidade_estoque' => 'UN']);
        $pendencia = $compra->pendencias()->doTipo(TipoPendenciaCompra::ProdutoNaoIdentificado)
            ->whereHas('item', fn ($q) => $q->where('ci_codigo_fornecedor', 'EMB-35'))->sole();

        app(PendenciaService::class)->resolverVinculo($pendencia, $caixaPizza->id, 1.0, User::factory()->create(['name_first' => 'Gerente']));

        $this->assertSame(StatusPendencia::Resolvida, $pendencia->fresh()->status);
        $this->assertSame($caixaPizza->id, FornecedorProduto::where('fp_codigo_fornecedor', 'EMB-35')->value('fp_produto_id'));

        // Mesma linha em outra nota do fornecedor: reconhecida, sem pendência.
        $outra = $this->importar('item-desconhecido', fn (string $xml) => str_replace(['4031000004030', '<nNF>403</nNF>'], ['4031000004048', '<nNF>404</nNF>'], $xml));
        $this->assertNotSame($compra->id, $outra->id);
        $this->assertSame($caixaPizza->id, $outra->itens()->where('ci_codigo_fornecedor', 'EMB-35')->value('ci_produto_id'));
        $this->assertFalse($outra->pendencias()->doTipo(TipoPendenciaCompra::ProdutoNaoIdentificado)
            ->whereHas('item', fn ($q) => $q->where('ci_codigo_fornecedor', 'EMB-35'))->exists());
    }

    public function test_resolver_a_ultima_pendencia_dispara_a_entrada_automatica_e_refaz_o_rateio(): void
    {
        $insumos = $this->plano('Insumos');
        $embalagens = $this->plano('Embalagens');
        $this->fornecedor(self::CNPJ_NOVO, 'Distribuidora Nova', [
            'fcc_entrada_automatica' => true,
            'fcc_plano_despesa_id' => $insumos->id,
        ]);
        $this->insumo('Catchup sachê', ['produto_codigo_EAN' => '7891234567895', 'produto_plano_despesa_id' => $insumos->id]);
        $caixaPizza = $this->insumo('Caixa de pizza', ['produto_plano_despesa_id' => $embalagens->id]);

        $compra = $this->importar('item-desconhecido');
        $titulo = $compra->lancamentos()->sole();
        $this->assertSame($insumos->id, $titulo->plano_despesa_id);
        $this->assertSame(CompraStatusEnum::RASCUNHO, $compra->compra_status);

        $pendencia = $compra->pendencias()->doTipo(TipoPendenciaCompra::ProdutoNaoIdentificado)->sole();
        app(PendenciaService::class)->resolverVinculo($pendencia, $caixaPizza->id, 1.0);

        $this->assertSame(CompraStatusEnum::CONFIRMADA, $compra->fresh()->compra_status);
        $titulo->refresh();
        $this->assertNull($titulo->plano_despesa_id);
        $this->assertEqualsCanonicalizing(
            [$insumos->id => 80.0, $embalagens->id => 100.0],
            $titulo->despesas()->pluck('valor', 'plano_despesa_id')->map(fn ($v) => (float) $v)->all(),
        );
    }

    public function test_titulo_lancado_a_mao_e_vinculado_mantem_o_plano_ao_confirmar(): void
    {
        $fornecedor = $this->fornecedorPix(ContaFinanceira::factory()->create(), ['fcc_entrada_automatica' => true]);
        $manual = $this->lancamentoManual($fornecedor, 250.00, '2026-11-12');
        $planoDoUsuario = $manual->plano_despesa_id;

        $compra = $this->importar('pix-a-vista');

        $this->assertSame(CompraStatusEnum::CONFIRMADA, $compra->compra_status);
        $this->assertSame($planoDoUsuario, $manual->fresh()->plano_despesa_id);
        $this->assertSame(0, $manual->despesas()->count());
    }

    public function test_de_para_excluido_com_o_mesmo_codigo_nao_derruba_a_importacao(): void
    {
        $catchup = $this->insumo('Catchup sachê', ['produto_codigo_EAN' => '7891234567895']);
        $fornecedor = $this->fornecedor(self::CNPJ_NOVO, 'Distribuidora Nova');
        $this->dePara($fornecedor, 'CAT-200', $catchup, 1)->delete();

        $compra = $this->importar('item-desconhecido');

        $item = $compra->itens()->where('ci_codigo_fornecedor', 'CAT-200')->sole();
        $this->assertSame($catchup->id, $item->ci_produto_id);
        $this->assertNull($item->ci_vinculo_id);
        $this->assertSame(1, FornecedorProduto::onlyTrashed()->count());
    }

    public function test_divergencia_entre_duplicatas_e_nota_abre_pendencia_e_nao_baixa(): void
    {
        $fornecedor = $this->fornecedor(self::CNPJ_BOLETO, 'Moinho', [
            'fcc_regra_baixa' => RegraBaixaCompra::AutomaticaNaEmissao,
            'fcc_conta_financeira_id' => ContaFinanceira::factory()->create()->id,
        ]);
        $this->dePara($fornecedor, 'FAR-25', $this->insumo('Farinha'), 25);

        $compra = $this->importar('boleto-3-duplicatas', fn (string $xml) => str_replace('<vDup>300.00</vDup></dup></cobr>', '<vDup>290.00</vDup></dup></cobr>', $xml));

        $this->assertTrue($compra->pendencias()->doTipo(TipoPendenciaCompra::DivergenciaValor)->exists());
        $this->assertSame(0, MovimentacaoFinanceira::count());
    }

    public function test_custo_acima_da_tolerancia_abre_pendencia_informativa_sem_bloquear(): void
    {
        $stone = ContaFinanceira::factory()->create();
        $fornecedor = $this->fornecedorPix($stone, ['fcc_entrada_automatica' => true, 'fcc_tolerancia_preco_pct' => 15]);
        FornecedorProduto::where('fp_prestador_id', $fornecedor->id)->where('fp_codigo_fornecedor', 'MUS-4KG')
            ->update(['fp_ultimo_custo_unitario' => 19.20]);

        $compra = $this->importar('pix-a-vista');

        $pendencia = $compra->pendencias()->doTipo(TipoPendenciaCompra::PrecoForaPadrao)->sole();
        $this->assertEquals(30.21, $pendencia->dados['variacao_pct']);
        $this->assertSame(CompraStatusEnum::CONFIRMADA, $compra->compra_status);
    }

    public function test_debito_automatico_baixa_no_vencimento_pelo_comando(): void
    {
        $banco = ContaFinanceira::factory()->banco()->create();
        $fornecedor = $this->fornecedor(self::CNPJ_BOLETO, 'Moinho', [
            'fcc_regra_baixa' => RegraBaixaCompra::AutomaticaNoVencimento,
            'fcc_conta_financeira_id' => $banco->id,
            'fcc_forma_pagamento' => FormaPagamento::Boleto,
        ]);
        $this->dePara($fornecedor, 'FAR-25', $this->insumo('Farinha'), 25);
        $compra = $this->importar('boleto-3-duplicatas');
        $this->assertSame(0, MovimentacaoFinanceira::count());

        Carbon::setTestNow('2026-11-24 06:10:00');
        $this->artisan('compras:baixar-vencimentos')->assertSuccessful();

        $titulos = $compra->lancamentos()->orderBy('parcela_numero')->get();
        $this->assertSame([StatusLancamento::Pago, StatusLancamento::Pendente, StatusLancamento::Pendente], $titulos->pluck('status')->all());
        $this->assertSame('2026-11-24', $titulos[0]->data_pagamento->toDateString());
    }

    public function test_compra_lancada_a_mao_com_mesmo_numero_recebe_o_xml_em_vez_de_duplicar(): void
    {
        $fornecedor = $this->fornecedorPix(ContaFinanceira::factory()->create());
        $manual = Compra::create([
            'compra_prestador_id' => $fornecedor->id,
            'compra_numero' => '201',
            'compra_serie' => '001',
            'compra_status' => CompraStatusEnum::CONFIRMADA,
        ]);

        $compra = $this->importar('pix-a-vista');

        $this->assertSame($manual->id, $compra->id);
        $this->assertSame(1, Compra::count());
        $this->assertSame('52261122333444000155550010000002011000002010', $compra->compra_chave_nfe);
        $this->assertNotNull($compra->compra_xml_path);
        $this->assertSame(0, Lancamento::count());
    }

    // ------------------------------------------------------------------

    public function test_excluir_rascunho_cancela_os_titulos_descarta_as_pendencias_e_reimportar_nao_duplica(): void
    {
        $compra = $this->importar('item-desconhecido');
        $titulo = $compra->lancamentos()->sole();

        $this->assertTrue($compra->delete());

        $this->assertSoftDeleted($compra);
        $this->assertSame(StatusLancamento::Cancelado, $titulo->fresh()->status);
        $this->assertSame(0, CompraPendencia::query()->where('compra_id', $compra->id)->emAberto()->count());

        $nova = $this->importar('item-desconhecido');

        $this->assertNotSame($compra->id, $nova->id);
        $this->assertSame(1, Lancamento::query()->where('status', '!=', StatusLancamento::Cancelado)->count());
        $this->assertSame($nova->id, Lancamento::query()->where('status', '!=', StatusLancamento::Cancelado)->sole()->compra_id);
    }

    public function test_rascunho_com_titulo_pago_nao_pode_ser_excluido(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $this->fornecedorPix(ContaFinanceira::factory()->create(['nome' => 'Stone']));
        $compra = $this->importar('pix-a-vista');
        $this->assertTrue($compra->isRascunho());

        Livewire::test(EditCompra::class, ['record' => $compra->getKey()])
            ->callAction(DeleteAction::class)
            ->assertNotified('Esta compra tem título com pagamento registrado. Estorne o pagamento antes de excluir.');

        $this->assertNotSoftDeleted($compra);
        $this->assertSame(StatusLancamento::Pago, $compra->lancamentos()->sole()->status);
    }

    private function importar(string $fixture, ?callable $ajuste = null): Compra
    {
        $xml = file_get_contents(base_path("tests/Fixtures/nfe-entrada/automacao/{$fixture}.xml"));

        return app(CompraImportacaoService::class)->importar($ajuste ? $ajuste($xml) : $xml);
    }

    /** Fornecedor do XML pix-a-vista: PIX à vista, baixa na emissão, itens com de-para. */
    private function fornecedorPix(ContaFinanceira $conta, array $condicao = []): Prestador
    {
        $fornecedor = $this->fornecedor(self::CNPJ_PIX, 'Laticínios', [
            'fcc_forma_pagamento' => FormaPagamento::Pix,
            'fcc_conta_financeira_id' => $conta->id,
            'fcc_regra_baixa' => RegraBaixaCompra::AutomaticaNaEmissao,
            ...$condicao,
        ]);
        $this->dePara($fornecedor, 'MUS-4KG', $this->insumo('Muçarela'), 4);
        $this->dePara($fornecedor, 'ORE-1KG', $this->insumo('Orégano'), 1);

        return $fornecedor;
    }

    private function fornecedor(string $cnpj, string $nome, array $condicao = []): Prestador
    {
        $fornecedor = Prestador::create(['tipo' => 'pj', 'categoria' => 'fornecedor', 'razao_social' => $nome, 'cpf_cnpj' => $cnpj]);

        FornecedorCondicaoCompra::create([
            'fcc_prestador_id' => $fornecedor->id,
            'fcc_prazo_base' => BaseVencimentoEnum::Entrada,
            'fcc_data_entrada_regra' => RegraDataEntradaEnum::Hoje,
            ...$condicao,
        ]);

        return $fornecedor;
    }

    private function dePara(Prestador $fornecedor, string $codigo, Produto $produto, float $fator): FornecedorProduto
    {
        return FornecedorProduto::create([
            'fp_prestador_id' => $fornecedor->id,
            'fp_produto_id' => $produto->id,
            'fp_codigo_fornecedor' => $codigo,
            'fp_fator_conversao' => $fator,
        ]);
    }

    private function insumo(string $nome, array $attrs = []): Produto
    {
        return Produto::create([
            'produto_descricao' => $nome,
            'produto_categoria_id' => $this->categoriaId,
            'produto_tipo' => 'insumo',
            'produto_unidade_estoque' => 'KG',
            ...$attrs,
        ]);
    }

    private function plano(string $nome): PlanoDespesa
    {
        return PlanoDespesa::create(['nome' => $nome, 'comportamento' => Comportamento::Variavel, 'periodicidade' => Periodicidade::Eventual]);
    }

    private function lancamentoManual(Prestador $fornecedor, float $valor, string $vencimento): Lancamento
    {
        return Lancamento::create([
            'tipo' => TipoLancamento::Pagar,
            'plano_despesa_id' => $this->plano('Compras '.uniqid())->id,
            'favorecido_id' => $fornecedor->id,
            'descricao' => 'Lançado à mão',
            'valor' => $valor,
            'vencimento' => $vencimento,
            'status' => StatusLancamento::Pendente,
        ]);
    }

    private function sessaoAberta(): SessaoCaixa
    {
        return SessaoCaixa::create([
            'sessaocaixa_caixa_id' => Caixa::create(['caixa_nome' => 'Caixa 1'])->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now()->subHour(),
            'sessaocaixa_saldo_inicial' => 100,
            'sessaocaixa_saldo_final' => 100,
            'sessaocaixa_user_id' => User::factory()->create(['name_first' => 'Caixa'])->id,
        ]);
    }
}
