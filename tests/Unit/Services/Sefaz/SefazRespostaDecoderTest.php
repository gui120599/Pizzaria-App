<?php

namespace Tests\Unit\Services\Sefaz;

use App\Exceptions\SefazDocumentoNaoLocalizadoException;
use App\Exceptions\SefazIndisponivelException;
use App\Services\Sefaz\Dto\SefazDocumentoCompleto;
use App\Services\Sefaz\Dto\SefazResumoDocumento;
use App\Services\Sefaz\SefazRespostaDecoder;
use Tests\TestCase;

class SefazRespostaDecoderTest extends TestCase
{
    private const CHAVE = '35260114200166000166550010000000461123456789';

    private function docZip(string $conteudoXml, string $schema, int $nsu): string
    {
        $comprimido = base64_encode(gzencode($conteudoXml));

        return "<docZip NSU=\"{$nsu}\" schema=\"{$schema}\">{$comprimido}</docZip>";
    }

    private function resNFe(string $chave, string $cnpj = '14200166000166'): string
    {
        return <<<XML
        <resNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">
          <chNFe>{$chave}</chNFe>
          <CNPJ>{$cnpj}</CNPJ>
          <xNome>Distribuidora Exemplo LTDA</xNome>
          <IE>123456789</IE>
          <dhEmi>2026-01-15T09:30:00-03:00</dhEmi>
          <tpNF>1</tpNF>
          <vNF>95.00</vNF>
          <dhRecbto>2026-01-15T09:35:00-03:00</dhRecbto>
          <nProt>135260000012345</nProt>
          <cSitNFe>1</cSitNFe>
        </resNFe>
        XML;
    }

    private function resEvento(string $chave): string
    {
        return <<<XML
        <resEvento xmlns="http://www.portalfiscal.inf.br/nfe">
          <cOrgao>91</cOrgao>
          <CNPJ>14200166000166</CNPJ>
          <chNFe>{$chave}</chNFe>
          <dhEvento>2026-01-16T10:00:00-03:00</dhEvento>
          <tpEvento>110111</tpEvento>
          <nSeqEvento>1</nSeqEvento>
          <xEvento>Cancelamento</xEvento>
        </resEvento>
        XML;
    }

    /** Envelope SOAP real (nfephp-org/sped-nfe devolve o corpo completo, não só o retDistDFeInt). */
    private function envelopeSoap(string $retDistDFeInt): string
    {
        return <<<XML
        <?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body><nfeDistDFeInteresseResponse xmlns="http://www.portalfiscal.inf.br/nfe/wsdl/NFeDistribuicaoDFe"><nfeDistDFeInteresseResult>{$retDistDFeInt}</nfeDistDFeInteresseResult></nfeDistDFeInteresseResponse></soap:Body></soap:Envelope>
        XML;
    }

    private function procNFe(string $chave): string
    {
        return <<<XML
        <nfeProc xmlns="http://www.portalfiscal.inf.br/nfe">
          <NFe xmlns="http://www.portalfiscal.inf.br/nfe">
            <infNFe versao="4.00" Id="NFe{$chave}">
              <ide><nNF>46</nNF></ide>
            </infNFe>
          </NFe>
        </nfeProc>
        XML;
    }

