<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use App\Models\Venda;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;

/**
 * Integração de emissão de NFC-e/NFS-e via nfse.io, extraída do antigo
 * VendaController (que concentrava tanto o fluxo operacional do PDV quanto
 * essa integração fiscal). Fora de escopo da migração do PDV para Filament
 * (ver App\Filament\Pages\OperarVenda) — realocada como está, sem reescrita
 * de lógica, apenas para não deixar o controller do PDV inchado.
 */
class NfeVendaController extends Controller
{
    public function enviarNfe($vendaId)
    {
        // Busca a venda pelo ID e carrega os relacionamentos necessários
        $venda = Venda::with(['cliente', 'itensVenda.produto', 'pagamentos.opcaoPagamento', 'pagamentos.cartao'])->findOrFail($vendaId);

        // Monta o array com os dados da venda baseado no modelo fornecido
        $nfeData = [
            'id' => (string) $venda->id,
            'payment' => $this->montarPagamentos($venda),
            'serie' => 1, // Ajuste conforme necessário
            'number' => $venda->id, // Ajuste conforme necessário
            'operationOn' => $venda->venda_datahora_finalizada,
            'operationNature' => 'Venda de mercadoria', // Ajuste conforme necessário
            'operationType' => 'Outgoing', // Ajuste conforme necessário
            'destination' => 'Internal_Operation', // Ajuste conforme necessário
            'purposeType' => 'Normal', // Ajuste conforme necessário
            'consumerType' => 'FinalConsumer', // Ajuste conforme necessário
            'presenceType' => 'Presence',
            'buyer' => $this->montarComprador($venda),
            'items' => $this->montarItens($venda),

            /*"printType" => 0,
            "contingencyOn" => null,
            "contingencyJustification" => null, // Ajuste conforme necessário
            "totals" => $this->montarTotais($venda),
            "transport" => $this->montarTransporte($venda),
            "additionalInformation" => $this->montarInformacoesAdicionais($venda),
            "billing" => $this->montarCobranca($venda),
            "issuer" => [
                "stStateTaxNumber" => null, // Ajuste conforme necessário
            ]*/
        ];
        // return response()->json($nfeData);

        // Envia o array para a API
        $response = $this->enviarParaApi($nfeData);
        // return response()->json([$nfeData,$response]);

        // Decodifica a resposta JSON para um array associativo
        // 'true' para obter o array associativo

        // Certifique-se de que a resposta foi decodificada corretamente
        if (is_array($response) && isset($response['id'])) {
            // Pega o campo "id" do JSON
            $idNfe = $response['id'];

            // Salva o id no campo venda_id_nfe
            $venda->venda_id_nfe = $idNfe;
            $venda->save();

            // Caso a nota autorize, ele atualiza o status
            $response_status = $this->atualizaStatusNFE($venda);

            // return redirect()->route('venda')->with('success', 'NFC-E Enviada com sucesso! Verifique em NOTAS FISCAIS se a mesma foi gerada!');

            return redirect()->route('sessao_caixa.vendas', ['sessao_caixa' => $venda->venda_sessao_caixa_id])->with('success', 'NFC-E Enviada com sucesso! Verifique em NOTAS FISCAIS se a mesma foi gerada!');
        } else {
            $responseArray = $response->getData(true);
            // Captura a mensagem de erro retornada pela API
            $errorDetail = isset($responseArray['error']) ? $responseArray['error'] : 'Erro inesperado ao se comunicar com a API da NFSe.';

            // Retorna a mensagem de erro para o usuário
            return redirect()->route('sessao_caixa.vendas', ['sessao_caixa' => $venda->venda_sessao_caixa_id])
                ->with('error', $errorDetail); // Passa a mensagem de erro para a sessão
        }
    }

