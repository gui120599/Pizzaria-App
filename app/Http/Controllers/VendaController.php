<?php

namespace App\Http\Controllers;

use App\Models\Venda;
use App\Http\Requests\StoreVendaRequest;
use App\Http\Requests\UpdateVendaRequest;
use App\Models\CartoesPagamento;
use App\Models\Categoria;
use App\Models\Cliente;
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
        $sessaoMesas = SessaoMesa::where('sessao_mesa_status', 'ABERTA')->get();

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
        $pedidos = Pedido::whereNotIn('pedido_status', ['CANCELADO', 'FINALIZADO'])
            ->with(['item_pedido_pedido_id' => function ($query) {
                $query->where('item_pedido_status', 'INSERIDO');
            }])
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

    public function AtualizarValorFrete(Request $request){

        $venda_valor_frete = $request->input('venda_valor_frete');
        $venda_id = $request->input('venda_id');

        $venda = Venda::find($venda_id);

        if (!$venda) {
            return response()->json(['error' => 'Venda não encontrada'], 200);
        }

        $venda->venda_valor_frete = $venda_valor_frete;
        if($venda->venda_valor_itens == 0){
            $venda->venda_valor_total = $venda_valor_frete;
        }else{
            $venda->venda_valor_total = $venda->venda_valor_itens + $venda_valor_frete - $venda->venda_valor_desconto;
        }
        
        $venda->save();

        return response()->json(['success' => 'Valor do frete atualizado!']);

    }


    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreVendaRequest $request)
    {
        dd($request);
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
}
