<?php

namespace App\Services;

use App\Exceptions\NfeIoException;
use App\Models\Empresa;
use App\Models\ItensVenda;
use App\Models\NfEmissao;
use App\Models\Venda;
use App\Services\Nfe\NfNumeracaoService;
use App\Support\RateioCentavos;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Cliente único para os 3 endpoints da NFC-e (nota de consumidor v2) da
 * NFe.io hoje usados pelo app: emitir, cancelar e consultar PDF do DANFE.
 * Substitui o Guzzle cru espalhado em NfeVendaController (que tinha
 * credenciais hardcoded em dois métodos e duas implementações duplicadas de
 * "enviar"/"buscar status") por um client único via Http::fake()-testável.
 *
 * Emissão e cancelamento são assíncronos na NFe.io: o retorno do POST/DELETE
 * só confirma que a operação entrou na fila, não o resultado final — por
 * isso sincronizarStatusLocal() existe separada, pra ser chamada via
 * polling (ver OperarVenda::verificarStatusNfe()) até o status mudar.
 */
class NfeIoService
{
    private const BASE_URL = 'https://api.nfse.io/v2';

    public function __construct(private readonly NfNumeracaoService $numeracaoService) {}

    /**
     * @return array{companyId: string, apiKey: string}
     */
    private function credenciais(): array
    {
        $empresa = Empresa::first();

        if (! $empresa || blank($empresa->empresa_api_nfeio_company_id) || blank($empresa->empresa_api_nfeio_apikey)) {
            throw new NfeIoException('Integração com a NFe.io não está configurada (company id / api key ausentes no cadastro da empresa).');
        }

        return [
            'companyId' => $empresa->empresa_api_nfeio_company_id,
            'apiKey' => $empresa->empresa_api_nfeio_apikey,
        ];
    }

    private function client(string $apiKey): PendingRequest
    {
        // Sem prefixo "Bearer": é o formato usado por enviarParaApi()/imprimirNFE()
        // no controller legado, a única variante hoje efetivamente em produção.
        return Http::withHeaders([
            'accept' => 'application/json',
            'Authorization' => $apiKey,
        ])->acceptJson();
    }

    /**
     * O número da nota é reservado (ou reaproveitado, em reenvio) antes do
     * POST e fica gravado em nf_emissoes mesmo se a NFe.io recusar — o número
     * só volta a ser usado pelo reenvio desta mesma venda.
     *
     * @param  NfEmissao::DECISAO_*  $decisao  se o envio foi decidido pela forma de pagamento ou pelo operador
     */
    public function emitir(Venda $venda, string $decisao = NfEmissao::DECISAO_MANUAL): array
    {
        $venda->loadMissing([
            'cliente',
            'itensVenda.produto.categoria',
            'itensVenda.adicionaisItemVenda.adicional',
            'pagamentos.opcaoPagamento',
            'pagamentos.cartao',
        ]);

        ['companyId' => $companyId, 'apiKey' => $apiKey] = $this->credenciais();

        $emissao = $this->numeracaoService->reservarPara($venda, $decisao);

        $response = $this->client($apiKey)
            ->post(self::BASE_URL."/companies/{$companyId}/consumerinvoices", $this->montarPayload($venda, $emissao->serie, $emissao->numero));

        if ($response->failed()) {
            $emissao->update(['status' => NfEmissao::STATUS_FALHA_ENVIO]);

            throw new NfeIoException(
                'Falha ao enfileirar emissão da NFC-e: '.($response->json('message') ?? $response->body()),
                $response->json()
            );
        }

        $data = $response->json() ?? [];

        $emissao->update([
            'status' => $data['status'] ?? 'Processing',
            'nfeio_id' => $data['id'] ?? $emissao->nfeio_id,
            'enviada_em' => now(),
        ]);

        if (filled($data['id'] ?? null)) {
            $venda->update(['venda_id_nfe' => $data['id']]);
        }

        return $data;
    }

