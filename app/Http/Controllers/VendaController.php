<?php

namespace App\Http\Controllers;

use App\Models\Venda;
use App\Http\Requests\StoreVendaRequest;
use App\Http\Requests\UpdateVendaRequest;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Mesa;
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
        $sessaCaixa = SessaoCaixa::where('sessaocaixa_status', 'ABERTA')->first();
        $sessaoMesas = SessaoMesa::where('sessao_mesa_status', 'ABERTA')->get();
        $categorias = Categoria::all();
        $produtos = Produto::all();
        $clientes = Cliente::all();

        $pedidos = Pedido::whereNotIn('pedido_status', ['CANCELADO', 'FINALIZADO'])
            ->with(['item_pedido_pedido_id' => function ($query) {
                $query->where('item_pedido_status', 'INSERIDO');
            }])
            ->get();


        return view('app.venda.index', [
            'sessaoCaixa' => $sessaCaixa,
            'produtos' => $produtos,
            'categorias' => $categorias,
            'sessaoMesas' => $sessaoMesas,
            'pedidos' => $pedidos,
            'clientes' => $clientes
        ]);
        //dd($pedidos);
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
            return response()->json(['success' => 'Venda cancelada com sucesso!'],200);
        } else {
            return response()->json(['error' => 'Venda não encontrada'], 404);
        }
    }
}
