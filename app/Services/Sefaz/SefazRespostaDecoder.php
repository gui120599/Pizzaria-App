<?php

namespace App\Services\Sefaz;

use App\Exceptions\SefazDocumentoNaoLocalizadoException;
use App\Exceptions\SefazIndisponivelException;
use App\Services\Sefaz\Dto\SefazDocumentoCompleto;
use App\Services\Sefaz\Dto\SefazLoteDistribuicao;
use App\Services\Sefaz\Dto\SefazResumoDocumento;
use Carbon\Carbon;
use SimpleXMLElement;

/**
 * Decodifica a resposta do webservice NFeDistribuicaoDFe (retDistDFeInt):
 * cada item do lote vem em <docZip> compactado em gzip+base64, identificado
 * pelo atributo "schema" (resNFe_v1.xx.xsd, procNFe_v4.00.xsd,
 * resEvento_1.00.xsd...). Não toca em rede/SOAP — só parsing puro,
 * testável com fixtures estáticas.
 *
 * A resposta crua da lib (nfephp-org/sped-nfe) é o envelope SOAP completo
 * (<soap:Envelope><soap:Body><nfeDistDFeInteresseResult><retDistDFeInt>...),
 * não o <retDistDFeInt> isolado — por isso todo XPath aqui usa "//" (busca
 * em qualquer profundidade) em vez de caminho relativo à raiz, o que também
 * continua funcionando com fixtures de teste que usam <retDistDFeInt> como
 * elemento raiz direto.
 */
class SefazRespostaDecoder
{
    /** Resposta do modo distNSU/consNSU: 0..50 resumos, com o cursor de continuação. */
    public function decodeLote(string $xmlResposta): SefazLoteDistribuicao
    {
        $xml = $this->carregar($xmlResposta);

        $itens = [];
        foreach ($xml->xpath('//retDistDFeInt/loteDistDFeInt/docZip') ?: [] as $docZip) {
            $resumo = $this->paraResumo(
                $this->descompactar((string) $docZip),
                (string) $docZip->attributes()->schema,
                (int) $docZip->attributes()->NSU,
            );

            if ($resumo !== null) {
                $itens[] = $resumo;
            }
        }

        return new SefazLoteDistribuicao(
            itens: $itens,
            ultNsuRetornado: (int) ($xml->xpath('//retDistDFeInt/ultNSU')[0] ?? 0),
            maxNsu: (int) ($xml->xpath('//retDistDFeInt/maxNSU')[0] ?? 0),
            cStat: trim((string) ($xml->xpath('//retDistDFeInt/cStat')[0] ?? '')),
        );
    }

    /**
     * Resposta do modo consChNFe: no máximo um item — XML completo se já
     * manifestado/liberado, senão um resumo.
     *
     * @throws SefazDocumentoNaoLocalizadoException se a SEFAZ não localizar nenhum documento pra chave
     *                                              (cStat 137 — comum quando a nota ainda não foi manifestada)
     */
    public function decodeConsultaChave(string $xmlResposta): SefazResumoDocumento|SefazDocumentoCompleto
    {
        $xml = $this->carregar($xmlResposta);

        $docZips = $xml->xpath('//retDistDFeInt/loteDistDFeInt/docZip') ?: [];
        if (empty($docZips)) {
            throw new SefazDocumentoNaoLocalizadoException('Nenhum documento localizado na SEFAZ para a chave informada.');
        }

        $docZip = $docZips[0];
        $schema = (string) $docZip->attributes()->schema;
        $conteudo = $this->descompactar((string) $docZip);

        if (str_starts_with($schema, 'procNFe')) {
            return new SefazDocumentoCompleto($this->extrairChaveDeProcNFe($conteudo), $conteudo);
        }

        $resumo = $this->paraResumo($conteudo, $schema, (int) $docZip->attributes()->NSU);
        if ($resumo === null) {
            throw new SefazIndisponivelException("Resposta da SEFAZ em formato inesperado (schema: {$schema}).");
        }

        return $resumo;
    }

