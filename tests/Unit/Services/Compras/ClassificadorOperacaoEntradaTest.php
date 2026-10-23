<?php

namespace Tests\Unit\Services\Compras;

use App\Enums\ConfiancaOperacaoEnum;
use App\Enums\ContaPagarOperacaoEnum;
use App\Enums\EfeitoEstoqueEnum;
use App\Enums\OperacaoEntradaEnum;
use App\Services\Compras\ClassificadorOperacaoEntrada;
use App\Services\Compras\Dto\ClassificacaoOperacao;
use App\Services\Compras\Dto\ContextoClassificacao;
use App\Services\Nfe\NfeXmlParser;
use Tests\TestCase;

class ClassificadorOperacaoEntradaTest extends TestCase
{
    private const CNPJ_EMPRESA = '11222333000181';

    private const CHAVE_COMPRA_NOSSA = '35261014200166000166550010000001011000001010';

    private function classificar(string $fixture, ?ContextoClassificacao $contexto = null): ClassificacaoOperacao
    {
        $xml = file_get_contents(base_path("tests/Fixtures/nfe-entrada/{$fixture}.xml"));

        return (new ClassificadorOperacaoEntrada)->classificar(
            (new NfeXmlParser)->parse($xml),
            $contexto ?? new ContextoClassificacao(empresaCnpj: self::CNPJ_EMPRESA),
        );
    }

    public function test_venda_5102_com_duplicatas_e_compra_com_confianca_alta(): void
    {
        $resultado = $this->classificar('venda-5102-duplicatas');

        $this->assertSame(OperacaoEntradaEnum::COMPRA, $resultado->operacao);
        $this->assertFalse($resultado->mista);
        $this->assertSame(ConfiancaOperacaoEnum::ALTA, $resultado->confianca);
        $this->assertSame([], $resultado->alertas);
        $this->assertCount(2, $resultado->porItem);
        $this->assertSame('5102', $resultado->porItem[0]->cfop);
    }

    public function test_venda_5102_com_tpag_90_e_compra_com_confianca_media_e_alerta(): void
    {
        $resultado = $this->classificar('venda-5102-sem-pagamento');

        $this->assertSame(OperacaoEntradaEnum::COMPRA, $resultado->operacao);
        $this->assertSame(ConfiancaOperacaoEnum::MEDIA, $resultado->confianca);
        $this->assertNotEmpty($resultado->alertas);
        $this->assertStringContainsString('bonificação', $resultado->alertas[0]);
        $this->assertCount(1, $resultado->alertas);
    }

    public function test_5910_de_fornecedor_habitual_e_bonificacao_com_confianca_media(): void
    {
        $resultado = $this->classificar('bonificacao-5910', new ContextoClassificacao(
            empresaCnpj: self::CNPJ_EMPRESA,
            fornecedorTemHistorico: true,
        ));

        $this->assertSame(OperacaoEntradaEnum::BONIFICACAO, $resultado->operacao);
        $this->assertSame(ConfiancaOperacaoEnum::MEDIA, $resultado->confianca);
    }

    public function test_5910_de_fornecedor_sem_historico_e_doacao(): void
    {
        $resultado = $this->classificar('bonificacao-5910');

        $this->assertSame(OperacaoEntradaEnum::DOACAO, $resultado->operacao);
        $this->assertSame(ConfiancaOperacaoEnum::MEDIA, $resultado->confianca);
    }

    public function test_nota_mista_classifica_cada_item_e_marca_o_cabecalho_como_mista(): void
    {
        $resultado = $this->classificar('mista-5102-5910', new ContextoClassificacao(
            empresaCnpj: self::CNPJ_EMPRESA,
            fornecedorTemHistorico: true,
        ));

        $this->assertTrue($resultado->mista);
        $this->assertNull($resultado->operacao);
        $this->assertSame('mista', $resultado->valorCabecalho());
        $this->assertSame(OperacaoEntradaEnum::COMPRA, $resultado->porItem[0]->operacao);
        $this->assertSame(OperacaoEntradaEnum::BONIFICACAO, $resultado->porItem[1]->operacao);
        $this->assertNotSame(ConfiancaOperacaoEnum::ALTA, $resultado->confianca);
        $this->assertStringContainsString('Nota mista', implode(' ', $resultado->alertas));
        // Item gratuito numa nota com duplicatas: o fornecedor pode estar cobrando.
        $this->assertStringContainsString('duplicatas', implode(' ', $resultado->alertas));
    }

    public function test_devolucao_do_fornecedor_com_nfref_para_compra_nossa_tem_confianca_alta(): void
    {
        $resultado = $this->classificar('devolucao-fornecedor-nfref', new ContextoClassificacao(
            empresaCnpj: self::CNPJ_EMPRESA,
            notasReferenciadasNossas: [self::CHAVE_COMPRA_NOSSA],
        ));

        $this->assertSame(OperacaoEntradaEnum::DEVOLUCAO_AO_FORNECEDOR, $resultado->operacao);
        $this->assertSame(ConfiancaOperacaoEnum::ALTA, $resultado->confianca);
        $this->assertSame(EfeitoEstoqueEnum::SAIDA, $resultado->operacao->efeitos()->estoque);
    }

    public function test_devolucao_do_fornecedor_sem_nfref_conhecida_tem_confianca_baixa(): void
    {
        $resultado = $this->classificar('devolucao-fornecedor-nfref');

        $this->assertSame(OperacaoEntradaEnum::DEVOLUCAO_AO_FORNECEDOR, $resultado->operacao);
        $this->assertSame(ConfiancaOperacaoEnum::BAIXA, $resultado->confianca);
    }