    public function test_decode_lote_com_resnfe_e_resevento(): void
    {
        $lote = <<<XML
        <retDistDFeInt versao="1.01" xmlns="http://www.portalfiscal.inf.br/nfe">
          <tpAmb>2</tpAmb>
          <verAplic>SP1.0</verAplic>
          <cStat>138</cStat>
          <xMotivo>Documento(s) localizado(s)</xMotivo>
          <dhResp>2026-01-20T10:00:00-03:00</dhResp>
          <ultNSU>000000000000010</ultNSU>
          <maxNSU>000000000000020</maxNSU>
          <loteDistDFeInt>
            {$this->docZip($this->resNFe(self::CHAVE), 'resNFe_v1.01.xsd', 9)}
            {$this->docZip($this->resEvento('35260114200166000166550010000000471123456780'), 'resEvento_v1.00.xsd', 10)}
          </loteDistDFeInt>
        </retDistDFeInt>
        XML;

        $resultado = (new SefazRespostaDecoder)->decodeLote($lote);

        $this->assertSame('138', $resultado->cStat);
        $this->assertSame(10, $resultado->ultNsuRetornado);
        $this->assertSame(20, $resultado->maxNsu);
        $this->assertTrue($resultado->temMais());
        $this->assertCount(2, $resultado->itens);

        $this->assertSame(self::CHAVE, $resultado->itens[0]->chaveAcesso);
        $this->assertFalse($resultado->itens[0]->isEvento);
        $this->assertSame('14200166000166', $resultado->itens[0]->cnpjEmitente);
        $this->assertSame('Distribuidora Exemplo LTDA', $resultado->itens[0]->nomeEmitente);
        $this->assertSame(95.0, $resultado->itens[0]->valor);
        $this->assertTrue($resultado->itens[0]->dataEmissao?->isSameDay('2026-01-15'));

        $this->assertTrue($resultado->itens[1]->isEvento);
        $this->assertNull($resultado->itens[1]->nomeEmitente);
        $this->assertNull($resultado->itens[1]->valor);
    }

    public function test_decode_lote_vazio(): void
    {
        $lote = <<<'XML'
        <retDistDFeInt versao="1.01" xmlns="http://www.portalfiscal.inf.br/nfe">
          <tpAmb>2</tpAmb>
          <verAplic>SP1.0</verAplic>
          <cStat>137</cStat>
          <xMotivo>Nenhum documento localizado</xMotivo>
          <dhResp>2026-01-20T10:00:00-03:00</dhResp>
          <ultNSU>000000000000020</ultNSU>
          <maxNSU>000000000000020</maxNSU>
        </retDistDFeInt>
        XML;

        $resultado = (new SefazRespostaDecoder)->decodeLote($lote);

        $this->assertSame('137', $resultado->cStat);
        $this->assertCount(0, $resultado->itens);
        $this->assertFalse($resultado->temMais());
    }

    public function test_decode_consulta_chave_com_xml_completo(): void
    {
        $resposta = <<<XML
        <retDistDFeInt versao="1.01" xmlns="http://www.portalfiscal.inf.br/nfe">
          <tpAmb>2</tpAmb>
          <verAplic>SP1.0</verAplic>
          <cStat>138</cStat>
          <xMotivo>Documento localizado</xMotivo>
          <dhResp>2026-01-20T10:00:00-03:00</dhResp>
          <ultNSU>000000000000010</ultNSU>
          <maxNSU>000000000000010</maxNSU>
          <loteDistDFeInt>
            {$this->docZip($this->procNFe(self::CHAVE), 'procNFe_v4.00.xsd', 10)}
          </loteDistDFeInt>
        </retDistDFeInt>
        XML;

        $resultado = (new SefazRespostaDecoder)->decodeConsultaChave($resposta);

        $this->assertInstanceOf(SefazDocumentoCompleto::class, $resultado);
        $this->assertSame(self::CHAVE, $resultado->chaveAcesso);
        $this->assertStringContainsString('<nfeProc', $resultado->xmlCompleto);
    }

    public function test_decode_consulta_chave_ainda_so_resumo(): void
    {
        $resposta = <<<XML
        <retDistDFeInt versao="1.01" xmlns="http://www.portalfiscal.inf.br/nfe">
          <tpAmb>2</tpAmb>
          <verAplic>SP1.0</verAplic>
          <cStat>138</cStat>
          <xMotivo>Documento localizado</xMotivo>
          <dhResp>2026-01-20T10:00:00-03:00</dhResp>
          <ultNSU>000000000000009</ultNSU>
          <maxNSU>000000000000009</maxNSU>
          <loteDistDFeInt>
            {$this->docZip($this->resNFe(self::CHAVE), 'resNFe_v1.01.xsd', 9)}
          </loteDistDFeInt>
        </retDistDFeInt>
        XML;

        $resultado = (new SefazRespostaDecoder)->decodeConsultaChave($resposta);

        $this->assertInstanceOf(SefazResumoDocumento::class, $resultado);
        $this->assertSame(self::CHAVE, $resultado->chaveAcesso);
    }

