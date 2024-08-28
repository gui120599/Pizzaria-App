<?php

namespace App\Http\Controllers;

use App\Models\Venda;
use App\Http\Requests\StoreVendaRequest;
use App\Http\Requests\UpdateVendaRequest;
use App\Models\CartoesPagamento;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\ItensVenda;
use App\Models\Mesa;
use App\Models\OpcoesPagamento;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\SessaoCaixa;
use App\Models\SessaoMesa;
use Carbon\Carbon;
use GuzzleHttp\Psr7\Query;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use NFe_io;
use Ramsey\Uuid\Type\Decimal;

class VendaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // Obtém a sessão de caixa que está aberta
        $sessaCaixa = SessaoCaixa::where('sessaocaixa_status', 'ABERTA')->first();

        // Obtém todas as sessões de mesa que estão abertas
        $sessaoMesas = SessaoMesa::where('sessao_mesa_status', 'ABERTA')
            ->with([
                'pedidos' => function ($query) {
                    $query->where('pedido_status', 'ENTREGUE')
                        ->with([
                            'item_pedido_pedido_id' => function ($query) {
                                $query->where('item_pedido_status', 'INSERIDO');
                            }
                        ]);
                }
            ])
            ->get();


        // Obtém todas as categorias e produtos
        $categorias = Categoria::all();
        $produtos = Produto::all();

        // Obtém todos os clientes
        $clientes = Cliente::all();

        //Obtém os Tipos de Pagamento
        $opcoesPagamentos = OpcoesPagamento::all();

        //Obtém os Cartões
        $cartoes = CartoesPagamento::all();

        // Obtém todos os pedidos que não estão cancelados ou finalizados
        $pedidos = Pedido::where('pedido_status', 'ENTREGUE')
            ->with([
                'item_pedido_pedido_id' => function ($query) {
                    $query->where('item_pedido_status', 'INSERIDO');
                }
            ])
            ->get();

        // Corrigido para usar where em vez de and, e first em vez de get
        // Isso busca a primeira venda que está aberta e pertence à sessão de caixa aberta
        $venda_aberta = Venda::where('venda_status', 'INICIADA')
            ->where('venda_sessao_caixa_id', $sessaCaixa->id)
            ->first();

        // Verifica se existe uma venda aberta
        /* if ($venda_aberta) {
            // Se houver uma venda aberta, carrega a view de edição com os dados
            return view('app.venda.edit', [
                'sessaoCaixa' => $sessaCaixa,
                'produtos' => $produtos,
                'categorias' => $categorias,
                'sessaoMesas' => $sessaoMesas,
                'pedidos' => $pedidos,
                'clientes' => $clientes,
                'venda_aberta' => $venda_aberta
            ])->with('success','Existe uma venda não finalizada!');
        } else {
            // Se não houver uma venda aberta, carrega a view de índice com os dados
            return view('app.venda.index', [
                'sessaoCaixa' => $sessaCaixa,
                'produtos' => $produtos,
                'categorias' => $categorias,
                'sessaoMesas' => $sessaoMesas,
                'pedidos' => $pedidos,
                'clientes' => $clientes
            ]);
        }*/
        return view('app.venda.index', [
            'sessaoCaixa' => $sessaCaixa,
            'produtos' => $produtos,
            'categorias' => $categorias,
            'sessaoMesas' => $sessaoMesas,
            'pedidos' => $pedidos,
            'clientes' => $clientes,
            'opcoesPagamentos' => $opcoesPagamentos,
            'cartoes' => $cartoes
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function iniciarVenda(Request $request)
    {
        $venda = new Venda();
        $venda->venda_status = 'INICIADA';
        $venda->venda_sessao_caixa_id = $request->input('venda_sessao_caixa_id');
        $venda->venda_datahora_iniciada = Carbon::now();
        $venda->venda_cliente_id = $request->input('venda_cliente_id');
        $venda->save();

        return response()->json(['venda_id' => $venda->id]);
    }

    public function AtualizarValorFrete(Request $request)
    {

        $venda_valor_frete = $request->input('venda_valor_frete');
        $venda_id = $request->input('venda_id');

        $venda = Venda::find($venda_id);

        if (!$venda) {
            return response()->json(['error' => 'Venda não encontrada'], 200);
        }

        $venda->venda_valor_frete = $venda_valor_frete;
        if ($venda->venda_valor_itens == 0) {
            $venda->venda_valor_total = $venda_valor_frete;
        } else {
            $venda->venda_valor_total = $venda->venda_valor_itens + $venda_valor_frete - $venda->venda_valor_desconto;
        }

        $venda->save();

        return response()->json(['success' => 'Valor do frete atualizado!']);
    }


    /**
     * Store a newly created resource in storage.
     */
    public function SalvarVenda(StoreVendaRequest $request)
    {
        // Encontrar a venda com base no ID fornecido
        $venda = Venda::find($request->input('venda_id'));
        $sessaoCaixa = SessaoCaixa::find($request->input('venda_sessao_caixa_id'));

        if ($venda) {
            // Atualizar os dados da venda
            $venda->update([
                'venda_status' => 'FINALIZADA',
                'venda_datahora_finalizada' => Carbon::now()
            ]);
            if ($sessaoCaixa) {
                $novoSaldoFinal = $sessaoCaixa->sessaocaixa_saldo_final + $request->input('venda_valor_total');
                $sessaoCaixa->update([
                    'sessaocaixa_saldo_final' => $novoSaldoFinal,
                ]);
            } else {
                // Lidar com a situação onde a venda não é encontrada
                return redirect()->back()->withErrors(['Sessão Caixa não encontrada.']);
            }
        } else {
            // Lidar com a situação onde a venda não é encontrada
            return redirect()->back()->withErrors(['Venda não encontrada.']);
        }

        // Obtenha todos os parâmetros da requisição
        $parameters = $request->all();

        // Acesse o array id_sessao_mesa
        $idSessaoMesa = $parameters['id_sessao_mesa'] ?? [];
        $idPedido = $parameters['id_pedido'] ?? [];

        // Atualize o status das sessões de mesa se o array estiver presente
        if (is_array($idSessaoMesa) && !empty($idSessaoMesa)) {
            foreach ($idSessaoMesa as $value) {
                // Encontrar e atualizar a sessão de mesa
                $sessaoMesa = SessaoMesa::find($value);
                if ($sessaoMesa) {
                    $sessaoMesa->update([
                        'sessao_mesa_status' => 'FECHADA'
                    ]);

                    // Atualizar o status dos pedidos relacionados à sessão de mesa
                    Pedido::where('pedido_sessao_mesa_id', $value)
                        ->update([
                            'pedido_status' => 'FINALIZADO',
                            'pedido_datahora_finalizado' => Carbon::now()
                        ]);
                }
            }
        }

        // Atualize o status dos pedidos se o array estiver presente
        if (is_array($idPedido) && !empty($idPedido)) {
            foreach ($idPedido as $value) {
                // Encontrar e atualizar o pedido
                $pedido = Pedido::find($value);
                if ($pedido) {
                    $pedido->update([
                        'pedido_status' => 'FINALIZADO',
                        'pedido_datahora_finalizado' => Carbon::now()
                    ]);
                }
            }
        }

        // Redirecionar com uma mensagem de sucesso
        return redirect()->route('dashboard')->with('success', 'Venda efetuada com sucesso!');
    }


    /**
     * Display the specified resource.
     */
    public function ListarVenda(Request $request)
    {
        $venda = Venda::find($request->input('venda_id'));
        if (!$venda) {
            return response()->json(['error' => 'Venda não encontrada!'], 404);
        }
        return response()->json([$venda]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Venda $venda)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateVendaRequest $request, Venda $venda)
    {
        //
    }

    /**
     * Deleta a venda caso o usuario saia da tela com a venda no valor 0,00
     */
    public function cancelarVenda(Request $request)
    {
        $venda_id = $request->input('venda_id');
        $venda = Venda::find($venda_id);

        if ($venda) {
            $venda->venda_status = 'CANCELADA';
            $venda->venda_datahora_cancelada = Carbon::now();
            $venda->save();
            return response()->json(['success' => 'Venda cancelada com sucesso!'], 200);
        } else {
            return response()->json(['error' => 'Venda não encontrada'], 404);
        }
    }

    public function enviarNfe($vendaId)
    {
        // Busca a venda pelo ID e carrega os relacionamentos necessários
        $venda = Venda::with(['cliente', 'itensVenda.produto', 'pagamentos.opcaoPagamento', 'pagamentos.cartao'])->findOrFail($vendaId);

        // Monta o array com os dados da venda baseado no modelo fornecido
        $nfeData = [
            "id" => (string) $venda->id,
            "serie" => 1, // Ajuste conforme necessário
            "number" => $venda->id, // Ajuste conforme necessário
            "operationOn" => $venda->venda_datahora_finalizada,
            "operationNature" => "Venda de mercadoria", // Ajuste conforme necessário
            "operationType" => "Outgoing", // Ajuste conforme necessário
            "destination" => "Internal_Operation", // Ajuste conforme necessário
            /*"printType" => 0,*/
            "purposeType" => "Normal", // Ajuste conforme necessário
            "consumerType" => "FinalConsumer", // Ajuste conforme necessário
            "presenceType" => "None",
            /*"contingencyOn" => null,
            "contingencyJustification" => null, // Ajuste conforme necessário
            "buyer" => $this->montarComprador($venda),*/
            /*"totals" => $this->montarTotais($venda),*/
            /*"transport" => $this->montarTransporte($venda),
            "additionalInformation" => $this->montarInformacoesAdicionais($venda),*/
            "items" => $this->montarItens($venda),
            "payment" => $this->montarPagamentos($venda),
            /*"billing" => $this->montarCobranca($venda),
            "issuer" => [
                "stStateTaxNumber" => null, // Ajuste conforme necessário
            ]*/
        ];

        // Envia o array para a API
        /*$response = $this->enviarParaApi($nfeData);

        return response()->json($response);*/
        return response()->json($nfeData);
    }

    private function montarPagamentos(Venda $venda)
    {
        $pagamentosArray = [];

        foreach ($venda->pagamentos as $pagamento) {
            if (stripos($pagamento->opcaoPagamento->opcaopag_nome, "Cartão") !== false){// O nome da opção de pagamento contém a palavra "cartão"
                $pagamentoDetalhe = [
                    "method" => $pagamento->opcaoPagamento->opcaopag_desc_nfe,  // Nome do método de pagamento
                    "amount" => $pagamento->pg_venda_valor_pagamento,
                    "card" => [
                        "federalTaxNumber" => $pagamento->cartao->cartao_cnpj_credenciadora ?? null,
                        "flag" => $pagamento->cartao->cartao_bandeira ?? null,
                        "authorization" => $pagamento->pg_venda_numero_autorizacao_cartao ?? null,
                        "integrationPaymentType" => $pagamento->pg_venda_tipo_integracao ?? null
                    ]
                ];

                $pagamentosArray[] = [
                    "paymentDetail" => [$pagamentoDetalhe]
                ];
            }  
            /*if (stripos($pagamento->opcaoPagamento->opcaopag_nome, "Pix") !== false){// O nome da opção de pagamento contém a palavra "cartão"
                $pagamentoDetalhe = [
                    "method" => $pagamento->opcaoPagamento->opcaopag_desc_nfe,  // Nome do método de pagamento
                    "amount" => $pagamento->pg_venda_valor_pagamento,
                    "card" => [
                        "federalTaxNumber" => $pagamento->cartao->cartao_cnpj_credenciadora ?? null,
                        "flag" => $pagamento->cartao->cartao_bandeira ?? null,
                        "authorization" => $pagamento->pg_venda_numero_autorizacao_cartao ?? null,
                        "integrationPaymentType" => $pagamento->pg_venda_tipo_integracao ?? null
                    ]
                ];

                $pagamentosArray[] = [
                    "paymentDetail" => [$pagamentoDetalhe]
                ];
            }  */
            else {
                $pagamentoDetalhe = [
                    "method" => $pagamento->opcaoPagamento->opcaopag_desc_nfe,  // Nome do método de pagamento
                    "amount" => $pagamento->pg_venda_valor_pagamento,
                ];

                $pagamentosArray[] = [
                    "paymentDetail" => [$pagamentoDetalhe],
                    "payBack" => $venda->venda_valor_troco
                ];
            }

        }

        return $pagamentosArray;
    }

    private function montarComprador(Venda $venda)
    {
        $cliente = $venda->cliente;
        if ($cliente) {
            return [
                "stateTaxNumberIndicator" => "none", // Ajuste conforme necessário
                "tradeName" => $cliente->nome_fantasia ?? null, // Ajuste conforme necessário
                "taxRegime" => "isento", // Ajuste conforme necessário
                "stateTaxNumber" => $cliente->cliente_inscricao_estadual ?? null, // Ajuste conforme necessário
                "id" => (string) $cliente->id ?? 1,
                "name" => $cliente->cliente_nome ?? null,
                "federalTaxNumber" => (int) $cliente->cliente_cpf_cnpj ?? null,
                "email" => $cliente->cliente_email ?? null,
                "address" => [
                    "phone" => $cliente->cliente_celular ?? null,
                    "state" => $cliente->cliente_estado ?? null,
                    "city" => [
                        "code" => $cliente->codigo_municipio ?? null,
                        "name" => $cliente->cliente_cidade ?? null,
                    ],
                    "district" => $cliente->clinete_bairro ?? null,
                    "additionalInformation" => $cliente->cliente_endereco ?? null,
                    "street" => $cliente->cliente_endereco ?? null,
                    "number" => $cliente->cliente_numero ?? null,
                    "postalCode" => $cliente->cliente_cep ?? null,
                    "country" => "BR" // Ajuste conforme necessário
                ],

                "type" => 2, // Ajuste conforme necessário
            ];
        }
        return null;
    }

    private function montarTotais(Venda $venda)
    {
        return [
            "icms" => [
                "baseTax" => $venda->venda_valor_base_calculo,
                "icmsAmount" => $venda->venda_valor_icms,
                "productAmount" => $venda->venda_valor_itens,
                "freightAmount" => $venda->venda_valor_frete,
                "insuranceAmount" => $venda->venda_valor_seguro,
                "discountAmount" => $venda->venda_valor_desconto,
                "invoiceAmount" => $venda->venda_valor_total,
                "ipiAmount" => 0,
                "pisAmount" => $venda->venda_valor_pis,
                "cofinsAmount" => $venda->venda_valor_cofins,
                // Adicione os demais campos conforme necessário...
            ],
            "issqn" => [
                "totalServiceNotTaxedICMS" => 0, // Ajuste conforme necessário
                // Adicione os demais campos conforme necessário...
            ]
        ];
    }

    private function montarTransporte(Venda $venda)
    {
        // Exemplo de montagem dos dados de transporte
        return [
            "freightModality" => 9,
            "transportGroup" => [
                "stateTaxNumber" => null,
                "transportRetention" => null,
                // Adicione os demais campos conforme necessário...
            ],
            // Adicione os demais campos conforme necessário...
        ];
    }

    private function montarInformacoesAdicionais(Venda $venda)
    {
        return [
            "fisco" => null,
            "taxpayer" => null,
            "xmlAuthorized" => null,
            "effort" => null,
            "order" => null,
            "contract" => null,
            // Adicione os demais campos conforme necessário...
        ];
    }

    private function montarItens(Venda $venda)
    {
        $itensArray = [];

        foreach ($venda->itensVenda as $item) {
            $produto = $item->produto;

            switch ($produto->produto_CSOSN) {
                case '101':
                    $itensArray[] = [
                        "code" => (string) $produto->id,
                        "codeGTIN" => $produto->produto_gtin ?? null,
                        "description" => $produto->categoria->categoria_nome . " " . $produto->produto_descricao,
                        "ncm" => $produto->produto_codigo_NCM ?? null,
                        "cfop" => (int) $produto->produto_CFOP ?? null,
                        "unit" => $produto->produto_unidade_comercial,
                        "quantity" => $item->item_venda_quantidade,
                        "unitAmount" => $item->item_venda_valor_unitario,
                        "totalAmount" => (float) $item->item_venda_valor,
                        "unitTax" => (string) $produto->produto_unidade_comercial,
                        "quantityTax" => $item->item_venda_quantidade_tributavel,
                        "taxUnitAmount" => $item->item_venda_valor_unitario_tributavel,
                        "discountAmount" => $item->item_venda_desconto,
                        "othersAmount" => 0,
                        "totalIndicator" => (boolean) $item->item_venda_valor,
                        "cest" => $produto->produto_codigo_CEST,
                        "tax" => [
                            "totalTax" => $item->item_venda_valor_total_tributos,
                            "icms" => [
                                "origin" => $produto->produto_cod_origem_mercadoria,
                                "baseTaxModality" => "3",
                                "baseTax" => $item->item_venda_valor_base_calculo,
                                "amount" => $item->item_venda_valor_icms,
                                "rate" => $produto->produto_valor_percentual_icms,
                                "csosn" => $produto->produto_CSOSN,
                            ],
                        ],
                    ];
                    break;
                case '102':
                    $itensArray[] = [
                        "code" => (string) $produto->id,
                        "codeGTIN" => $produto->produto_gtin ?? null,
                        "description" => $produto->categoria->categoria_nome . " " . $produto->produto_descricao,
                        "ncm" => $produto->produto_codigo_NCM ?? null,
                        "cfop" => (int) $produto->produto_CFOP ?? null,
                        "unit" => $produto->produto_unidade_comercial,
                        "quantity" => $item->item_venda_quantidade,
                        "unitAmount" => $item->item_venda_valor_unitario,
                        "totalAmount" => (float) $item->item_venda_valor,
                        "unitTax" => (string) $produto->produto_unidade_comercial,
                        "quantityTax" => $item->item_venda_quantidade_tributavel,
                        "taxUnitAmount" => $item->item_venda_valor_unitario_tributavel,
                        "discountAmount" => $item->item_venda_desconto,
                        "othersAmount" => 0,
                        "totalIndicator" => (boolean) $item->item_venda_valor,
                        "cest" => $produto->produto_codigo_CEST,
                        "tax" => [
                            "icms" => [
                                "origin" => $produto->produto_cod_origem_mercadoria,
                                "csosn" => $produto->produto_CSOSN,
                                "baseTax" => 0,
                                "amount" => 0,
                                "rate" => 0,
                            ],
                        ],
                    ];
                    break;
                case '500':
                    $itensArray[] = [
                        "code" => (string) $produto->id,
                        "codeGTIN" => $produto->produto_gtin ?? null,
                        "description" => $produto->categoria->categoria_nome . " " . $produto->produto_descricao,
                        "ncm" => $produto->produto_codigo_NCM ?? null,
                        "cfop" => (int) $produto->produto_CFOP ?? null,
                        "unit" => $produto->produto_unidade_comercial,
                        "quantity" => $item->item_venda_quantidade,
                        "unitAmount" => $item->item_venda_valor_unitario,
                        "totalAmount" => (float) $item->item_venda_valor,
                        "unitTax" => (string) $produto->produto_unidade_comercial,
                        "quantityTax" => $item->item_venda_quantidade_tributavel,
                        "taxUnitAmount" => $item->item_venda_valor_unitario_tributavel,
                        "discountAmount" => $item->item_venda_desconto,
                        "othersAmount" => 0,
                        "totalIndicator" => (boolean) $item->item_venda_valor,
                        "cest" => $produto->produto_codigo_CEST,
                        "tax" => [
                            "icms" => [
                                "origin" => $produto->produto_cod_origem_mercadoria,
                                "csosn" => $produto->produto_CSOSN,
                                "baseTax" => 0,
                                "amount" => 0,
                                "rate" => 0,
                            ],
                        ],
                    ];
                    break;
                default:
                    $itensArray[] = [
                        "code" => (string) $produto->id,
                        "codeGTIN" => $produto->produto_gtin ?? null,
                        "description" => $produto->categoria->categoria_nome . " " . $produto->produto_descricao,
                        "ncm" => $produto->produto_codigo_NCM ?? null,
                        "cfop" => (int) $produto->produto_CFOP ?? null,
                        "unit" => $produto->produto_unidade_comercial,
                        "quantity" => $item->item_venda_quantidade,
                        "unitAmount" => $item->item_venda_valor_unitario,
                        "totalAmount" => (float) $item->item_venda_valor,
                        "unitTax" => (string) $produto->produto_unidade_comercial,
                        "quantityTax" => $item->item_venda_quantidade_tributavel,
                        "taxUnitAmount" => $item->item_venda_valor_unitario_tributavel,
                        "discountAmount" => $item->item_venda_desconto,
                        "othersAmount" => 0,
                        "totalIndicator" => (boolean) $item->item_venda_valor,
                        "cest" => $produto->produto_codigo_CEST,
                        "tax" => [
                            "totalTax" => $item->item_venda_valor_total_tributos,
                            "icms" => [
                                "origin" => $produto->produto_cod_origem_mercadoria,
                                "baseTaxModality" => "3",
                                "baseTax" => $item->item_venda_valor_base_calculo,
                                "amount" => $item->item_venda_valor_icms,
                                "rate" => $produto->produto_valor_percentual_icms,
                                "csosn" => $produto->produto_CSOSN,
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
            "bill" => [
                "number" => null, // Ajuste conforme necessário
                "originalAmount" => $venda->valor_total,
                "discountAmount" => $venda->valor_desconto,
                "netAmount" => $venda->valor_total_liquido,
            ],
            "duplicates" => [
                [
                    "number" => null, // Ajuste conforme necessário
                    "expirationOn" => now()->toIso8601String(),
                    "amount" => (float) $venda->valor_total_liquido,
                ]
            ]
        ];
    }

    private function enviarParaApi2(array $nfeData)
    {

        // Substitua pelo caminho correto do client-php e sua chave da API
        require_once("vendor\nfe\nfe\lib\init.php");
        NFe_io::setApiKey("sCnxUa4YkuQIklw4YFWY9CskMnA26ZQJts4vjAAzYTfqafp9I7e1HWcBDSa8ClLBx3w");

        try {
            $nfe = NFe::create($nfeData);
            return $nfe;
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    function enviarParaApi(array $data)
    {
        $url = 'https://api.nfse.io/v2/companies/d3b5de8a66524a9db1c6a47babfdff6f/consumerinvoices?apikey=sCnxUa4YkuQIklw4YFWY9CskMnA26ZQJts4vjAAzYTfqafp9I7e1HWcBDSa8ClLBx3w';
        // Inicializa uma sessão cURL
        $ch = curl_init($url);

        // Configura as opções do cURL
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Retornar resposta como string
        curl_setopt($ch, CURLOPT_POST, true); // Definir o método HTTP como POST
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data)); // Enviar os dados como JSON

        // Configura os headers, incluindo Content-Type como application/json
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen(json_encode($data))
        ]);

        // Executa a requisição e obtém a resposta
        $response = curl_exec($ch);

        // Verifica se ocorreu algum erro
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
        }

        // Fecha a sessão cURL
        curl_close($ch);

        // Retorna a resposta
        return json_decode($response);
    }

    function listarNFCE($vendaId)
    {

        // Busca a venda pelo ID e carrega os relacionamentos necessários
        $venda = Venda::with(['cliente', 'itensVenda.produto', 'pagamentos.opcaoPagamento', 'pagamentos.cartao'])->findOrFail($vendaId);
    }

}
