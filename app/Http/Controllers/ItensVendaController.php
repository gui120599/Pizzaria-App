<?php

namespace App\Http\Controllers;

use App\Models\ItensPedido;
use App\Models\ItensVenda;
use App\Http\Requests\StoreItensVendaRequest;
use App\Http\Requests\UpdateItensVendaRequest;
use App\Models\Pedido;
use App\Models\SessaoMesa;
use Illuminate\Http\Request;

class ItensVendaController extends Controller
{

    public function adicionarItensSessaoMesa(SessaoMesa $sessaoMesa)
    {
        $pedidos = Pedido::where('pedido_sessao_mesa_id', $sessaoMesa)->get();
        $itens_pedidos = [];

        foreach($pedidos as $pedido){
            $itens_pedidos = ItensPedido::where('item_pedido_pedido_id',$pedido->id)->where('item_pedido_status','INSERIDO')->get();
        }

        var_dump($itens_pedidos);
        
        //return response()->json(['pedidos' => $pedidos],200);
        //return response()->json(['message' => 'Chegou aqui','ITENS'=>$itens_pedidos],200);

    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
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
    public function store(StoreItensVendaRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(ItensVenda $itensVenda)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(ItensVenda $itensVenda)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateItensVendaRequest $request, ItensVenda $itensVenda)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ItensVenda $itensVenda)
    {
        //
    }
}
