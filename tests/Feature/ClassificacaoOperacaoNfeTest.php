<?php

namespace Tests\Feature;

use App\Enums\CompraStatusEnum;
use App\Enums\ConfiancaOperacaoEnum;
use App\Filament\Resources\Compras\Support\OperacaoFiscalView;
use App\Models\Categoria;
use App\Models\Compra;
use App\Models\Empresa;
use App\Models\Prestador;
use App\Models\Produto;
use App\Models\User;
use App\Services\CompraService;
use App\Services\Nfe\NfeImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Fase 1 do plano docs/planos/operacoes-fiscais-entrada-nfe.md: captura e classificação somente leitura. */
class ClassificacaoOperacaoNfeTest extends TestCase
{
    use RefreshDatabase;

    private const CNPJ_FORNECEDOR = '14200166000166';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        Empresa::create(['empresa_razao_social' => 'Pizzaria Teste LTDA', 'empresa_cnpj' => '11222333000181']);
    }

    private function fixture(string $nome): string
    {
        return file_get_contents(base_path("tests/Fixtures/nfe-entrada/{$nome}.xml"));
    }

    private function importar(string $fixture): Compra
    {
        return app(NfeImportService::class)->importar($this->fixture($fixture), auth()->id());
    }

    /** Apaga as colunas fiscais para simular uma compra importada antes da Fase 1. */
    private function tornarLegada(Compra $compra): void
    {
        DB::table('compras')->where('id', $compra->id)->update([
            'compra_tp_nf' => null, 'compra_fin_nfe' => null, 'compra_nat_op' => null, 'compra_dest_documento' => null,
            'compra_valor_nf' => null, 'compra_duplicatas_nf' => null, 'compra_pagamentos_nf' => null,
            'compra_operacao' => null, 'compra_operacao_confianca' => null, 'compra_operacao_criterios' => null,
        ]);
        DB::table('compra_itens')->where('ci_compra_id', $compra->id)->update([
            'ci_cfop' => null, 'ci_cst_csosn' => null, 'ci_operacao' => null,
        ]);
    }

    /** Vincula todos os itens a um insumo, confirma e gera 1 título — para a compra ter efeitos reais. */
    private function confirmarComEfeitos(Compra $compra): void
    {
        $insumo = Produto::create([
            'produto_descricao' => 'Insumo',
            'produto_categoria_id' => Categoria::firstOrCreate(['categoria_nome' => 'Teste'])->id,
            'produto_tipo' => 'insumo',
            'produto_unidade_estoque' => 'UN',
        ]);
        $compra->itens()->update(['ci_produto_id' => $insumo->id]);

        $service = app(CompraService::class);
        $service->confirmar($compra->fresh());
        $service->gerarContaPagar($compra->fresh(), ['parcelas' => [['vencimento' => today()->toDateString(), 'valor' => 10]]]);
    }

    /** Fotografia de tudo que a Fase 1 NÃO pode alterar. */
    private function efeitos(): array
    {
        return [
            'movimentacoes' => DB::table('movimentacao_produtos')->orderBy('id')->get()->toJson(),
            'lotes' => DB::table('estoque_lotes')->orderBy('id')->get()->toJson(),
            'lancamentos' => DB::table('lancamentos')->orderBy('id')->get()->toJson(),
            'compras' => DB::table('compras')->orderBy('id')
                ->get(['id', 'compra_status', 'compra_valor_produtos', 'compra_valor_frete', 'compra_valor_desconto', 'compra_valor_outros', 'compra_valor_total'])
                ->toJson(),
            'saldos' => DB::table('produtos')->orderBy('id')->pluck('produto_saldo_estoque', 'id')->toJson(),
        ];
    }

    public function test_importacao_grava_colunas_fiscais_e_sugestao_da_compra_e_dos_itens(): void
    {
        $compra = $this->importar('venda-5102-duplicatas');

        $this->assertSame(1, $compra->compra_tp_nf);
        $this->assertSame(1, $compra->compra_fin_nfe);
        $this->assertSame('Venda de mercadoria adquirida de terceiros', $compra->compra_nat_op);
        $this->assertSame('11222333000181', $compra->compra_dest_documento);
        $this->assertSame('120.00', $compra->compra_valor_nf);
        $this->assertSame([], $compra->compra_notas_referenciadas);
        $this->assertSame('15', $compra->compra_pagamentos_nf[0]['tPag']);
        $this->assertEquals(120, $compra->compra_pagamentos_nf[0]['vPag']);
        $this->assertCount(2, $compra->compra_duplicatas_nf);
        $this->assertSame('2026-11-20', $compra->compra_duplicatas_nf[0]['dVenc']);
        $this->assertSame('compra', $compra->compra_operacao);
        $this->assertSame(ConfiancaOperacaoEnum::ALTA, $compra->compra_operacao_confianca);
        $this->assertNotEmpty($compra->compra_operacao_criterios['criterios']);
        $this->assertNull($compra->compra_operacao_definida_por);

        $itens = $compra->itens()->orderBy('id')->get();
        $this->assertSame('5102', $itens[0]->ci_cfop);
        $this->assertSame('102', $itens[0]->ci_cst_csosn);
        $this->assertSame('compra', $itens[0]->ci_operacao);
    }

    public function test_importacao_de_bonificacao_sugere_bonificacao_sem_mudar_nenhum_efeito(): void
    {
        $fornecedor = Prestador::create([
            'tipo' => 'pj', 'categoria' => 'fornecedor', 'razao_social' => 'Distribuidora Exemplo LTDA',
            'nome' => 'Distribuidora Exemplo', 'cpf_cnpj' => self::CNPJ_FORNECEDOR,
        ]);
        Compra::create(['compra_prestador_id' => $fornecedor->id, 'compra_status' => CompraStatusEnum::CONFIRMADA]);
        $antes = $this->efeitos();

        $compra = $this->importar('bonificacao-5910');

        $this->assertSame('bonificacao', $compra->compra_operacao);
        $this->assertSame(ConfiancaOperacaoEnum::MEDIA, $compra->compra_operacao_confianca);
        $this->assertSame('bonificacao', $compra->itens()->value('ci_operacao'));
        $this->assertSame(CompraStatusEnum::RASCUNHO, $compra->compra_status);
        $this->assertSame($antes['movimentacoes'], $this->efeitos()['movimentacoes']);
        $this->assertSame($antes['lancamentos'], $this->efeitos()['lancamentos']);
    }

    public function test_nota_mista_grava_mista_no_cabecalho_e_a_operacao_de_cada_item(): void
    {
        $compra = $this->importar('mista-5102-5910');

        $this->assertSame('mista', $compra->compra_operacao);
        $this->assertSame(['compra', 'doacao'], $compra->itens()->orderBy('id')->pluck('ci_operacao')->all());
    }

    public function test_importacao_grava_tributos_nao_somados_ao_total(): void
    {
        $xml = str_replace(
            ['<vST>0.00</vST>', '<vFCPST>0.00</vFCPST>', '<vSeg>0.00</vSeg>', '<vIPI>0.00</vIPI>', '<vNF>50.00</vNF>',
                '<imposto><ICMS><ICMSSN102><orig>0</orig><CSOSN>102</CSOSN></ICMSSN102></ICMS></imposto>'],
            ['<vST>3.00</vST>', '<vFCPST>0.50</vFCPST>', '<vSeg>1.00</vSeg>', '<vIPI>2.00</vIPI>', '<vNF>56.50</vNF>',
                '<imposto><ICMS><ICMS60><orig>0</orig><CST>60</CST><vICMSSTRet>0.00</vICMSSTRet></ICMS60></ICMS><IPI><IPITrib><CST>50</CST><vIPI>2.00</vIPI></IPITrib></IPI></imposto>'],
            $this->fixture('natop-bonificacao-cfop-5102'),
        );
        $xml = str_replace('<vProd>50.00</vProd>', '<vProd>50.00</vProd>', $xml);

        $compra = app(NfeImportService::class)->importar($xml, auth()->id());

        $this->assertSame('3.00', $compra->compra_valor_st);
        $this->assertSame('2.00', $compra->compra_valor_ipi);
        $this->assertSame('1.00', $compra->compra_valor_seguro);
        $this->assertSame('0.50', $compra->compra_valor_fcp_st);
        $this->assertSame('56.50', $compra->compra_valor_nf);
        $this->assertSame('50.00', $compra->compra_valor_total, 'o cálculo do total não muda na Fase 1');
        $this->assertEqualsWithDelta(6.5, abs($compra->divergenciaValorNf()), 0.001);
        $this->assertSame('60', $compra->itens()->value('ci_cst_csosn'));
        $this->assertSame('2.0000', $compra->itens()->value('ci_valor_ipi'));
        $aviso = OperacaoFiscalView::avisoDivergenciaValor($compra)?->toHtml();
        $this->assertStringContainsString('ICMS-ST R$ 3,00', $aviso);
        $this->assertStringContainsString('IPI R$ 2,00', $aviso);
    }

    public function test_aviso_do_modal_so_aparece_quando_a_operacao_nao_e_compra_com_confianca_alta(): void
    {
        $this->assertNull(OperacaoFiscalView::avisoOperacao($this->importar('venda-5102-duplicatas')));
        $this->assertNull(OperacaoFiscalView::avisoDivergenciaValor($this->importar('venda-5102-sem-pagamento')));

        $aviso = OperacaoFiscalView::avisoOperacao($this->importar('bonificacao-5910'))?->toHtml();
        $this->assertStringContainsString('DOAÇÃO', $aviso);

        $this->assertNotNull(OperacaoFiscalView::avisoOperacao($this->importar('comodato-5908')));
    }

    public function test_diagnostico_sem_gravar_nao_altera_nada(): void
    {
        $bonificacao = $this->importar('bonificacao-5910');
        $comodato = $this->importar('comodato-5908');
        $venda = $this->importar('venda-5102-duplicatas');
        $this->confirmarComEfeitos($bonificacao);
        $this->confirmarComEfeitos($comodato);
        foreach ([$bonificacao, $comodato, $venda] as $compra) {
            $this->tornarLegada($compra);
        }
        $antes = $this->efeitos();
        $colunasAntes = DB::table('compras')->orderBy('id')->get()->toJson();
        $itensAntes = DB::table('compra_itens')->orderBy('id')->get()->toJson();

        $this->assertSame(0, Artisan::call('compras:diagnosticar-operacoes'));
        $saida = Artisan::output();

        $this->assertStringContainsString('Bonificação', $saida, 'o comodato confirmado do mesmo fornecedor conta como histórico');
        $this->assertStringContainsString('Comodato', $saida);
        $this->assertStringContainsString('deu entrada no estoque, mas a operação prevê sem movimentação', $saida, 'comodato confirmado como compra');
        $this->assertStringContainsString('gerou título, mas a operação não gera conta a pagar', $saida);
        $this->assertStringContainsString('3 compra(s) analisada(s), 2 com divergência', $saida, 'a venda em rascunho não diverge');
        $this->assertStringContainsString('Nada foi gravado', $saida);
        $this->assertSame($antes, $this->efeitos());
        $this->assertSame($colunasAntes, DB::table('compras')->orderBy('id')->get()->toJson());
        $this->assertSame($itensAntes, DB::table('compra_itens')->orderBy('id')->get()->toJson());
    }

    public function test_diagnostico_com_gravar_preenche_so_colunas_fiscais_sem_tocar_nos_efeitos(): void
    {
        $bonificacao = $this->importar('bonificacao-5910');
        $this->confirmarComEfeitos($bonificacao);
        $this->tornarLegada($bonificacao);
        $antes = $this->efeitos();

        $this->assertSame(0, Artisan::call('compras:diagnosticar-operacoes', ['--gravar' => true]));

        $this->assertSame($antes, $this->efeitos());

        $compra = $bonificacao->fresh();
        $this->assertSame(CompraStatusEnum::CONFIRMADA, $compra->compra_status);
        $this->assertSame('doacao', $compra->compra_operacao, 'a própria compra não conta como histórico do fornecedor');
        $this->assertSame(1, $compra->compra_tp_nf);
        $this->assertSame('doacao', $compra->itens()->value('ci_operacao'));
        $this->assertSame('5910', $compra->itens()->value('ci_cfop'));
        $this->assertNull($compra->compra_operacao_definida_por);
    }

    public function test_diagnostico_ignora_compras_sem_xml_e_reporta_xml_ausente(): void
    {
        Compra::create(['compra_status' => CompraStatusEnum::RASCUNHO, 'compra_origem' => 'manual']);
        $compra = $this->importar('venda-5102-duplicatas');
        $compra->forceFill(['compra_xml_path' => 'compras_xml/inexistente.xml'])->save();

        Artisan::call('compras:diagnosticar-operacoes');
        $saida = Artisan::output();

        $this->assertStringContainsString('1 compra(s) com XML ausente no disco', $saida);
        $this->assertStringContainsString('0 compra(s) analisada(s)', $saida);
    }
}
