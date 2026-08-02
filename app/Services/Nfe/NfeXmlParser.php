<?php

namespace App\Services\Nfe;

use App\Exceptions\NfeXmlInvalidoException;
use App\Services\Nfe\Dto\NfeEmitente;
use App\Services\Nfe\Dto\NfeItem;
use App\Services\Nfe\Dto\NfeParseada;
use Carbon\CarbonImmutable;
use SimpleXMLElement;
use Throwable;

/**
 * Lê o XML de uma NF-e de compra (modelo padrão SEFAZ, envelope <nfeProc>
 * ou <NFe> puro) e extrai os dados necessários para pré-preencher uma
 * Compra. Não toca em Eloquent/banco — só parsing puro.
 */
class NfeXmlParser
{
    public function parse(string $xmlConteudo): NfeParseada
    {
        $conteudo = trim($xmlConteudo);
        if ($conteudo === '') {
            throw new NfeXmlInvalidoException('Arquivo XML vazio.');
        }

        // O default namespace (xmlns="...") atrapalha o acesso por propriedade
        // do SimpleXML; como é leitura (não validação de schema), removê-lo
        // simplifica a navegação sem perder nenhum dado.
        $semNamespace = preg_replace('/xmlns="[^"]*"/', '', $conteudo);

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($semNamespace);
        libxml_clear_errors();

        if ($xml === false) {
            throw new NfeXmlInvalidoException('O arquivo enviado não é um XML válido.');
        }

        $infNFe = $this->localizarInfNFe($xml);
        $autorizacaoConfirmada = $this->validarAutorizacao($xml);

        $ide = $infNFe->ide;
        $total = $infNFe->total->ICMSTot;

        if (! isset($total->vNF)) {
            throw new NfeXmlInvalidoException('NF-e sem bloco de totais (ICMSTot).');
        }

        return new NfeParseada(
            chaveAcesso: $this->extrairChaveAcesso($infNFe),
            numero: $this->textoOuNull($ide->nNF),
            serie: $this->textoOuNull($ide->serie),
            dataEmissao: $this->extrairData((string) ($ide->dhEmi ?? $ide->dEmi ?? '')),
            modeloReconhecido: $this->textoOuNull($ide->mod) === '55',
            autorizacaoConfirmada: $autorizacaoConfirmada,
            emitente: $this->extrairEmitente($infNFe->emit),
            valorProdutos: (float) $total->vProd,
            valorFrete: (float) $total->vFrete,
            valorDesconto: (float) $total->vDesc,
            valorOutros: (float) $total->vOutro,
            valorTotalNota: (float) $total->vNF,
            itens: $this->extrairItens($infNFe),
        );
    }

    private function localizarInfNFe(SimpleXMLElement $xml): SimpleXMLElement
    {
        if ($xml->getName() === 'infNFe') {
            return $xml;
        }

        foreach (['NFe/infNFe', 'infNFe'] as $caminho) {
            $resultado = $xml->xpath($caminho);
            if (! empty($resultado)) {
                return $resultado[0];
            }
        }

        throw new NfeXmlInvalidoException('Layout de NF-e não reconhecido: elemento <infNFe> não encontrado.');
    }

    /**
     * Bloqueia notas canceladas/denegadas quando o envelope traz o protocolo
     * (<protNFe>). Retorna se a autorização foi de fato confirmada — um XML
     * "puro" (sem envelope) não traz esse protocolo, então não há como saber
     * o status junto à SEFAZ só com esse arquivo.
     */
    private function validarAutorizacao(SimpleXMLElement $xml): bool
    {
        $cStatNodes = $xml->xpath('protNFe/infProt/cStat');
        if (empty($cStatNodes)) {
            return false;
        }

        $cStat = trim((string) $cStatNodes[0]);
        if ($cStat !== '100') {
            $motivoNodes = $xml->xpath('protNFe/infProt/xMotivo');
            $motivo = $motivoNodes ? trim((string) $motivoNodes[0]) : "status {$cStat}";

            throw new NfeXmlInvalidoException("Nota fiscal não autorizada pela SEFAZ: {$motivo}.");
        }

        return true;
    }

    private function extrairChaveAcesso(SimpleXMLElement $infNFe): string
    {
        $id = (string) $infNFe->attributes()->Id;
        $chave = preg_replace('/\D/', '', $id) ?? '';

        if (strlen($chave) !== 44) {
            throw new NfeXmlInvalidoException('Chave de acesso da NF-e inválida (esperado 44 dígitos).');
        }

        return $chave;
    }

    private function extrairEmitente(SimpleXMLElement $emit): NfeEmitente
    {
        $documento = preg_replace('/\D/', '', (string) ($emit->CNPJ ?? $emit->CPF ?? '')) ?? '';
        if ($documento === '') {
            throw new NfeXmlInvalidoException('Emitente da NF-e sem CNPJ/CPF.');
        }

        $endereco = $emit->enderEmit;

        return new NfeEmitente(
            documento: $documento,
            razaoSocial: $this->textoOuNull($emit->xNome),
            nomeFantasia: $this->textoOuNull($emit->xFant),
            inscricaoEstadual: $this->textoOuNull($emit->IE),
            email: $this->textoOuNull($emit->email),
            telefone: $this->textoOuNull($endereco->fone),
            cep: $this->textoOuNull($endereco->CEP),
            endereco: $this->textoOuNull($endereco->xLgr),
            numero: $this->textoOuNull($endereco->nro),
            complemento: $this->textoOuNull($endereco->xCpl),
            bairro: $this->textoOuNull($endereco->xBairro),
            cidade: $this->textoOuNull($endereco->xMun),
            uf: $this->textoOuNull($endereco->UF),
        );
    }

    /** @return NfeItem[] */
    private function extrairItens(SimpleXMLElement $infNFe): array
    {
        $itens = [];

        foreach ($infNFe->det as $det) {
            $prod = $det->prod;

            $ean = $this->textoOuNull($prod->cEAN);
            // 'SEM GTIN' é o valor sentinela padrão da própria NF-e (e,
            // coincidentemente, também o default do cadastro de produtos) —
            // nunca pode ser usado para casar item com produto.
            if ($ean !== null && strtoupper($ean) === 'SEM GTIN') {
                $ean = null;
            }

            // Um item pode ter múltiplos blocos <rastro> (multi-lote); por
            // simplicidade usamos só o primeiro — o usuário confere/ajusta
            // lote e validade manualmente na revisão se precisar dos demais.
            $temRastro = isset($prod->rastro);
            $rastro = $prod->rastro;

            $itens[] = new NfeItem(
                codigoFornecedor: (string) $prod->cProd,
                descricao: (string) $prod->xProd,
                ean: $ean,
                unidadeComercial: (string) $prod->uCom,
                quantidadeComercial: (float) $prod->qCom,
                valorUnitarioComercial: (float) $prod->vUnCom,
                valorTotalBruto: (float) $prod->vProd,
                valorDesconto: (float) $prod->vDesc,
                loteCodigo: $temRastro ? $this->textoOuNull($rastro->nLote) : null,
                validade: $temRastro ? $this->extrairData((string) $rastro->dVal) : null,
            );
        }

        if (empty($itens)) {
            throw new NfeXmlInvalidoException('NF-e sem itens (<det>).');
        }

        return $itens;
    }

    private function extrairData(string $valor): ?CarbonImmutable
    {
        if ($valor === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($valor);
        } catch (Throwable) {
            return null;
        }
    }

    private function textoOuNull(mixed $node): ?string
    {
        $texto = trim((string) $node);

        return $texto === '' ? null : $texto;
    }
}