    public function test_finalidade_2_e_complementar(): void
    {
        $resultado = $this->classificar('complementar');

        $this->assertSame(OperacaoEntradaEnum::COMPLEMENTAR, $resultado->operacao);
        $this->assertSame(ConfiancaOperacaoEnum::MEDIA, $resultado->confianca);
    }

    public function test_finalidade_3_e_ajuste_sem_efeito(): void
    {
        $resultado = $this->classificar('ajuste');

        $this->assertSame(OperacaoEntradaEnum::AJUSTE, $resultado->operacao);
        $this->assertSame(ConfiancaOperacaoEnum::ALTA, $resultado->confianca);
        $this->assertTrue($resultado->operacao->efeitos()->registraSemEfeito);
    }

    public function test_5908_e_comodato_sem_movimentacao_de_estoque(): void
    {
        $resultado = $this->classificar('comodato-5908');

        $this->assertSame(OperacaoEntradaEnum::COMODATO, $resultado->operacao);
        $this->assertSame(ConfiancaOperacaoEnum::ALTA, $resultado->confianca);
        $this->assertSame(EfeitoEstoqueEnum::NENHUM, $resultado->operacao->efeitos()->estoque);
    }

    public function test_5920_e_vasilhame(): void
    {
        $resultado = $this->classificar('vasilhame-5920');

        $this->assertSame(OperacaoEntradaEnum::VASILHAME, $resultado->operacao);
        $this->assertSame(ConfiancaOperacaoEnum::ALTA, $resultado->confianca);
    }

    public function test_5915_e_conserto_e_5911_e_amostra_gratis(): void
    {
        $this->assertSame(OperacaoEntradaEnum::CONSERTO, $this->classificar('conserto-5915')->operacao);
        $this->assertSame(OperacaoEntradaEnum::AMOSTRA_GRATIS, $this->classificar('amostra-5911')->operacao);
    }

    public function test_destinatario_diferente_da_empresa_e_nao_processavel(): void
    {
        $resultado = $this->classificar('destinatario-diferente');

        $this->assertSame(OperacaoEntradaEnum::NAO_PROCESSAVEL, $resultado->operacao);
        $this->assertSame(ConfiancaOperacaoEnum::NAO_PROCESSAVEL, $resultado->confianca);
        $this->assertTrue($resultado->operacao->efeitos()->bloqueiaConfirmacao);
    }

    public function test_emitente_e_a_propria_empresa_e_nao_processavel(): void
    {
        $resultado = $this->classificar('venda-5102-duplicatas', new ContextoClassificacao(empresaCnpj: '14200166000166'));

        $this->assertSame(OperacaoEntradaEnum::NAO_PROCESSAVEL, $resultado->operacao);
    }

    public function test_emitente_com_a_mesma_raiz_de_cnpj_e_transferencia(): void
    {
        $resultado = $this->classificar('transferencia-mesma-raiz');

        $this->assertSame(OperacaoEntradaEnum::TRANSFERENCIA, $resultado->operacao);
        $this->assertSame(ConfiancaOperacaoEnum::MEDIA, $resultado->confianca);
    }

    public function test_natureza_da_operacao_divergente_do_cfop_rebaixa_a_confianca(): void
    {
        $resultado = $this->classificar('natop-bonificacao-cfop-5102');

        $this->assertSame(OperacaoEntradaEnum::COMPRA, $resultado->operacao, 'o CFOP continua mandando na operação');
        $this->assertSame(ConfiancaOperacaoEnum::MEDIA, $resultado->confianca);
        $this->assertStringContainsString('diverge do CFOP', implode(' ', $resultado->alertas));
    }

    public function test_tpnf_0_nunca_passa_de_confianca_baixa(): void
    {
        $xml = file_get_contents(base_path('tests/Fixtures/nfe-compra-exemplo.xml'));

        $resultado = (new ClassificadorOperacaoEntrada)->classificar(
            (new NfeXmlParser)->parse($xml),
            new ContextoClassificacao,
        );

        $this->assertSame(ConfiancaOperacaoEnum::BAIXA, $resultado->confianca);
        $this->assertStringContainsString('tpNF=0', implode(' ', $resultado->alertas));
    }

    public function test_sem_cnpj_da_empresa_cadastrado_nao_bloqueia_por_destinatario(): void
    {
        $resultado = $this->classificar('destinatario-diferente', new ContextoClassificacao);

        $this->assertSame(OperacaoEntradaEnum::COMPRA, $resultado->operacao);
    }

    public function test_matriz_de_efeitos_das_operacoes_principais(): void
    {
        $compra = OperacaoEntradaEnum::COMPRA->efeitos();
        $this->assertTrue($compra->geraEntradaEstoque());
        $this->assertSame(ContaPagarOperacaoEnum::SIM, $compra->contaPagar);

        foreach ([OperacaoEntradaEnum::BONIFICACAO, OperacaoEntradaEnum::DOACAO] as $operacao) {
            $this->assertTrue($operacao->efeitos()->geraEntradaEstoque());
            $this->assertSame(ContaPagarOperacaoEnum::NAO, $operacao->efeitos()->contaPagar);
            $this->assertTrue($operacao->efeitos()->exigeConfirmacao);
        }

        $devolucao = OperacaoEntradaEnum::DEVOLUCAO_AO_FORNECEDOR->efeitos();
        $this->assertSame(EfeitoEstoqueEnum::SAIDA, $devolucao->estoque);
        $this->assertTrue($devolucao->exigeVinculo);

        foreach (OperacaoEntradaEnum::cases() as $operacao) {
            if ($operacao !== OperacaoEntradaEnum::COMPRA) {
                $this->assertNotSame(
                    ContaPagarOperacaoEnum::SIM,
                    $operacao->efeitos()->contaPagar,
                    "{$operacao->value} não pode gerar conta a pagar incondicional",
                );
            }
        }
    }
}