    public function jsonNFE($vendaId)
    {
        // Busca a venda pelo ID e carrega os relacionamentos necessários
        $venda = Venda::with(['cliente', 'itensVenda.produto', 'pagamentos.opcaoPagamento', 'pagamentos.cartao'])->findOrFail($vendaId);

        // Monta o array com os dados da venda baseado no modelo fornecido
        $nfeData = [
            'id' => (string) $venda->id,
            'payment' => $this->montarPagamentos($venda),
            'serie' => 1, // Ajuste conforme necessário
            'number' => $venda->id, // Ajuste conforme necessário
            'operationOn' => $venda->venda_datahora_finalizada,
            'operationNature' => 'Venda de mercadoria', // Ajuste conforme necessário
            'operationType' => 'Outgoing', // Ajuste conforme necessário
            'destination' => 'Internal_Operation', // Ajuste conforme necessário
            'purposeType' => 'Normal', // Ajuste conforme necessário
            'consumerType' => 'FinalConsumer', // Ajuste conforme necessário
            'presenceType' => 'Presence',
            'buyer' => $this->montarComprador($venda),
            'items' => $this->montarItens($venda),

            /*"printType" => 0,
            "contingencyOn" => null,
            "contingencyJustification" => null, // Ajuste conforme necessário
            "totals" => $this->montarTotais($venda),
            "transport" => $this->montarTransporte($venda),
            "additionalInformation" => $this->montarInformacoesAdicionais($venda),
            "billing" => $this->montarCobranca($venda),
            "issuer" => [
                "stStateTaxNumber" => null, // Ajuste conforme necessário
            ]*/
        ];

        return response()->json($nfeData);
    }

    public function removerIdNfe($vendaId, $idNfe)
    {
        $venda = Venda::where('id', $vendaId)->where('venda_id_nfe', $idNfe)->first();

        if ($venda) {
            $venda->update(['venda_id_nfe' => null]);

            return redirect()->route('nota_fiscal')->with('success', 'Id da NFE removido com sucesso da venda!');
        }

        return redirect()->route('nota_fiscal')->with('error', 'Venda não encontrada!');
    }

    private function montarPagamentos(Venda $venda)
    {
        $pagamentosArray = [];
        $pagamentoDetalhe = [];

        foreach ($venda->pagamentos as $pagamento) {
            if (stripos($pagamento->opcaoPagamento->opcaopag_nome, 'Cartão') !== false || stripos($pagamento->opcaoPagamento->opcaopag_nome, 'Pix') !== false) { // O nome da opção de pagamento contém a palavra "cartão"
                $pagamentoDetalhe[] = [
                    'method' => $pagamento->opcaoPagamento->opcaopag_desc_nfe,  // Nome do método de pagamento
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
                    'method' => $pagamento->opcaoPagamento->opcaopag_desc_nfe,  // Nome do método de pagamento
                    'amount' => $pagamento->pg_venda_valor_pago_pelo_cliente,
                ];
            }
        }
        $pagamentosArray[] = [
            'paymentDetail' => $pagamentoDetalhe,
            'payback' => $venda->pagamentos->sum('pg_venda_valor_troco'),
        ];

        return $pagamentosArray;
    }

