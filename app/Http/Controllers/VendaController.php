<?php

namespace App\Http\Controllers;

use App\Models\Venda;
use App\Http\Requests\StoreVendaRequest;
use App\Http\Requests\UpdateVendaRequest;
use App\Models\Categoria;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\SessaoMesa;
use GuzzleHttp\Psr7\Query;
use Illuminate\Support\Facades\DB;

class VendaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $sessaoMesas = SessaoMesa::where('sessao_mesa_status', 'ABERTA')->get();
        $categorias = Categoria::all();
        $produtos = Produto::all();

        $pedidos = Pedido::whereNotIn('pedido_status', ['CANCELADO', 'FINALIZADO'])
            ->with(['item_pedido_pedido_id' => function ($query) {
                $query->where('item_pedido_status', 'INSERIDO');
            }])
            ->get();

        //dd($pedidos);
        return view('app.venda.index', ['produtos' => $produtos, 'categorias' => $categorias, 'sessaoMesas' => $sessaoMesas, 'pedidos' => $pedidos]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreVendaRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Venda $venda)
    {
        //
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
     * Remove the specified resource from storage.
     */
    public function destroy(Venda $venda)
    {
        //
    }
}