    public function consultarStatus(string $invoiceId): array
    {
        ['companyId' => $companyId, 'apiKey' => $apiKey] = $this->credenciais();

        $response = $this->client($apiKey)
            ->get(self::BASE_URL."/companies/{$companyId}/consumerinvoices/{$invoiceId}");

        if ($response->failed()) {
            throw new NfeIoException('Falha ao consultar status da NFC-e: '.$response->body(), $response->json());
        }

        return $response->json() ?? [];
    }

    /** Consulta o status na NFe.io e persiste em venda_status_nfe. Devolve o status resultante. */
    public function sincronizarStatusLocal(Venda $venda): string
    {
        if (blank($venda->venda_id_nfe)) {
            throw new NfeIoException('Esta venda ainda não tem uma NFC-e emitida.');
        }

        $data = $this->consultarStatus($venda->venda_id_nfe);
        $status = $data['status'] ?? null;

        if (filled($status) && $status !== $venda->venda_status_nfe) {
            $venda->update(['venda_status_nfe' => $status]);
        }

        if (filled($status)) {
            NfEmissao::espelharStatus($venda->venda_id_nfe, $status);
        }

        return $status ?? $venda->venda_status_nfe ?? 'Processing';
    }

    public function cancelar(string $invoiceId): void
    {
        ['companyId' => $companyId, 'apiKey' => $apiKey] = $this->credenciais();

        $response = $this->client($apiKey)
            ->delete(self::BASE_URL."/companies/{$companyId}/consumerinvoices/{$invoiceId}");

        if ($response->failed()) {
            throw new NfeIoException('Falha ao solicitar cancelamento da NFC-e: '.$response->body(), $response->json());
        }
    }

    /** GET .../pdf devolve um JSON com {"uri": "..."} apontando pro PDF real (confirmado em resources/views/nfePDF.blade.php). */
    public function consultarPdfUrl(string $invoiceId): string
    {
        ['companyId' => $companyId, 'apiKey' => $apiKey] = $this->credenciais();

        $response = $this->client($apiKey)
            ->get(self::BASE_URL."/companies/{$companyId}/consumerinvoices/{$invoiceId}/pdf");

        if ($response->failed()) {
            throw new NfeIoException('Falha ao consultar PDF da NFC-e: '.$response->body(), $response->json());
        }

        $uri = $response->json('uri');

        if (blank($uri)) {
            throw new NfeIoException('A NFe.io não retornou a URL do PDF.', $response->json());
        }

        return $uri;
    }

    /** Monta o payload sem enviar nem reservar número — usado pela tela de debug/preview (rota venda.gerar_JSONNFE). */
    public function payloadPreview(Venda $venda): array
    {
        $venda->loadMissing([
            'cliente',
            'itensVenda.produto.categoria',
            'itensVenda.adicionaisItemVenda.adicional',
            'pagamentos.opcaoPagamento',
            'pagamentos.cartao',
        ]);

        $emissao = $venda->nfEmissaoAtual;

        if ($emissao && ! $emissao->numeroConsumido()) {
            return $this->montarPayload($venda, $emissao->serie, $emissao->numero);
        }

        return $this->montarPayload($venda, NfNumeracaoService::SERIE_PADRAO, $this->numeracaoService->proximoNumero());
    }

    private function montarPayload(Venda $venda, int $serie, int $numero): array
    {
        return [
            'id' => (string) $venda->id,
            'payment' => $this->montarPagamentos($venda),
            'serie' => $serie,
            'number' => $numero,
            'operationOn' => $venda->venda_datahora_finalizada,
            'operationNature' => 'Venda de mercadoria',
            'operationType' => 'Outgoing',
            'destination' => 'Internal_Operation',
            'purposeType' => 'Normal',
            'consumerType' => 'FinalConsumer',
            'presenceType' => 'Presence',
            'buyer' => $this->montarComprador($venda),
            'items' => $this->montarItens($venda),
        ];
    }