    private function montarComprador(Venda $venda)
    {
        $cliente = $venda->cliente;
        if ($cliente) {
            switch ($cliente->cliente_tipo) {
                case 'Física':
                    return [
                        'stateTaxNumberIndicator' => 'NonTaxPayer', // 0 - Nenhum (None) 1 - Contribuinte ICMS - informar a IE do destinatário (TaxPayer) 2 - Contribuinte isento de Inscrição no cadastro de Contribuintes (Exempt) 9 - Não Contribuinte, que pode ou não possuir Inscrição Estadual no Cadastro de Contribuintes do ICMS (NonTaxPayer)
                        'tradeName' => $cliente->cliente_nome ?? null, // Ajuste conforme necessário
                        'taxRegime' => 'isento', // Ajuste conforme necessário
                        'stateTaxNumber' => $cliente->cliente_inscricao_estadual ?? null, // Ajuste conforme necessário
                        'id' => (string) $cliente->id ?? null,
                        'name' => $cliente->cliente_nome ?? null,
                        'federalTaxNumber' => (string) $cliente->cliente_cpf ?? null,
                        'email' => $cliente->cliente_email ?? null,
                        'type' => 2, // 0 - Indefinido (Undefined) 2 - Pessoa Física (NaturalPerson) 4 - Pessoa Jurídica (LegalEntity)
                        /*"address" => [
                            "phone" => $cliente->cliente_celular ?? null,
                            "state" => $cliente->cliente_estado ?? null,
                            "city" => [
                                "code" => $cliente->cliente_municicodigo_municipio ?? null,
                                "name" => $cliente->cliente_cidade ?? null,
                            ],
                            "district" => $cliente->clinete_bairro ?? null,
                            "additionalInformation" => $cliente->cliente_endereco ?? null,
                            "street" => $cliente->cliente_endereco ?? null,
                            "number" => $cliente->cliente_numero ?? null,
                            "postalCode" => $cliente->cliente_cep ?? null,
                            "country" => "BR" // Ajuste conforme necessário
                        ],*/
                    ];
                    break;
                case 'PF':
                    return [
                        'stateTaxNumberIndicator' => 'NonTaxPayer', // 0 - Nenhum (None) 1 - Contribuinte ICMS - informar a IE do destinatário (TaxPayer) 2 - Contribuinte isento de Inscrição no cadastro de Contribuintes (Exempt) 9 - Não Contribuinte, que pode ou não possuir Inscrição Estadual no Cadastro de Contribuintes do ICMS (NonTaxPayer)
                        'tradeName' => $cliente->cliente_nome ?? null, // Ajuste conforme necessário
                        'taxRegime' => 'isento', // Ajuste conforme necessário
                        'stateTaxNumber' => $cliente->cliente_inscricao_estadual ?? null, // Ajuste conforme necessário
                        'id' => (string) $cliente->id ?? null,
                        'name' => $cliente->cliente_nome ?? null,
                        'federalTaxNumber' => (string) $cliente->cliente_cpf ?? null,
                        'email' => $cliente->cliente_email ?? null,
                        'type' => 2, // 0 - Indefinido (Undefined) 2 - Pessoa Física (NaturalPerson) 4 - Pessoa Jurídica (LegalEntity)
                        /*"address" => [
                            "phone" => $cliente->cliente_celular ?? null,
                            "state" => $cliente->cliente_estado ?? null,
                            "city" => [
                                "code" => $cliente->cliente_municicodigo_municipio ?? null,
                                "name" => $cliente->cliente_cidade ?? null,
                            ],
                            "district" => $cliente->clinete_bairro ?? null,
                            "additionalInformation" => $cliente->cliente_endereco ?? null,
                            "street" => $cliente->cliente_endereco ?? null,
                            "number" => $cliente->cliente_numero ?? null,
                            "postalCode" => $cliente->cliente_cep ?? null,
                            "country" => "BR" // Ajuste conforme necessário
                        ],*/
                    ];
                    break;
                case 'Jurídica':
                    return [
                        'stateTaxNumberIndicator' => 'NonTaxPayer', // 0 - Nenhum (None) 1 - Contribuinte ICMS - informar a IE do destinatário (TaxPayer) 2 - Contribuinte isento de Inscrição no cadastro de Contribuintes (Exempt) 9 - Não Contribuinte, que pode ou não possuir Inscrição Estadual no Cadastro de Contribuintes do ICMS (NonTaxPayer)
                        'tradeName' => $cliente->cliente_nome ?? null, // Ajuste conforme necessário
                        'taxRegime' => 'isento', // Ajuste conforme necessário
                        'stateTaxNumber' => $cliente->cliente_inscricao_estadual ?? null, // Ajuste conforme necessário
                        'id' => (string) $cliente->id ?? null,
                        'name' => $cliente->cliente_nome ?? null,
                        'federalTaxNumber' => (string) $cliente->cliente_cnpj ?? null,
                        'email' => $cliente->cliente_email ?? null,
                        'type' => 4, // 0 - Indefinido (Undefined) 2 - Pessoa Física (NaturalPerson) 4 - Pessoa Jurídica (LegalEntity)
                        /*"address" => [
                            "phone" => $cliente->cliente_celular ?? null,
                            "state" => $cliente->cliente_estado ?? null,
                            "city" => [
                                "code" => $cliente->cliente_municicodigo_municipio ?? null,
                                "name" => $cliente->cliente_cidade ?? null,
                            ],
                            "district" => $cliente->clinete_bairro ?? null,
                            "additionalInformation" => $cliente->cliente_endereco ?? null,
                            "street" => $cliente->cliente_endereco ?? null,
                            "number" => $cliente->cliente_numero ?? null,
                            "postalCode" => $cliente->cliente_cep ?? null,
                            "country" => "BR" // Ajuste conforme necessário
                        ],*/
                    ];
                    break;
                case 'PJ':
                    return [
                        'stateTaxNumberIndicator' => 'NonTaxPayer', // 0 - Nenhum (None) 1 - Contribuinte ICMS - informar a IE do destinatário (TaxPayer) 2 - Contribuinte isento de Inscrição no cadastro de Contribuintes (Exempt) 9 - Não Contribuinte, que pode ou não possuir Inscrição Estadual no Cadastro de Contribuintes do ICMS (NonTaxPayer)
                        'tradeName' => $cliente->cliente_nome ?? null, // Ajuste conforme necessário
                        'taxRegime' => 'isento', // Ajuste conforme necessário
                        'stateTaxNumber' => $cliente->cliente_inscricao_estadual ?? null, // Ajuste conforme necessário
                        'id' => (string) $cliente->id ?? null,
                        'name' => $cliente->cliente_nome ?? null,
                        'federalTaxNumber' => (string) $cliente->cliente_cnpj ?? null,
                        'email' => $cliente->cliente_email ?? null,
                        'type' => 4, // 0 - Indefinido (Undefined) 2 - Pessoa Física (NaturalPerson) 4 - Pessoa Jurídica (LegalEntity)
                        /*"address" => [
                            "phone" => $cliente->cliente_celular ?? null,
                            "state" => $cliente->cliente_estado ?? null,
                            "city" => [
                                "code" => $cliente->cliente_municicodigo_municipio ?? null,
                                "name" => $cliente->cliente_cidade ?? null,
                            ],
                            "district" => $cliente->clinete_bairro ?? null,
                            "additionalInformation" => $cliente->cliente_endereco ?? null,
                            "street" => $cliente->cliente_endereco ?? null,
                            "number" => $cliente->cliente_numero ?? null,
                            "postalCode" => $cliente->cliente_cep ?? null,
                            "country" => "BR" // Ajuste conforme necessário
                        ],*/
                    ];
                    break;

                default:
                    return null;
                    break;
            }
        }

        return null;
    }