    private function paraResumo(string $conteudo, string $schema, int $nsu): ?SefazResumoDocumento
    {
        if (str_starts_with($schema, 'resNFe')) {
            $doc = $this->carregar($conteudo);
            $vNF = $this->textoOuNull($doc->vNF);
            $dhEmi = $this->textoOuNull($doc->dhEmi);

            return new SefazResumoDocumento(
                nsu: $nsu,
                chaveAcesso: trim((string) $doc->chNFe),
                isEvento: false,
                cnpjEmitente: $this->textoOuNull($doc->CNPJ) ?? $this->textoOuNull($doc->CPF),
                nomeEmitente: $this->textoOuNull($doc->xNome),
                valor: $vNF !== null ? (float) $vNF : null,
                dataEmissao: $dhEmi !== null ? Carbon::parse($dhEmi) : null,
            );
        }

        if (str_starts_with($schema, 'resEvento')) {
            $doc = $this->carregar($conteudo);

            return new SefazResumoDocumento(
                nsu: $nsu,
                chaveAcesso: trim((string) $doc->chNFe),
                isEvento: true,
                cnpjEmitente: $this->textoOuNull($doc->CNPJ) ?? $this->textoOuNull($doc->CPF),
            );
        }

        if (str_starts_with($schema, 'procNFe')) {
            // XML completo apareceu direto no lote de NSU (incomum, mas possível
            // quando o solicitante é o próprio emitente/destinatário direto).
            // Trata como resumo pra seguir o mesmo fluxo de consultarPorChave
            // depois — simples e seguro, ainda que redundante nesse caso raro.
            return new SefazResumoDocumento(
                nsu: $nsu,
                chaveAcesso: $this->extrairChaveDeProcNFe($conteudo),
                isEvento: false,
            );
        }

        if (str_starts_with($schema, 'procEventoNFe')) {
            $doc = $this->carregar($conteudo);

            return new SefazResumoDocumento(
                nsu: $nsu,
                chaveAcesso: trim((string) ($doc->xpath('evento/infEvento/chNFe')[0] ?? '')),
                isEvento: true,
            );
        }

        return null;
    }

    private function extrairChaveDeProcNFe(string $conteudo): string
    {
        $xml = $this->carregar($conteudo);

        $infNFe = $xml->xpath('NFe/infNFe')[0] ?? $xml->xpath('infNFe')[0] ?? null;
        if ($infNFe === null) {
            throw new SefazIndisponivelException('XML completo da SEFAZ sem <infNFe> reconhecível.');
        }

        $chave = preg_replace('/\D/', '', (string) $infNFe->attributes()->Id) ?? '';
        if (strlen($chave) !== 44) {
            throw new SefazIndisponivelException('Chave de acesso inválida no XML completo devolvido pela SEFAZ.');
        }

        return $chave;
    }

    private function descompactar(string $base64Gzip): string
    {
        $binario = base64_decode(trim($base64Gzip), strict: true);
        if ($binario === false) {
            throw new SefazIndisponivelException('Conteúdo da SEFAZ não é base64 válido.');
        }

        $conteudo = @gzdecode($binario);
        if ($conteudo === false) {
            throw new SefazIndisponivelException('Conteúdo da SEFAZ não descompactou (gzip inválido).');
        }

        return $conteudo;
    }

    private function carregar(string $xmlConteudo): SimpleXMLElement
    {
        $semNamespace = preg_replace('/xmlns="[^"]*"/', '', trim($xmlConteudo));

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($semNamespace);
        libxml_clear_errors();

        if ($xml === false) {
            throw new SefazIndisponivelException('Resposta da SEFAZ não é um XML válido.');
        }

        return $xml;
    }

    private function textoOuNull(mixed $node): ?string
    {
        $texto = trim((string) $node);

        return $texto === '' ? null : $texto;
    }
}