    /**
     * Venda fiado/parcial (ver FinalizacaoVendaService::finalizar()) finaliza com
     * venda_valor_pago < venda_valor_total — sem essa linha extra, o payload
     * declararia menos pagamento do que o valor total da nota (ou nenhum, em
     * fiado 100%), o que não reflete a operação real perante o fisco. 'withoutPayment'
     * é o método da NFe.io para o código SEFAZ tPag=90 ("Sem pagamento"), usado
     * justamente para venda a prazo sem quitação no ato.
     */
    private function montarPagamentos(Venda $venda): array
    {
        $pagamentoDetalhe = [];

        foreach ($venda->pagamentos as $pagamento) {
            $nomeOpcao = $pagamento->opcaoPagamento->opcaopag_nome ?? '';

            if (stripos($nomeOpcao, 'Cartão') !== false || stripos($nomeOpcao, 'Pix') !== false) {
                $pagamentoDetalhe[] = [
                    'method' => $pagamento->opcaoPagamento->opcaopag_desc_nfe,
                    'amount' => $pagamento->pg_venda_valor_pago_pelo_cliente,
                    'card' => [
                        'federalTaxNumber' => $pagamento->cartao->cartao_cnpj_credenciadora ?? null,
                        'flag' => $pagamento->cartao->cartao_bandeira ?? null,
                        'authorization' => $pagamento->pg_venda_numero_autorizacao_cartao ?? null,
                        'integrationPaymentType' => $pagamento->pg_venda_tipo_integracao ?? null,
                    ],
                ];
            } else {
                $pagamentoDetalhe[] = [
                    'method' => $pagamento->opcaoPagamento->opcaopag_desc_nfe,
                    'amount' => $pagamento->pg_venda_valor_pago_pelo_cliente,
                ];
            }
        }

        $saldoFiado = round((float) $venda->venda_valor_total - (float) $venda->venda_valor_pago, 2);
        if ($saldoFiado > 0.01) {
            $pagamentoDetalhe[] = [
                'method' => 'withoutPayment',
                'amount' => $saldoFiado,
            ];
        }

        return [[
            'paymentDetail' => $pagamentoDetalhe,
            'payback' => $venda->pagamentos->sum('pg_venda_valor_troco'),
        ]];
    }

    private function montarComprador(Venda $venda): ?array
    {
        $cliente = $venda->cliente;

        if (! $cliente) {
            return null;
        }

        $ehJuridica = in_array($cliente->cliente_tipo, ['Jurídica', 'PJ'], true);

        return [
            'stateTaxNumberIndicator' => 'NonTaxPayer',
            'tradeName' => $cliente->cliente_nome ?? null,
            'taxRegime' => 'isento',
            'stateTaxNumber' => $cliente->cliente_inscricao_estadual ?? null,
            'id' => (string) $cliente->id,
            'name' => $cliente->cliente_nome ?? null,
            'federalTaxNumber' => $ehJuridica ? (string) $cliente->cliente_cnpj : (string) $cliente->cliente_cpf,
            'email' => $cliente->cliente_email ?? null,
            'type' => $ehJuridica ? 4 : 2, // 2 Pessoa Física, 4 Pessoa Jurídica
        ];
    }

