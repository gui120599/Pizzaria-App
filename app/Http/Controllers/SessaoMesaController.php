<?php

namespace App\Http\Controllers;

use App\Models\SessaoMesa;
use App\Http\Requests\StoreSessaoMesaRequest;
use App\Http\Requests\UpdateSessaoMesaRequest;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Mesa;
use App\Models\OpcoesEntregas;
use App\Models\OpcoesPagamento;
use App\Models\Produto;

class SessaoMesaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(string|int $mesa_id)
    {
        $mesa = Mesa::find($mesa_id);

        switch ($mesa->mesa_status) {
            case 'LIBERADA':
                $clientes = Cliente::all();
                return view('app.sessao_mesa.index', ['clientes' => $clientes, 'mesa' => $mesa]);
                break;
            case 'OCUPADA':
                return redirect()->route('sessaoMesa.pedidoMesa', ['mesa_id' => $mesa->id]);
                break;
            default:
                dd($mesa);
                //return redirect()->route('dashboard');
                break;
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function PedidoMesa(string|int $mesa_id)
    {

        $mesa = Mesa::find($mesa_id);
        $sessaoMesa = SessaoMesa::where('sessao_mesa_mesa_id', $mesa_id)->where('sessao_mesa_status', 'ABERTA')->first();
        $opcoes_pagamento = OpcoesPagamento::all();
        $opcoes_entregas = OpcoesEntregas::all();
        $produtos = Produto::all();
        $categorias = Categoria::all();
        return view(
            'app.sessao_mesa.pedido_mesa',
            [
                'mesa' => $mesa,
                'sessao_mesa' => $sessaoMesa,
                'opcoes_entregas' => $opcoes_entregas,
                'opcoes_pagamento' => $opcoes_pagamento,
                'produtos' => $produtos,
                'categorias' => $categorias
            ]
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function AbrirSessaoMesa(StoreSessaoMesaRequest $request)
    {
        // Cria a nova sessão para a mesa
        $sessaoMesa = SessaoMesa::create($request->all());

        // Obtém o ID da mesa a partir do request
        $mesa_id = $request->input('sessao_mesa_mesa_id');

        // Recupera a mesa do banco de dados
        $mesa = Mesa::findOrFail($mesa_id);

        // Atualiza o status da mesa para "OCUPADA"
        $mesa->mesa_status = 'OCUPADA';
        $mesa->save();

        // Obtém a última sessão criada
        $ultimaSessaoMesa = SessaoMesa::latest()->first();

        // Redireciona para a página de pedidos da mesa
        return redirect()->route('sessaoMesa.pedidoMesa', ['mesa_id' => $mesa_id, 'sessao_mesa' => $ultimaSessaoMesa]);
    }


    /**
     * Display the specified resource.
     */
    public function show(SessaoMesa $sessaoMesa)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(SessaoMesa $sessaoMesa)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateSessaoMesaRequest $request, SessaoMesa $sessaoMesa)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(SessaoMesa $sessaoMesa)
    {
        //
    }
}