    private function montarTotais(Venda $venda)
    {
        return [
            'icms' => [
                'baseTax' => $venda->venda_valor_base_calculo,
                'icmsAmount' => $venda->venda_valor_icms,
                'productAmount' => $venda->venda_valor_itens,
                'freightAmount' => $venda->venda_valor_frete,
                'insuranceAmount' => $venda->venda_valor_seguro,
                'discountAmount' => $venda->venda_valor_desconto,
                'invoiceAmount' => $venda->venda_valor_total,
                'ipiAmount' => 0,
                'pisAmount' => $venda->venda_valor_pis,
                'cofinsAmount' => $venda->venda_valor_cofins,
                // Adicione os demais campos conforme necessário...
            ],
            'issqn' => [
                'totalServiceNotTaxedICMS' => 0, // Ajuste conforme necessário
                // Adicione os demais campos conforme necessário...
            ],
        ];
    }

    private function montarTransporte(Venda $venda)
    {
        // Exemplo de montagem dos dados de transporte
        return [
            'freightModality' => 9,
            'transportGroup' => [
                'stateTaxNumber' => null,
                'transportRetention' => null,
                // Adicione os demais campos conforme necessário...
            ],
            // Adicione os demais campos conforme necessário...
        ];
    }

    private function montarInformacoesAdicionais(Venda $venda)
    {
        return [
            'fisco' => null,
            'taxpayer' => null,
            'xmlAuthorized' => null,
            'effort' => null,
            'order' => null,
            'contract' => null,
            // Adicione os demais campos conforme necessário...
        ];
    }