    private function montarItens(Venda $venda): array
    {
        $itensArray = [];

        // Rateia o frete da venda igualmente entre os itens, em centavos: com
        // round(frete / n) por item, R$ 4,00 em 3 itens virava 3,99 e a nota
        // ficava 1 centavo abaixo dos pagamentos (rejeitada).
        $fretePorItem = RateioCentavos::ratearProporcional(
            (int) round((float) ($venda->venda_valor_frete ?? 0) * 100),
            $venda->itensVenda->mapWithKeys(fn ($item) => [$item->id => 1])->all(),
        );

        // Taxa de serviço da mesa também vai em "outras despesas" (vOutro),
        // rateada pelo valor de cada item, em centavos — sem ela o total dos
        // pagamentos ficaria acima do total da nota.
        $taxaServicoPorItem = RateioCentavos::ratearProporcional(
            (int) round((float) $venda->venda_valor_taxa_servico * 100),
            $venda->itensVenda->mapWithKeys(fn ($item) => [$item->id => (int) round((float) $item->item_venda_valor * 100)])->all(),
        );

        foreach ($venda->itensVenda as $item) {
            $produto = $item->produto;
            $valores = $this->valoresDoItem($item);

            $descAdicionais = '';
            foreach ($item->adicionaisItemVenda as $adicional) {
                $descAdicionais .= ' Adic. '.$adicional->adicional->adicional_nome;
            }

            $item404 = in_array($produto->produto_CSOSN, ['102', '500'], true);

            $itensArray[] = [
                'code' => (string) $produto->id,
                'codeGTIN' => $produto->produto_gtin ?? null,
                // Pizza de sabores é um item só na nota: categoria + frações dos
                // sabores (ex.: PIZZA GRANDE 1/2 CALABRESA / 1/2 MUSSARELA). NCM,
                // CFOP e tributos seguem o produto da linha (1º sabor) — todos
                // os sabores são da mesma categoria. xProd tem teto de 120.
                'description' => mb_substr(
                    $produto->categoria->categoria_nome.' '
                        .($item->ehMultiSabor() ? $item->descricaoSabores(ascii: true) : $produto->produto_descricao)
                        .$descAdicionais,
                    0,
                    120,
                ),
                'ncm' => $produto->produto_codigo_NCM ?? null,
                'cfop' => (int) $produto->produto_CFOP,
                'unit' => $produto->produto_unidade_comercial,
                'quantity' => $item->item_venda_quantidade,
                'unitAmount' => $item->item_venda_valor_unitario,
                'totalAmount' => $valores['bruto'],
                'unitTax' => (string) $produto->produto_unidade_comercial,
                'quantityTax' => $item->item_venda_quantidade_tributavel,
                'taxUnitAmount' => $item->item_venda_valor_unitario,
                'discountAmount' => $valores['desconto'],
                'othersAmount' => round($valores['outros'] + (($fretePorItem[$item->id] ?? 0) + ($taxaServicoPorItem[$item->id] ?? 0)) / 100, 2),
                'totalIndicator' => (bool) $item->item_venda_valor,
                'cest' => $produto->produto_codigo_CEST,
                'tax' => $item404 ? [
                    'icms' => [
                        'origin' => $produto->produto_cod_origem_mercadoria,
                        'csosn' => $produto->produto_CSOSN,
                        'baseTax' => 0,
                        'amount' => 0,
                        'rate' => 0,
                    ],
                ] : [
                    'totalTax' => $item->item_venda_valor_total_tributos,
                    'icms' => [
                        'origin' => $produto->produto_cod_origem_mercadoria,
                        'baseTaxModality' => '3',
                        'baseTax' => $item->item_venda_valor_base_calculo,
                        'amount' => $item->item_venda_valor_icms,
                        'rate' => $produto->produto_valor_percentual_icms,
                        'csosn' => $produto->produto_CSOSN,
                    ],
                ],
            ];
        }

        return $itensArray;
    }

    /**
     * Valores do item na nota. A NFe.io/SEFAZ calcula o valor do produto
     * como quantidade × unitário (vProd), enquanto item_venda_valor é o valor
     * cobrado (vProd − desconto + adicionais). Quando os dois não fecham —
     * fração de pizza (0,33 × 86,90 = 28,68, mas o terço foi cobrado 28,97) —
     * a diferença entra como desconto ou outras despesas, para o total da nota
     * bater com o total da venda e com os pagamentos.
     *
     * @return array{bruto: float, desconto: float, outros: float}
     */
    private function valoresDoItem(ItensVenda $item): array
    {
        $brutoCents = (int) round((float) $item->item_venda_quantidade * (float) $item->item_venda_valor_unitario * 100);
        $descontoCents = (int) round((float) $item->item_venda_desconto * 100);
        $outrosCents = (int) round((float) $item->item_venda_valor_adicionais * 100);

        $ajusteCents = (int) round((float) $item->item_venda_valor * 100) - ($brutoCents - $descontoCents + $outrosCents);

        if ($ajusteCents > 0) {
            $outrosCents += $ajusteCents;
        } else {
            $descontoCents -= $ajusteCents;
        }

        return [
            'bruto' => $brutoCents / 100,
            'desconto' => $descontoCents / 100,
            'outros' => $outrosCents / 100,
        ];
    }
}
