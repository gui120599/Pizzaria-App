<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClienteRequest;
use App\Models\Empresa;
use App\Models\MovimentacoesSessaoCaixa;
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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use NFe_io;
use Ramsey\Uuid\Type\Decimal;
use GuzzleHttp\Client;

class VendaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = Auth::user();  // Recupera o usuário logado
        $userId = $user->id;   // Acessa o ID do usuário
        $firstName = $user->name_first; // Acessa o campo name_first
        // Obtém a sessão de caixa que está aberta
        $sessaoCaixa = SessaoCaixa::where('sessaocaixa_status', 'ABERTA')->where('sessaocaixa_user_id', $userId)->first();

        // Obtém todas as sessões de mesa que não estão finalizadas
        // Obtém todas as sessões de mesa que não estão finalizadas
        $sessaoMesas = SessaoMesa::whereIn('sessao_mesa_status', ['ABERTA', 'FECHADA'])
            ->with([
                'pedidos' => function ($query) {
                    $query->whereNotIn('pedido_status', ['INICIADO', 'CANCELADO', 'FINALIZADO'])
                        ->with([
                            'item_pedido_pedido_id.produto.categoria',
                            'item_pedido_pedido_id.adicionaisItemPedido.adicional',
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

        // Obtém todos os pedidos que não estão cancelados ou finalizados ou que não possuem venda vinculada
        $pedidos = Pedido::whereNotIn('pedido_status', ['INICIADO', 'CANCELADO', 'FINALIZADO'])
            ->with([
                'item_pedido_pedido_id.produto.categoria',
                'item_pedido_pedido_id.adicionaisItemPedido.adicional',
                'item_pedido_pedido_id' => function ($query) {
                    $query->where('item_pedido_status', 'INSERIDO');
                }
            ])
            ->whereNull('pedido_venda_id')
            ->orderByDesc('id')
            ->get();

        //dd($sessaoMesas->toArray());
        if ($sessaoCaixa) {
            return view('app.venda.index', [
                'sessaoCaixa' => $sessaoCaixa,
                'produtos' => $produtos,
                'categorias' => $categorias,
                'sessaoMesas' => $sessaoMesas,
                'pedidos' => $pedidos,
                'clientes' => $clientes,
                'opcoesPagamentos' => $opcoesPagamentos,
                'cartoes' => $cartoes
            ]);
        }

        return redirect()->route('sessao_caixa')->with('error', 'Nenhuma sessão caixa está aberta para o usuário: ' . $firstName . '!');
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
        DB::beginTransaction();

        try {
            $venda = Venda::findOrFail($request->input('venda_id'));
            $sessaoCaixa = SessaoCaixa::findOrFail($request->input('venda_sessao_caixa_id'));

            // =========================
            // TRATAMENTO DO CLIENTE
            // =========================
            if (
                $request->input('venda_cliente_id') === null &&
                (
                    $request->filled('venda_cliente_cpf') ||
                    $request->filled('venda_cliente_cnpj')
                )
            ) {
                // Limpa os dados como no prepareForValidation
                $cpf = $request->input('venda_cliente_cpf') ? str_replace([".", "-", " "], "", $request->input('venda_cliente_cpf')) : null;
                $cnpj = $request->input('venda_cliente_cnpj') ? str_replace([".", "-", "/", " "], "", $request->input('venda_cliente_cnpj')) : null;
                $celular = $request->input('venda_cliente_telefone') ? str_replace(["(", ")", "-", " "], "", $request->input('venda_cliente_telefone')) : null;

                // Tenta buscar cliente existente
                $cliente = null;

                if ($cpf) {
                    $cliente = Cliente::where('cliente_cpf', $cpf)->first();
                } elseif ($cnpj) {
                    $cliente = Cliente::where('cliente_cnpj', $cnpj)->first();
                }

                if (!$cliente) {
                    // Prepara dados
                    $clienteData = [
                        'cliente_nome' => $request->input('venda_cliente_nome') ?? 'Cliente não identificado',
                        'cliente_cpf' => $cpf,
                        'cliente_cnpj' => $cnpj,
                        'cliente_celular' => $celular,
                        'cliente_email' => $request->input('venda_cliente_email'),
                        'cliente_tipo' => $cpf ? 'Física' : 'Jurídica',
                    ];

                    // Valida sem a regra `unique`
                    $rules = (new StoreClienteRequest())->rules();

                    unset($rules['cliente_cpf'], $rules['cliente_cnpj']); // Remove unique do validador

                    $validator = Validator::make($clienteData, $rules);

                    if ($validator->fails()) {
                        return redirect()->back()->withErrors($validator)->withInput();
                    }

                    $cliente = Cliente::create($clienteData);
                }

                // Atualiza a venda com o cliente (existente ou criado)
                $venda->update([
                    'venda_cliente_id' => $cliente->id
                ]);
            }

            // =========================
            // FINALIZAÇÃO DA VENDA
            // =========================
            $venda->update([
                'venda_status' => 'FINALIZADA',
                'venda_datahora_finalizada' => Carbon::now(),
                'venda_cliente_id' => $venda->venda_cliente_id ?? $request->input('venda_cliente_id')
            ]);

            // =========================
            // MOVIMENTAÇÃO CAIXA
            // =========================
            MovimentacoesSessaoCaixa::create([
                'mov_sessaocaixa_id' => $sessaoCaixa->id,
                'mov_venda_id' => $venda->id,
                'mov_descricao' => 'VENDA: ' . $venda->id,
                'mov_tipo' => 'ENTRADA',
                'mov_valor' => $request->input('venda_valor_total'),
            ]);

            $saldoInicial = $sessaoCaixa->sessaocaixa_saldo_inicial;

            $valorTotalVendas = Venda::where('venda_sessao_caixa_id', $sessaoCaixa->id)->where('venda_status', 'FINALIZADA')->sum('venda_valor_total');

            $sessaoCaixa->update([
                'sessaocaixa_saldo_final' => $saldoInicial + $valorTotalVendas
            ]);

            // =========================
            // FINALIZAÇÃO DE MESAS E PEDIDOS
            // =========================
            $this->finalizarSessoesEMesas($request->input('id_sessao_mesa', []), $venda->id);
            $this->finalizarPedidosIndividuais($request->input('id_pedido', []), $venda->id);

            DB::commit();

            return redirect()->route('sessao_caixa.vendas', ['sessao_caixa' => $request->input('venda_sessao_caixa_id')])
                ->with('success', 'Venda efetuada com sucesso!');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->withErrors(['erro' => 'Erro ao salvar venda: ' . $e->getMessage()]);
        }
    }

    private function finalizarSessoesEMesas(array $idSessaoMesa, int $vendaId): void
    {
        foreach ($idSessaoMesa as $sessaoId) {
            $sessaoMesa = SessaoMesa::find($sessaoId);
            if ($sessaoMesa) {
                $sessaoMesa->update(['sessao_mesa_status' => 'FINALIZADA']);
                $mesa = Mesa::find($sessaoMesa->sessao_mesa_mesa_id);
                if ($mesa) {
                    /*// Verifica se existem outras sessões ativas para essa mesa
                    $outrasSessoesAtivas = SessaoMesa::where('sessao_mesa_mesa_id', $mesa->id)
                        ->where('id', '<>', $sessaoMesa->id)
                        ->where('sessao_mesa_status', '!=', 'FINALIZADA')
                        ->exists();

                    if (!$outrasSessoesAtivas) {*/
                    $mesa->update(['mesa_status' => 'LIBERADA']);
                    //}
                }

                $pedidos = Pedido::where('pedido_sessao_mesa_id', $sessaoId)
                    ->where('pedido_status', '<>', 'CANCELADO')
                    ->get();

                foreach ($pedidos as $pedido) {
                    $dados = [
                        'pedido_venda_id' => $vendaId,
                        'pedido_datahora_finalizado' => Carbon::now()
                    ];

                    if (in_array($pedido->pedido_status, ['ENTREGUE'])) {
                        $dados['pedido_status'] = 'FINALIZADO';
                    }

                    $pedido->update($dados);
                }
            }
        }
    }

    private function finalizarPedidosIndividuais(array $idPedido, int $vendaId): void
    {
        foreach ($idPedido as $pedidoId) {
            $pedido = Pedido::where('id', $pedidoId)->where('pedido_status', '<>', 'CANCELADO')->first();
            if ($pedido) {
                $dados = [
                    'pedido_venda_id' => $vendaId,
                    'pedido_datahora_finalizado' => Carbon::now()
                ];

                if (in_array($pedido->pedido_status, ['ENTREGUE', 'EM TRANSPORTE'])) {
                    $dados['pedido_status'] = 'FINALIZADO';
                }

                $pedido->update($dados);
            }
        }
    }


    /**
     * Tela do formulário para buscar as vendas
     */
    public function showVendasMensal()
    {
        // Busca datas com vendas finalizadas
        $datas = Venda::whereNotNull('venda_datahora_finalizada')
            ->selectRaw('YEAR(venda_datahora_finalizada) as ano, MONTH(venda_datahora_finalizada) as mes')
            ->distinct()
            ->orderByDesc('ano')
            ->orderBy('mes')
            ->get();

        // Organiza anos e meses disponíveis
        $anosDisponiveis = $datas->pluck('ano')->unique()->values();
        $mesesDisponiveis = $datas->pluck('mes')->unique()->sort()->values();

        // Envia para a view
        return view('app.venda.list', [
            'anosDisponiveis' => $anosDisponiveis,
            'mesesDisponiveis' => $mesesDisponiveis,
        ]);
    }


    /**
     * Listar vendas mensalmente com filtros opcionais
     * Display a listing of the resource.
     * @param  \Illuminate\Http\Request  $request
     */
    public function listarVendasMensal(Request $request)
    {
        $ano = $request->input('ano', Carbon::now()->year());
        $mesesSelecionados = $request->input('meses', range(1, 12));

        // Normaliza meses
        $mesesSelecionados = is_array($mesesSelecionados)
            ? $mesesSelecionados
            : [$mesesSelecionados];

        // Busca vendas reais
        $vendasBD = Venda::select(
            DB::raw("DATE_FORMAT(venda_datahora_finalizada, '%Y-%m') as mes"),
            DB::raw("SUM(venda_valor_total) as total_vendas")
        )
            ->where('venda_status', 'FINALIZADA')
            ->whereYear('venda_datahora_finalizada', $ano)
            ->whereIn(DB::raw('MONTH(venda_datahora_finalizada)'), $mesesSelecionados)
            ->whereNotNull('venda_id_nfe')
            ->groupBy('mes')
            ->get()
            ->keyBy('mes');

        // Monta todos os meses (inclusive os sem venda)
        $vendasMensais = collect();

        foreach ($mesesSelecionados as $mes) {
            $mesFormatado = sprintf('%d-%02d', $ano, $mes);

            $vendasMensais->push((object) [
                'mes' => $mesFormatado,
                'total_vendas' => $vendasBD[$mesFormatado]->total_vendas ?? 0
            ]);
        }

        return view('vendasMensalPDF', ['vendasMensais' => $vendasMensais]);

        //return response()->json($vendasMensais, 200);
    }



    /**More actions
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
            "payment" => $this->montarPagamentos($venda),
            "serie" => 1, // Ajuste conforme necessário
            "number" => $venda->id, // Ajuste conforme necessário
            "operationOn" => $venda->venda_datahora_finalizada,
            "operationNature" => "Venda de mercadoria", // Ajuste conforme necessário
            "operationType" => "Outgoing", // Ajuste conforme necessário
            "destination" => "Internal_Operation", // Ajuste conforme necessário
            "purposeType" => "Normal", // Ajuste conforme necessário
            "consumerType" => "FinalConsumer", // Ajuste conforme necessário
            "presenceType" => "Presence",
            "buyer" => $this->montarComprador($venda),
            "items" => $this->montarItens($venda),

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
        //return response()->json($nfeData);

        // Envia o array para a API
        $response = $this->enviarParaApi($nfeData);
        //return response()->json([$nfeData,$response]);

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

            return redirect()->route('venda')
                ->with('success', 'NFC-E Enviada com sucesso! Verifique em NOTAS FISCAIS se a mesma foi gerada!');
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
            "id" => (string) $venda->id,
            "payment" => $this->montarPagamentos($venda),
            "serie" => 1, // Ajuste conforme necessário
            "number" => $venda->id, // Ajuste conforme necessário
            "operationOn" => $venda->venda_datahora_finalizada,
            "operationNature" => "Venda de mercadoria", // Ajuste conforme necessário
            "operationType" => "Outgoing", // Ajuste conforme necessário
            "destination" => "Internal_Operation", // Ajuste conforme necessário
            "purposeType" => "Normal", // Ajuste conforme necessário
            "consumerType" => "FinalConsumer", // Ajuste conforme necessário
            "presenceType" => "Presence",
            "buyer" => $this->montarComprador($venda),
            "items" => $this->montarItens($venda),

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
            if (stripos($pagamento->opcaoPagamento->opcaopag_nome, "Cartão") !== false || stripos($pagamento->opcaoPagamento->opcaopag_nome, "Pix") !== false) { // O nome da opção de pagamento contém a palavra "cartão"
                $pagamentoDetalhe[] = [
                    "method" => $pagamento->opcaoPagamento->opcaopag_desc_nfe,  // Nome do método de pagamento
                    "amount" => $pagamento->pg_venda_valor_pagamento,
                    "card" => [
                        "federalTaxNumber" => $pagamento->cartao->cartao_cnpj_credenciadora ?? null,
                        "flag" => $pagamento->cartao->cartao_bandeira ?? null,
                        "authorization" => $pagamento->pg_venda_numero_autorizacao_cartao ?? null,
                        "integrationPaymentType" => $pagamento->pg_venda_tipo_integracao ?? null
                    ]
                ];
            } else {
                $pagamentoDetalhe[] = [
                    "method" => $pagamento->opcaoPagamento->opcaopag_desc_nfe,  // Nome do método de pagamento
                    "amount" => $pagamento->pg_venda_valor_pagamento,
                ];
            }
        }
        $pagamentosArray[] = [
            "paymentDetail" => $pagamentoDetalhe,
            "payback" => $venda->venda_valor_troco
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
                        "stateTaxNumberIndicator" => "NonTaxPayer", // 0 - Nenhum (None) 1 - Contribuinte ICMS - informar a IE do destinatário (TaxPayer) 2 - Contribuinte isento de Inscrição no cadastro de Contribuintes (Exempt) 9 - Não Contribuinte, que pode ou não possuir Inscrição Estadual no Cadastro de Contribuintes do ICMS (NonTaxPayer)
                        "tradeName" => $cliente->cliente_nome ?? null, // Ajuste conforme necessário
                        "taxRegime" => "isento", // Ajuste conforme necessário
                        "stateTaxNumber" => $cliente->cliente_inscricao_estadual ?? null, // Ajuste conforme necessário
                        "id" => (string) $cliente->id ?? null,
                        "name" => $cliente->cliente_nome ?? null,
                        "federalTaxNumber" => (string) $cliente->cliente_cpf ?? null,
                        "email" => $cliente->cliente_email ?? null,
                        "type" => 2, // 0 - Indefinido (Undefined) 2 - Pessoa Física (NaturalPerson) 4 - Pessoa Jurídica (LegalEntity)
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
                        "stateTaxNumberIndicator" => "NonTaxPayer", // 0 - Nenhum (None) 1 - Contribuinte ICMS - informar a IE do destinatário (TaxPayer) 2 - Contribuinte isento de Inscrição no cadastro de Contribuintes (Exempt) 9 - Não Contribuinte, que pode ou não possuir Inscrição Estadual no Cadastro de Contribuintes do ICMS (NonTaxPayer)
                        "tradeName" => $cliente->cliente_nome ?? null, // Ajuste conforme necessário
                        "taxRegime" => "isento", // Ajuste conforme necessário
                        "stateTaxNumber" => $cliente->cliente_inscricao_estadual ?? null, // Ajuste conforme necessário
                        "id" => (string) $cliente->id ?? null,
                        "name" => $cliente->cliente_nome ?? null,
                        "federalTaxNumber" => (string) $cliente->cliente_cnpj ?? null,
                        "email" => $cliente->cliente_email ?? null,
                        "type" => 4, // 0 - Indefinido (Undefined) 2 - Pessoa Física (NaturalPerson) 4 - Pessoa Jurídica (LegalEntity)
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
        // Obtém os dados da empresa
        $empresa = Empresa::first();

        $itensArray = [];

        foreach ($venda->itensVenda as $item) {
            $produto = $item->produto;

            switch ($produto->produto_CSOSN) {
                case '101':
                    $descAdicionais = "";
                    if ($item->adicionaisItemVenda) {
                        foreach ($item->adicionaisItemVenda as $adicional) {
                            $descAdicionais .= " Adic. " . $adicional->adicional->adicional_nome;
                        }
                    } else {
                    }

                    $itensArray[] = [
                        "code" => (string) $produto->id,
                        "codeGTIN" => $produto->produto_gtin ?? null,
                        "description" => $produto->categoria->categoria_nome . " " . $produto->produto_descricao . "" . $descAdicionais,
                        /*"description" => "NOTA FISCAL EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL",*/
                        "ncm" => $produto->produto_codigo_NCM ?? null,
                        "cfop" => (int) $produto->produto_CFOP ?? null,
                        "unit" => $produto->produto_unidade_comercial,
                        "quantity" => $item->item_venda_quantidade,
                        "unitAmount" => $item->item_venda_valor_unitario,
                        "totalAmount" => (float) $item->item_venda_valor,
                        "unitTax" => (string) $produto->produto_unidade_comercial,
                        "quantityTax" => $item->item_venda_quantidade_tributavel,
                        "taxUnitAmount" => $item->item_venda_valor_unitario,
                        "discountAmount" => (float) $item->item_venda_desconto,
                        "othersAmount" => $item->item_venda_valor_adicionais,
                        "totalIndicator" => (bool) $item->item_venda_valor,
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
                    $descAdicionais = "";
                    if ($item->adicionaisItemVenda) {
                        foreach ($item->adicionaisItemVenda as $adicional) {
                            $descAdicionais .= " Adic. " . $adicional->adicional->adicional_nome;
                        }
                    }

                    $itensArray[] = [
                        "code" => (string) $produto->id,
                        "codeGTIN" => $produto->produto_gtin ?? null,
                        "description" => $produto->categoria->categoria_nome . " " . $produto->produto_descricao . "" . $descAdicionais,
                        /*"description" => "NOTA FISCAL EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL",*/
                        "ncm" => $produto->produto_codigo_NCM ?? null,
                        "cfop" => (int) $produto->produto_CFOP ?? null,
                        "unit" => $produto->produto_unidade_comercial,
                        "quantity" => $item->item_venda_quantidade,
                        "unitAmount" => $item->item_venda_valor_unitario,
                        "totalAmount" => (float) $item->item_venda_valor,
                        "unitTax" => (string) $produto->produto_unidade_comercial,
                        "quantityTax" => $item->item_venda_quantidade_tributavel,
                        "taxUnitAmount" => $item->item_venda_valor_unitario,
                        "discountAmount" => $item->item_venda_desconto,
                        "othersAmount" => $item->item_venda_valor_adicionais,
                        "totalIndicator" => (bool) $item->item_venda_valor,
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
                    $descAdicionais = "";
                    if ($item->adicionaisItemVenda) {
                        foreach ($item->adicionaisItemVenda as $adicional) {
                            $descAdicionais = " Adic. " . $adicional->adicional->adicional_nome;
                        }
                    } else {
                    }

                    $itensArray[] = [
                        "code" => (string) $produto->id,
                        "codeGTIN" => $produto->produto_gtin ?? null,
                        "description" => $produto->categoria->categoria_nome . " " . $produto->produto_descricao . "" . $descAdicionais,
                        /*"description" => "NOTA FISCAL EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL",*/
                        "ncm" => $produto->produto_codigo_NCM ?? null,
                        "cfop" => (int) $produto->produto_CFOP ?? null,
                        "unit" => $produto->produto_unidade_comercial,
                        "quantity" => $item->item_venda_quantidade,
                        "unitAmount" => $item->item_venda_valor_unitario,
                        "totalAmount" => (float) $item->item_venda_valor,
                        "unitTax" => (string) $produto->produto_unidade_comercial,
                        "quantityTax" => $item->item_venda_quantidade_tributavel,
                        "taxUnitAmount" => $item->item_venda_valor_unitario,
                        "discountAmount" => $item->item_venda_desconto,
                        "othersAmount" => $item->item_venda_valor_adicionais,
                        "totalIndicator" => (bool) $item->item_venda_valor,
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
                    $descAdicionais = "";
                    if ($item->adicionaisItemVenda) {
                        foreach ($item->adicionaisItemVenda as $adicional) {
                            $descAdicionais = " Adic. " . $adicional->adicional->adicional_nome;
                        }
                    } else {
                    }

                    $itensArray[] = [
                        "code" => (string) $produto->id,
                        "codeGTIN" => $produto->produto_gtin ?? null,
                        "description" => $produto->categoria->categoria_nome . " " . $produto->produto_descricao . "" . $descAdicionais,
                        /*"description" => "NOTA FISCAL EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL",*/
                        "ncm" => $produto->produto_codigo_NCM ?? null,
                        "cfop" => (int) $produto->produto_CFOP ?? null,
                        "unit" => $produto->produto_unidade_comercial,
                        "quantity" => $item->item_venda_quantidade,
                        "unitAmount" => $item->item_venda_valor_unitario,
                        "totalAmount" => (float) $item->item_venda_valor,
                        "unitTax" => (string) $produto->produto_unidade_comercial,
                        "quantityTax" => $item->item_venda_quantidade_tributavel,
                        "taxUnitAmount" => $item->item_venda_valor_unitario,
                        "discountAmount" => $item->item_venda_desconto,
                        "othersAmount" => $item->item_venda_valor_adicionais,
                        "totalIndicator" => (bool) $item->item_venda_valor,
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

    function enviarParaApi2(array $data)
    {
        // Inicializa o cliente HTTP do Guzzle
        $client = new Client();

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
                return response()->json(['error' => 'Erro ao gerar JSON: ' . json_last_error_msg()], 500);
            }

            // Faz a requisição POST para a API
            $response = $client->request('POST', $url, [
                'headers' => [
                    'accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $apiKey, // Adicione 'Bearer ' se necessário
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
            return response()->json(['error' => 'Falha ao enviar dados para a API. Status Code: ' . $statusCode, 'response' => $content], $statusCode);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            // Captura exceções específicas do cliente HTTP
            $response = $e->getResponse();
            $responseBodyAsString = $response ? $response->getBody()->getContents() : 'Sem resposta da API';
            return response()->json(['error' => 'Erro ao se comunicar com a API: ' . $responseBodyAsString, 'status_code' => $response ? $response->getStatusCode() : 'Desconhecido'], $response ? $response->getStatusCode() : 500);
        } catch (\GuzzleHttp\Exception\ServerException $e) {
            // Captura exceções do servidor (5xx)
            $response = $e->getResponse();
            $responseBodyAsString = $response ? $response->getBody()->getContents() : 'Sem resposta da API';
            return response()->json(['error' => 'Erro no servidor da API: ' . $responseBodyAsString, 'status_code' => $response ? $response->getStatusCode() : 'Desconhecido'], $response ? $response->getStatusCode() : 500);
        } catch (\Exception $e) {
            // Tratamento de exceção geral
            return response()->json(['error' => 'Erro inesperado: ' . $e->getMessage()], 500);
        }
    }

    function enviarParaApi(array $data)
    {
        // Inicializa o cliente HTTP do Guzzle
        $client = new Client();

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
                return response()->json(['error' => 'Erro ao gerar JSON: ' . json_last_error_msg()], 500);
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
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            // Captura exceções específicas do cliente HTTP
            $response = $e->getResponse();
            $responseBodyAsString = $response->getBody()->getContents();
            return response()->json(['error' => 'Erro ao se comunicar com a API: ' . $responseBodyAsString], $response->getStatusCode());
        } catch (\Exception $e) {
            // Tratamento de exceção geral
            return response()->json(['error' => 'Erro ao se comunicar com a API: ' . $e->getMessage()], 500);
        }
    }

    function buscarNFE(Venda $venda)
    {
        // Inicializa o cliente HTTP do Guzzle
        $client = new Client();
        $invoiceId = $venda->venda_id_nfe;
        $companyId = "d3b5de8a66524a9db1c6a47babfdff6f";

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
                    'data' => $data
                ], 200);
            } else {
                // Retorna um erro se a decodificação falhar
                return response()->json([
                    'error' => 'Erro ao decodificar o JSON da resposta: ' . json_last_error_msg()
                ], 500);
            }
        } catch (\Exception $e) {
            // Tratamento de exceção caso algo dê errado
            return response()->json(['error' => 'Erro ao se comunicar com a API: ' . $e->getMessage()], 500);
        }
    }

    function atualizaStatusNFE($venda)
    {
        // Inicializa o cliente HTTP do Guzzle
        $client = new Client();
        $invoiceId = $venda->venda_id_nfe;
        $companyId = "d3b5de8a66524a9db1c6a47babfdff6f";

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
                    'error' => 'Erro ao decodificar o JSON da resposta: ' . json_last_error_msg()
                ], 500);
            }
        } catch (\Exception $e) {
            // Tratamento de exceção caso algo dê errado
            return response()->json(['error' => 'Erro ao se comunicar com a API: ' . $e->getMessage()], 500);
        }
    }

    function imprimirNFE(Venda $venda, string $id_nfe)
    {
        // Inicializa o cliente HTTP do Guzzle
        $client = new Client();
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
                return view('nfePDF', ["data" => $data]);
            }

            // Retorno em caso de falha
            return response()->json(['error' => 'Falha ao baixar o PDF'], $response->getStatusCode());
        } catch (\Exception $e) {
            // Tratamento de exceção caso algo dê errado
            return response()->json(['error' => 'Erro ao se comunicar com a API: ' . $e->getMessage()], 500);
        }
    }

    function listarNFCE($vendaId)
    {

        // Busca a venda pelo ID e carrega os relacionamentos necessários
        $venda = Venda::with(['cliente', 'itensVenda.produto', 'pagamentos.opcaoPagamento', 'pagamentos.cartao'])->findOrFail($vendaId);
    }
}
