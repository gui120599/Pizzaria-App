<?php

namespace Tests\Unit\Services\Nfe;

use App\Exceptions\NfeXmlInvalidoException;
use App\Services\Nfe\NfeXmlParser;
use Tests\TestCase;

class NfeXmlParserTest extends TestCase
{
    private function fixture(string $nome): string
    {
        return file_get_contents(base_path("tests/Fixtures/{$nome}"));
    }

    public function test_parseia_campos_principais_do_xml(): void
    {
        $nfe = (new NfeXmlParser)->parse($this->fixture('nfe-compra-exemplo.xml'));

        $this->assertSame('35260114200166000166550010000000461123456789', $nfe->chaveAcesso);
        $this->assertSame('46', $nfe->numero);
        $this->assertSame('1', $nfe->serie);
        $this->assertSame('2026-01-15', $nfe->dataEmissao?->toDateString());
        $this->assertTrue($nfe->modeloReconhecido);
        $this->assertTrue($nfe->autorizacaoConfirmada);

        $this->assertSame('14200166000166', $nfe->emitente->documento);
        $this->assertFalse($nfe->emitente->isPessoaFisica());
        $this->assertSame('Distribuidora Exemplo LTDA', $nfe->emitente->razaoSocial);
        $this->assertSame('Distribuidora Exemplo', $nfe->emitente->nomeFantasia);
        $this->assertSame('São Paulo', $nfe->emitente->cidade);
        $this->assertSame('SP', $nfe->emitente->uf);

        $this->assertEqualsWithDelta(90.00, $nfe->valorProdutos, 0.001);
        $this->assertEqualsWithDelta(5.00, $nfe->valorFrete, 0.001);
        $this->assertEqualsWithDelta(0.00, $nfe->valorDesconto, 0.001);
        $this->assertEqualsWithDelta(95.00, $nfe->valorTotalNota, 0.001);

        $this->assertCount(2, $nfe->itens);

        $item1 = $nfe->itens[0];
        $this->assertSame('001', $item1->codigoFornecedor);
        $this->assertSame('AÇÚCAR REFINADO 1KG', $item1->descricao);
        $this->assertSame('7891234567890', $item1->ean);
        $this->assertSame('UN', $item1->unidadeComercial);
        $this->assertEqualsWithDelta(10.0, $item1->quantidadeComercial, 0.0001);
        $this->assertEqualsWithDelta(50.00, $item1->valorTotalBruto, 0.001);
        $this->assertEqualsWithDelta(5.0, $item1->custoUnitarioLiquido(), 0.001);
        $this->assertSame('L2026A', $item1->loteCodigo);
        $this->assertSame('2026-12-31', $item1->validade?->toDateString());

        $item2 = $nfe->itens[1];
        $this->assertSame('002', $item2->codigoFornecedor);
        $this->assertNull($item2->loteCodigo);
        $this->assertNull($item2->validade);
    }

    public function test_ignora_cean_sentinela_sem_gtin(): void
    {
        $nfe = (new NfeXmlParser)->parse($this->fixture('nfe-compra-exemplo.xml'));

        // O item 2 traz cEAN="SEM GTIN" — o mesmo valor sentinela usado como
        // default no cadastro de produtos; não pode virar critério de match.
        $this->assertNull($nfe->itens[1]->ean);
    }

    public function test_lanca_excecao_para_xml_mal_formado(): void
    {
        $this->expectException(NfeXmlInvalidoException::class);

        (new NfeXmlParser)->parse('isto não é um xml <<<');
    }

    public function test_lanca_excecao_para_nota_nao_autorizada(): void
    {
        $this->expectException(NfeXmlInvalidoException::class);

        (new NfeXmlParser)->parse($this->fixture('nfe-compra-cancelada.xml'));
    }

    public function test_aceita_xml_nfe_puro_sem_envelope_nfeproc(): void
    {
        $xmlPuro = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <NFe xmlns="http://www.portalfiscal.inf.br/nfe">
          <infNFe versao="4.00" Id="NFe35260114200166000166550010000000481123456781">
            <ide>
              <mod>55</mod>
              <serie>1</serie>
              <nNF>48</nNF>
              <dhEmi>2026-01-17T09:00:00-03:00</dhEmi>
            </ide>
            <emit>
              <CNPJ>14200166000166</CNPJ>
              <xNome>Distribuidora Exemplo LTDA</xNome>
              <enderEmit>
                <UF>SP</UF>
              </enderEmit>
            </emit>
            <det nItem="1">
              <prod>
                <cProd>001</cProd>
                <xProd>Produto Teste</xProd>
                <uCom>UN</uCom>
                <qCom>1.0000</qCom>
                <vUnCom>10.00</vUnCom>
                <vProd>10.00</vProd>
              </prod>
            </det>
            <total>
              <ICMSTot>
                <vProd>10.00</vProd>
                <vFrete>0.00</vFrete>
                <vDesc>0.00</vDesc>
                <vOutro>0.00</vOutro>
                <vNF>10.00</vNF>
              </ICMSTot>
            </total>
          </infNFe>
        </NFe>
        XML;

        $nfe = (new NfeXmlParser)->parse($xmlPuro);

        $this->assertSame('35260114200166000166550010000000481123456781', $nfe->chaveAcesso);
        $this->assertFalse($nfe->autorizacaoConfirmada);
        $this->assertCount(1, $nfe->itens);
    }
}
