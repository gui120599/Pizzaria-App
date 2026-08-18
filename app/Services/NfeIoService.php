<?php

namespace App\Services;

use App\Exceptions\NfeIoException;
use App\Models\Empresa;
use App\Models\Venda;
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

    public function emitir(Venda $venda): array
    {
        $venda->loadMissing([
            'cliente',
            'itensVenda.produto.categoria',
            'itensVenda.adicionaisItemVenda.adicional',
            'pagamentos.opcaoPagamento',
            'pagamentos.cartao',
        ]);

        ['companyId' => $companyId, 'apiKey' => $apiKey] = $this->credenciais();

        $response = $this->client($apiKey)
            ->post(self::BASE_URL."/companies/{$companyId}/consumerinvoices", $this->montarPayload($venda));

        if ($response->failed()) {
            throw new NfeIoException(
                'Falha ao enfileirar emissão da NFC-e: '.($response->json('message') ?? $response->body()),
                $response->json()
            );
        }

        $data = $response->json() ?? [];

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

    /** Monta o payload sem enviar — usado pela tela de debug/preview (rota venda.gerar_JSONNFE). */
    public function payloadPreview(Venda $venda): array
    {
        $venda->loadMissing([
            'cliente',
            'itensVenda.produto.categoria',
            'itensVenda.adicionaisItemVenda.adicional',
            'pagamentos.opcaoPagamento',
            'pagamentos.cartao',
        ]);

        return $this->montarPayload($venda);
    }

    private function montarPayload(Venda $venda): array
    {
        return [
            'id' => (string) $venda->id,
            'payment' => $this->montarPagamentos($venda),
            'serie' => 1,
            'number' => $venda->id,
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

        // Rateia o frete da venda igualmente entre os itens.
        $qtdItens = $venda->itensVenda->count();
        $freteItem = $qtdItens > 0 ? round((float) ($venda->venda_valor_frete ?? 0) / $qtdItens, 2) : 0;

        foreach ($venda->itensVenda as $item) {
            $produto = $item->produto;

            $descAdicionais = '';
            foreach ($item->adicionaisItemVenda as $adicional) {
                $descAdicionais .= ' Adic. '.$adicional->adicional->adicional_nome;
            }

            $item404 = in_array($produto->produto_CSOSN, ['102', '500'], true);

            $itensArray[] = [
                'code' => (string) $produto->id,
                'codeGTIN' => $produto->produto_gtin ?? null,
                'description' => $produto->categoria->categoria_nome.' '.$produto->produto_descricao.$descAdicionais,
                'ncm' => $produto->produto_codigo_NCM ?? null,
                'cfop' => (int) $produto->produto_CFOP,
                'unit' => $produto->produto_unidade_comercial,
                'quantity' => $item->item_venda_quantidade,
                'unitAmount' => $item->item_venda_valor_unitario,
                'totalAmount' => (float) $item->item_venda_valor,
                'unitTax' => (string) $produto->produto_unidade_comercial,
                'quantityTax' => $item->item_venda_quantidade_tributavel,
                'taxUnitAmount' => $item->item_venda_valor_unitario,
                'discountAmount' => (float) $item->item_venda_desconto,
                'othersAmount' => $item->item_venda_valor_adicionais + $freteItem,
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
}