    private function montarItens(Venda $venda)
    {
        // Obtém os dados da empresa
        $empresa = Empresa::first();

        $itensArray = [];

        // Rateia o valor do frete da venda (se houver) dividindo igualmente entre a quantidade de itens.
        $qtdItens = $venda->itensVenda->count();
        $freteItem = $qtdItens > 0 ? round((float) ($venda->venda_valor_frete ?? 0) / $qtdItens, 2) : 0;

        foreach ($venda->itensVenda as $item) {
            $produto = $item->produto;

            switch ($produto->produto_CSOSN) {
                case '101':
                    $descAdicionais = '';
                    if ($item->adicionaisItemVenda) {
                        foreach ($item->adicionaisItemVenda as $adicional) {
                            $descAdicionais .= ' Adic. '.$adicional->adicional->adicional_nome;
                        }
                    } else {
                    }

                    $itensArray[] = [
                        'code' => (string) $produto->id,
                        'codeGTIN' => $produto->produto_gtin ?? null,
                        'description' => $produto->categoria->categoria_nome.' '.$produto->produto_descricao.''.$descAdicionais,
                        /* "description" => "NOTA FISCAL EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL", */
                        'ncm' => $produto->produto_codigo_NCM ?? null,
                        'cfop' => (int) $produto->produto_CFOP ?? null,
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
                        'tax' => [
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
                    break;
                case '102':
                    $descAdicionais = '';
                    if ($item->adicionaisItemVenda) {
                        foreach ($item->adicionaisItemVenda as $adicional) {
                            $descAdicionais .= ' Adic. '.$adicional->adicional->adicional_nome;
                        }
                    }

                    $itensArray[] = [
                        'code' => (string) $produto->id,
                        'codeGTIN' => $produto->produto_gtin ?? null,
                        'description' => $produto->categoria->categoria_nome.' '.$produto->produto_descricao.''.$descAdicionais,
                        /* "description" => "NOTA FISCAL EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL", */
                        'ncm' => $produto->produto_codigo_NCM ?? null,
                        'cfop' => (int) $produto->produto_CFOP ?? null,
                        'unit' => $produto->produto_unidade_comercial,
                        'quantity' => $item->item_venda_quantidade,
                        'unitAmount' => $item->item_venda_valor_unitario,
                        'totalAmount' => (float) $item->item_venda_valor,
                        'unitTax' => (string) $produto->produto_unidade_comercial,
                        'quantityTax' => $item->item_venda_quantidade_tributavel,
                        'taxUnitAmount' => $item->item_venda_valor_unitario,
                        'discountAmount' => $item->item_venda_desconto,
                        'othersAmount' => $item->item_venda_valor_adicionais + $freteItem,
                        'totalIndicator' => (bool) $item->item_venda_valor,
                        'cest' => $produto->produto_codigo_CEST,
                        'tax' => [
                            'icms' => [
                                'origin' => $produto->produto_cod_origem_mercadoria,
                                'csosn' => $produto->produto_CSOSN,
                                'baseTax' => 0,
                                'amount' => 0,
                                'rate' => 0,
                            ],
                        ],
                    ];
                    break;
                case '500':
                    $descAdicionais = '';
                    if ($item->adicionaisItemVenda) {
                        foreach ($item->adicionaisItemVenda as $adicional) {
                            $descAdicionais = ' Adic. '.$adicional->adicional->adicional_nome;
                        }
                    } else {
                    }

                    $itensArray[] = [
                        'code' => (string) $produto->id,
                        'codeGTIN' => $produto->produto_gtin ?? null,
                        'description' => $produto->categoria->categoria_nome.' '.$produto->produto_descricao.''.$descAdicionais,
                        /* "description" => "NOTA FISCAL EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL", */
                        'ncm' => $produto->produto_codigo_NCM ?? null,
                        'cfop' => (int) $produto->produto_CFOP ?? null,
                        'unit' => $produto->produto_unidade_comercial,
                        'quantity' => $item->item_venda_quantidade,
                        'unitAmount' => $item->item_venda_valor_unitario,
                        'totalAmount' => (float) $item->item_venda_valor,
                        'unitTax' => (string) $produto->produto_unidade_comercial,
                        'quantityTax' => $item->item_venda_quantidade_tributavel,
                        'taxUnitAmount' => $item->item_venda_valor_unitario,
                        'discountAmount' => $item->item_venda_desconto,
                        'othersAmount' => $item->item_venda_valor_adicionais + $freteItem,
                        'totalIndicator' => (bool) $item->item_venda_valor,
                        'cest' => $produto->produto_codigo_CEST,
                        'tax' => [
                            'icms' => [
                                'origin' => $produto->produto_cod_origem_mercadoria,
                                'csosn' => $produto->produto_CSOSN,
                                'baseTax' => 0,
                                'amount' => 0,
                                'rate' => 0,
                            ],
                        ],
                    ];
                    break;
                default:
                    $descAdicionais = '';
                    if ($item->adicionaisItemVenda) {
                        foreach ($item->adicionaisItemVenda as $adicional) {
                            $descAdicionais = ' Adic. '.$adicional->adicional->adicional_nome;
                        }
                    } else {
                    }

                    $itensArray[] = [
                        'code' => (string) $produto->id,
                        'codeGTIN' => $produto->produto_gtin ?? null,
                        'description' => $produto->categoria->categoria_nome.' '.$produto->produto_descricao.''.$descAdicionais,
                        /* "description" => "NOTA FISCAL EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL", */
                        'ncm' => $produto->produto_codigo_NCM ?? null,
                        'cfop' => (int) $produto->produto_CFOP ?? null,
                        'unit' => $produto->produto_unidade_comercial,
                        'quantity' => $item->item_venda_quantidade,
                        'unitAmount' => $item->item_venda_valor_unitario,
                        'totalAmount' => (float) $item->item_venda_valor,
                        'unitTax' => (string) $produto->produto_unidade_comercial,
                        'quantityTax' => $item->item_venda_quantidade_tributavel,
                        'taxUnitAmount' => $item->item_venda_valor_unitario,
                        'discountAmount' => $item->item_venda_desconto,
                        'othersAmount' => $item->item_venda_valor_adicionais + $freteItem,
                        'totalIndicator' => (bool) $item->item_venda_valor,
                        'cest' => $produto->produto_codigo_CEST,
                        'tax' => [
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
                    break;
            }
        }

        return $itensArray;
    }

    private function montarCobranca(Venda $venda)
    {
        return [
            'bill' => [
                'number' => null, // Ajuste conforme necessário
                'originalAmount' => $venda->valor_total,
                'discountAmount' => $venda->valor_desconto,
                'netAmount' => $venda->valor_total_liquido,
            ],
            'duplicates' => [
                [
                    'number' => null, // Ajuste conforme necessário
                    'expirationOn' => now()->toIso8601String(),
                    'amount' => (float) $venda->valor_total_liquido,
                ],
            ],
        ];
    }

    public function enviarParaApi2(array $data)
    {
        // Inicializa o cliente HTTP do Guzzle
        $client = new Client;

        // Obtém os dados da empresa
        $empresa = Empresa::first();
        $companyId = $empresa->empresa_api_nfeio_company_id;
        $apiKey = $empresa->empresa_api_nfeio_apikey;

        // URL da API
        $url = "https://api.nfse.io/v2/companies/{$companyId}/consumerinvoices";

        try {
            // Verifique o JSON antes de enviar
            $jsonPayload = json_encode($data, JSON_PRETTY_PRINT);
            if ($jsonPayload === false) {
                return response()->json(['error' => 'Erro ao gerar JSON: '.json_last_error_msg()], 500);
            }

            // Faz a requisição POST para a API
            $response = $client->request('POST', $url, [
                'headers' => [
                    'accept' => 'application/json',
                    'Authorization' => 'Bearer '.$apiKey, // Adicione 'Bearer ' se necessário
                    'Content-Type' => 'application/json',
                ],
                'body' => $jsonPayload,
            ]);

            // Decodifica o corpo da resposta JSON
            $statusCode = $response->getStatusCode();
            $content = $response->getBody()->getContents();

            // Verifica se a requisição foi bem-sucedida
            if ($statusCode === 200) {
                return json_decode($content, true);
            }

            // Retorno em caso de falha
            return response()->json(['error' => 'Falha ao enviar dados para a API. Status Code: '.$statusCode, 'response' => $content], $statusCode);
        } catch (ClientException $e) {
            // Captura exceções específicas do cliente HTTP
            $response = $e->getResponse();
            $responseBodyAsString = $response ? $response->getBody()->getContents() : 'Sem resposta da API';

            return response()->json(['error' => 'Erro ao se comunicar com a API: '.$responseBodyAsString, 'status_code' => $response ? $response->getStatusCode() : 'Desconhecido'], $response ? $response->getStatusCode() : 500);
        } catch (ServerException $e) {
            // Captura exceções do servidor (5xx)
            $response = $e->getResponse();
            $responseBodyAsString = $response ? $response->getBody()->getContents() : 'Sem resposta da API';

            return response()->json(['error' => 'Erro no servidor da API: '.$responseBodyAsString, 'status_code' => $response ? $response->getStatusCode() : 'Desconhecido'], $response ? $response->getStatusCode() : 500);
        } catch (\Exception $e) {
            // Tratamento de exceção geral
            return response()->json(['error' => 'Erro inesperado: '.$e->getMessage()], 500);
        }
    }

    public function enviarParaApi(array $data)
    {
        // Inicializa o cliente HTTP do Guzzle
        $client = new Client;

        // Obtém os dados da empresa
        $empresa = Empresa::first();
        $companyId = $empresa->empresa_api_nfeio_company_id;
        $apiKey = $empresa->empresa_api_nfeio_apikey;

        // URL da API
        $url = "https://api.nfse.io/v2/companies/{$companyId}/consumerinvoices";

        try {
            // Verifique o JSON antes de enviar
            $jsonPayload = json_encode($data, JSON_PRETTY_PRINT);
            if ($jsonPayload === false) {
                return response()->json(['error' => 'Erro ao gerar JSON: '.json_last_error_msg()], 500);
            }

            // Faz a requisição POST para a API
            $response = $client->request('POST', $url, [
                'headers' => [
                    'accept' => 'application/json',
                    'Authorization' => $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'body' => $jsonPayload,
            ]);

            // Decodifica o corpo da resposta JSON
            $statusCode = $response->getStatusCode();
            $content = $response->getBody()->getContents();

            // Verifica se a requisição foi bem-sucedida
            if ($statusCode === 200) {
                return json_decode($content, true);
            }

            // Retorno em caso de falha
            return response()->json(['error' => 'Falha ao enviar dados para a API'], $statusCode);
        } catch (ClientException $e) {
            // Captura exceções específicas do cliente HTTP
            $response = $e->getResponse();
            $responseBodyAsString = $response->getBody()->getContents();

            return response()->json(['error' => 'Erro ao se comunicar com a API: '.$responseBodyAsString], $response->getStatusCode());
        } catch (\Exception $e) {
            // Tratamento de exceção geral
            return response()->json(['error' => 'Erro ao se comunicar com a API: '.$e->getMessage()], 500);
        }
    }

    public function buscarNFE(Venda $venda)
    {
        // Inicializa o cliente HTTP do Guzzle
        $client = new Client;
        $invoiceId = $venda->venda_id_nfe;
        $companyId = 'd3b5de8a66524a9db1c6a47babfdff6f';

        // URL da API com os parâmetros dinamicamente inseridos
        $url = "https://api.nfse.io/v2/companies/{$companyId}/consumerinvoices/{$invoiceId}";

        try {
            // Faz a requisição GET para a API
            $response = $client->request('GET', $url, [
                'headers' => [
                    'accept' => 'application/json',
                    'Authorization' => 'sCnxUa4YkuQIklw4YFWY9CskMnA26ZQJts4vjAAzYTfqafp9I7e1HWcBDSa8ClLBx3w',
                ],
            ]);

            // Decodifica o corpo da resposta JSON
            $body = $response->getBody();
            $statusCode = $response->getStatusCode();
            $content = $body->getContents();

            // Decodifica a string JSON dentro do campo "content"
            $data = json_decode($content, true);

            // Verifica se a decodificação foi bem-sucedida
            if (json_last_error() === JSON_ERROR_NONE) {
                // Retorna a resposta como JSON
                return response()->json([
                    'statusCode' => $statusCode,
                    'data' => $data,
                ], 200);
            } else {
                // Retorna um erro se a decodificação falhar
                return response()->json([
                    'error' => 'Erro ao decodificar o JSON da resposta: '.json_last_error_msg(),
                ], 500);
            }
        } catch (\Exception $e) {
            // Tratamento de exceção caso algo dê errado
            return response()->json(['error' => 'Erro ao se comunicar com a API: '.$e->getMessage()], 500);
        }
    }

    public function atualizaStatusNFE($venda)
    {
        // Inicializa o cliente HTTP do Guzzle
        $client = new Client;
        $invoiceId = $venda->venda_id_nfe;
        $companyId = 'd3b5de8a66524a9db1c6a47babfdff6f';

        // URL da API com os parâmetros dinamicamente inseridos
        $url = "https://api.nfse.io/v2/companies/{$companyId}/consumerinvoices/{$invoiceId}";

        try {
            // Faz a requisição GET para a API
            $response = $client->request('GET', $url, [
                'headers' => [
                    'accept' => 'application/json',
                    'Authorization' => 'sCnxUa4YkuQIklw4YFWY9CskMnA26ZQJts4vjAAzYTfqafp9I7e1HWcBDSa8ClLBx3w',
                ],
            ]);

            // Decodifica o corpo da resposta JSON
            $body = $response->getBody();
            $content = $body->getContents();
            $statusCode = $response->getStatusCode();

            // Decodifica a string JSON
            $data = json_decode($content, true);

            // Verifica se a decodificação foi bem-sucedida
            if (json_last_error() === JSON_ERROR_NONE) {
                // Verifica se o status é "Issued"
                if (isset($data['data']['status']) && $data['data']['status'] === 'Issued') {
                    // Atualiza o campo venda_status_nfe
                    $venda->venda_status_nfe = 'Issued';
                    $venda->save();
                }

                // Retorna a resposta como JSON
                return response()->json([
                    'statusCode' => $statusCode,
                ], 200);
            } else {
                // Retorna um erro se a decodificação falhar
                return response()->json([
                    'error' => 'Erro ao decodificar o JSON da resposta: '.json_last_error_msg(),
                ], 500);
            }
        } catch (\Exception $e) {
            // Tratamento de exceção caso algo dê errado
            return response()->json(['error' => 'Erro ao se comunicar com a API: '.$e->getMessage()], 500);
        }
    }

    public function imprimirNFE(Venda $venda, string $id_nfe)
    {
        // Inicializa o cliente HTTP do Guzzle
        $client = new Client;
        $invoiceId = $id_nfe;

        $empresa = Empresa::first();
        $companyId = $empresa->empresa_api_nfeio_company_id;
        $apiKey = $empresa->empresa_api_nfeio_apikey;

        // URL da API com os parâmetros dinamicamente inseridos
        $url = "https://api.nfse.io/v2/companies/{$companyId}/consumerinvoices/{$invoiceId}/pdf";

        try {
            // Faz a requisição GET para a API
            $response = $client->request('GET', $url, [
                'headers' => [
                    'accept' => 'application/json',
                    'Authorization' => $apiKey,
                ],
            ]);

            // Decodifica o corpo da resposta JSON
            $body = $response->getBody();
            $statusCode = $response->getStatusCode();
            $content = $body->getContents();

            // Decodifica a string JSON dentro do campo "content"
            $data = json_decode($content, true);

            // Verifica se a requisição foi bem-sucedida
            if ($response->getStatusCode() === 200) {
                // Retorna o conteúdo do PDF (ou salva, dependendo da sua necessidade)
                return view('nfePDF', ['data' => $data]);
            }

            // Retorno em caso de falha
            return response()->json(['error' => 'Falha ao baixar o PDF'], $response->getStatusCode());
        } catch (\Exception $e) {
            // Tratamento de exceção caso algo dê errado
            return response()->json(['error' => 'Erro ao se comunicar com a API: '.$e->getMessage()], 500);
        }
    }

    public function listarNFCE($vendaId)
    {

        // Busca a venda pelo ID e carrega os relacionamentos necessários
        $venda = Venda::with(['cliente', 'itensVenda.produto', 'pagamentos.opcaoPagamento', 'pagamentos.cartao'])->findOrFail($vendaId);
    }
}