    public function test_decode_consulta_chave_sem_documento_lanca_excecao(): void
    {
        $resposta = <<<'XML'
        <retDistDFeInt versao="1.01" xmlns="http://www.portalfiscal.inf.br/nfe">
          <tpAmb>2</tpAmb>
          <verAplic>SP1.0</verAplic>
          <cStat>137</cStat>
          <xMotivo>Nenhum documento localizado</xMotivo>
          <dhResp>2026-01-20T10:00:00-03:00</dhResp>
          <ultNSU>000000000000009</ultNSU>
          <maxNSU>000000000000009</maxNSU>
        </retDistDFeInt>
        XML;

        try {
            (new SefazRespostaDecoder)->decodeConsultaChave($resposta);
            $this->fail('Esperava SefazDocumentoNaoLocalizadoException.');
        } catch (SefazDocumentoNaoLocalizadoException $e) {
            $this->assertInstanceOf(SefazIndisponivelException::class, $e);
        }
    }

    /**
     * Regressão: a resposta crua da lib nfephp-org/sped-nfe é o envelope SOAP
     * completo, não o <retDistDFeInt> isolado — o retDistDFeInt fica vários
     * níveis abaixo da raiz. Sem o XPath "//" isso silenciosamente "não acha
     * nada" mesmo com documento presente.
     */
    public function test_decode_lote_funciona_dentro_do_envelope_soap_completo(): void
    {
        $retDistDFeInt = <<<XML
        <retDistDFeInt versao="1.01" xmlns="http://www.portalfiscal.inf.br/nfe">
          <tpAmb>1</tpAmb>
          <verAplic>SP1.0</verAplic>
          <cStat>138</cStat>
          <xMotivo>Documento(s) localizado(s)</xMotivo>
          <dhResp>2026-01-20T10:00:00-03:00</dhResp>
          <ultNSU>000000000000010</ultNSU>
          <maxNSU>000000000000020</maxNSU>
          <loteDistDFeInt>
            {$this->docZip($this->resNFe(self::CHAVE), 'resNFe_v1.01.xsd', 9)}
          </loteDistDFeInt>
        </retDistDFeInt>
        XML;

        $resultado = (new SefazRespostaDecoder)->decodeLote($this->envelopeSoap($retDistDFeInt));

        $this->assertSame('138', $resultado->cStat);
        $this->assertSame(10, $resultado->ultNsuRetornado);
        $this->assertSame(20, $resultado->maxNsu);
        $this->assertCount(1, $resultado->itens);
        $this->assertSame(self::CHAVE, $resultado->itens[0]->chaveAcesso);
    }

    public function test_decode_consulta_chave_funciona_dentro_do_envelope_soap_completo(): void
    {
        $retDistDFeInt = <<<XML
        <retDistDFeInt versao="1.01" xmlns="http://www.portalfiscal.inf.br/nfe">
          <tpAmb>1</tpAmb>
          <verAplic>SP1.0</verAplic>
          <cStat>138</cStat>
          <xMotivo>Documento localizado</xMotivo>
          <dhResp>2026-01-20T10:00:00-03:00</dhResp>
          <loteDistDFeInt>
            {$this->docZip($this->procNFe(self::CHAVE), 'procNFe_v4.00.xsd', 10)}
          </loteDistDFeInt>
        </retDistDFeInt>
        XML;

        $resultado = (new SefazRespostaDecoder)->decodeConsultaChave($this->envelopeSoap($retDistDFeInt));

        $this->assertInstanceOf(SefazDocumentoCompleto::class, $resultado);
        $this->assertSame(self::CHAVE, $resultado->chaveAcesso);
    }
}
