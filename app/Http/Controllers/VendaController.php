<?php

namespace App\Http\Controllers;

use App\Exceptions\VendaNaoFinalizavelException;
use App\Http\Requests\StoreVendaRequest;
use App\Http\Requests\UpdateVendaRequest;
use App\Models\CartoesPagamento;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\OpcoesPagamento;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\SessaoCaixa;
use App\Models\SessaoMesa;
use App\Models\Venda;
use App\Services\FinalizacaoVendaService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
                            'item_pedido_pedido_id.cliente',
                            'item_pedido_pedido_id.venda',
                            'item_pedido_pedido_id' => function ($query) {
                                $query->where('item_pedido_status', 'INSERIDO');
                            },
                        ]);
                },
            ])
            ->get();

        // Obtém todas as categorias e produtos
        $categorias = Categoria::all();
        $produtos = Produto::all();

        // Obtém todos os clientes
        $clientes = Cliente::all();

        // Obtém os Tipos de Pagamento
        $opcoesPagamentos = OpcoesPagamento::all();

        // Obtém os Cartões
        $cartoes = CartoesPagamento::all();

        // Obtém todos os pedidos que não estão cancelados ou finalizados ou que não possuem venda vinculada
        $pedidos = Pedido::whereNotIn('pedido_status', ['INICIADO', 'CANCELADO', 'FINALIZADO'])
            ->with([
                'item_pedido_pedido_id.produto.categoria',
                'item_pedido_pedido_id.adicionaisItemPedido.adicional',
                'item_pedido_pedido_id' => function ($query) {
                    $query->where('item_pedido_status', 'INSERIDO');
                },
            ])
            ->whereNull('pedido_venda_id')
            ->orderByDesc('id')
            ->get();

        // dd($sessaoMesas->toArray());
        if ($sessaoCaixa) {
            return view('app.venda.index', [
                'sessaoCaixa' => $sessaoCaixa,
                'produtos' => $produtos,
                'categorias' => $categorias,
                'sessaoMesas' => $sessaoMesas,
                'pedidos' => $pedidos,
                'clientes' => $clientes,
                'opcoesPagamentos' => $opcoesPagamentos,
                'cartoes' => $cartoes,
            ]);
        }

        return redirect()->route('sessao_caixa')->with('error', 'Nenhuma sessão caixa está aberta para o usuário: '.$firstName.'!');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function iniciarVenda(Request $request)
    {
        $venda = new Venda;
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

        if (! $venda) {
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
        $venda = Venda::findOrFail($request->input('venda_id'));

        $clienteAdHoc = null;
        if (
            $request->input('venda_cliente_id') === null &&
            ($request->filled('venda_cliente_cpf') || $request->filled('venda_cliente_cnpj'))
        ) {
            $clienteAdHoc = [
                'cpf' => $request->input('venda_cliente_cpf'),
                'cnpj' => $request->input('venda_cliente_cnpj'),
                'telefone' => $request->input('venda_cliente_telefone'),
                'nome' => $request->input('venda_cliente_nome'),
                'email' => $request->input('venda_cliente_email'),
            ];
        }

        try {
            app(FinalizacaoVendaService::class)->finalizar(
                $venda,
                $clienteAdHoc,
                $request->input('id_sessao_mesa', []),
                $request->input('id_pedido', [])
            );
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->validator)->withInput();
        } catch (VendaNaoFinalizavelException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['erro' => 'Erro ao salvar venda: '.$e->getMessage()]);
        }

        return redirect()->route('sessao_caixa.vendas', ['sessao_caixa' => $request->input('venda_sessao_caixa_id')])
            ->with('success', 'Venda efetuada com sucesso!');
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
            DB::raw('SUM(venda_valor_total) as total_vendas')
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
                'total_vendas' => $vendasBD[$mesFormatado]->total_vendas ?? 0,
            ]);
        }

        return view('vendasMensalPDF', ['vendasMensais' => $vendasMensais]);

        // return response()->json($vendasMensais, 200);
    }

    /**More actions
     * Display the specified resource.
     */
    public function ListarVenda(Request $request)
    {
        $venda = Venda::find($request->input('venda_id'));
        if (! $venda) {
            return response()->json(['error' => 'Venda não encontrada!'], 404);
        }

        return response()->json([$venda]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Venda $venda)
    {
        $venda->load(['cliente', 'sessaoCaixa.caixa', 'sessaoCaixa.user', 'pagamentos.opcaoPagamento']);

        $sessaoCaixa = $venda->sessaoCaixa;

        // Mesmas fontes de itens da tela de criação (paridade de UI)
        $sessaoMesas = SessaoMesa::whereIn('sessao_mesa_status', ['ABERTA', 'FECHADA'])
            ->with([
                'pedidos' => function ($query) {
                    $query->whereNotIn('pedido_status', ['INICIADO', 'CANCELADO', 'FINALIZADO'])
                        ->with([
                            'item_pedido_pedido_id.produto.categoria',
                            'item_pedido_pedido_id.adicionaisItemPedido.adicional',
                            'item_pedido_pedido_id.cliente',
                            'item_pedido_pedido_id.venda',
                            'item_pedido_pedido_id' => function ($query) {
                                $query->where('item_pedido_status', 'INSERIDO');
                            },
                        ]);
                },
            ])
            ->get();

        $pedidos = Pedido::whereNotIn('pedido_status', ['INICIADO', 'CANCELADO', 'FINALIZADO'])
            ->with([
                'item_pedido_pedido_id.produto.categoria',
                'item_pedido_pedido_id.adicionaisItemPedido.adicional',
                'item_pedido_pedido_id' => function ($query) {
                    $query->where('item_pedido_status', 'INSERIDO');
                },
            ])
            ->whereNull('pedido_venda_id')
            ->orderByDesc('id')
            ->get();

        $categorias = Categoria::with('produtos')->get();
        $clientes = Cliente::all();
        $opcoesPagamentos = OpcoesPagamento::all();
        $cartoes = CartoesPagamento::all();

        return view('app.venda.edit', compact(
            'venda', 'sessaoCaixa', 'sessaoMesas', 'pedidos',
            'categorias', 'clientes', 'opcoesPagamentos', 'cartoes'
        ));
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
        $venda = Venda::find($request->input('venda_id'));

        if (! $venda) {
            return response()->json(['error' => 'Venda não encontrada'], 404);
        }

        app(FinalizacaoVendaService::class)->cancelar($venda, $request->input('venda_motivo_cancelamento'));

        return response()->json(['success' => 'Venda cancelada com sucesso!'], 200);
    }

    public function cancelarVendaWeb(Request $request, Venda $venda)
    {
        app(FinalizacaoVendaService::class)->cancelar($venda, $request->input('venda_motivo_cancelamento'));

        return redirect()->back()->with('success', 'Venda #'.$venda->id.' cancelada. Os itens estão livres para nova venda.');
    }
}
