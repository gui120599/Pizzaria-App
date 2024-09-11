<?php

namespace App\Http\Controllers;

use App\Models\Pedido;
use App\Http\Requests\StorePedidoRequest;
use App\Http\Requests\UpdatePedidoRequest;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\ItensPedido;
use App\Models\OpcoesEntregas;
use App\Models\OpcoesPagamento;
use App\Models\Produto;
use Carbon\Carbon;
use Illuminate\Http\Request;
use PhpParser\Node\Expr\FuncCall;

class PedidoController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $pedidos = Pedido::all();
        $clientes = Cliente::all();
        $opcoes_pagamento = OpcoesPagamento::all();
        $opcoes_entregas = OpcoesEntregas::all();
        $produtos = Produto::all();
        $categorias = Categoria::all();
        return view('app.pedido.index', [
            'pedidos' => $pedidos,
            'clientes' => $clientes,
            'opcoes_entregas' => $opcoes_entregas,
            'opcoes_pagamento' => $opcoes_pagamento,
            'produtos' => $produtos,
            'categorias' => $categorias
        ]);
    }

    /**
     * Display a listing of the resource.
     */
    public function iniciarPedido(Request $request)
    {
        // Criar um novo pedido
        $pedido = new Pedido();
        $pedido->pedido_status = 'INICIADO'; // Definir o status do pedido como 'INICIADO'
        $pedido->save();

        // Retornar o ID do pedido em formato JSON
        return response()->json(['pedido_id' => $pedido->id]);
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
    public function store(StorePedidoRequest $request)
    {
        dd($request);
    }

    /**
     * Display the specified resource.
     */
    public function show(Pedido $pedido)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Pedido $pedido)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdatePedidoRequest $request, Pedido $pedido)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function PedidosAbertos()
    {
        return view('app.pedido.abertos');
    }


    public function PedidosAbertosLista()
    {
        // Pega todos os pedidos com status 'aberto' (ajuste o valor do status conforme sua lógica)
        $pedidos = Pedido::with(['cliente', 'sessaoMesa.mesa', 'garcom', 'entregador', 'opcaoEntrega', 'produtosInseridosPedido'])
            ->where('pedido_status', 'ABERTO')
            ->get();

        // Retorna os pedidos como JSON
        return response()->json($pedidos);
    }


    public function PedidosPreparandoLista()
    {
        // Pega todos os pedidos com status 'aberto' (ajuste o valor do status conforme sua lógica)
        $pedidos = Pedido::with(['cliente', 'sessaoMesa.mesa', 'garcom', 'entregador', 'opcaoEntrega', 'produtosInseridosPedido'])
            ->where('pedido_status', 'PREPARANDO')
            ->get();

        // Retorna os pedidos como JSON
        return response()->json($pedidos);
    }


    public function PedidosProntoLista()
    {
        // Pega todos os pedidos com status 'aberto' (ajuste o valor do status conforme sua lógica)
        $pedidos = Pedido::with(['cliente', 'sessaoMesa.mesa', 'garcom', 'entregador', 'opcaoEntrega', 'produtosInseridosPedido'])
            ->where('pedido_status', 'PRONTO')
            ->get();

        // Retorna os pedidos como JSON
        return response()->json($pedidos);
    }


    public function PedidosEmTransporteLista()
    {
        // Pega todos os pedidos com status 'aberto' (ajuste o valor do status conforme sua lógica)
        $pedidos = Pedido::with(['cliente', 'sessaoMesa.mesa', 'garcom', 'entregador', 'opcaoEntrega', 'produtosInseridosPedido'])
            ->where('pedido_status', 'EM TRANSPORTE')
            ->get();

        // Retorna os pedidos como JSON
        return response()->json($pedidos);
    }

    public function PedidosEntregueLista()
    {
        // Pega todos os pedidos com status 'aberto' (ajuste o valor do status conforme sua lógica)
        $pedidos = Pedido::with(['cliente', 'sessaoMesa.mesa', 'garcom', 'entregador', 'opcaoEntrega', 'produtosInseridosPedido'])
            ->where('pedido_status', 'ENTREGUE')
            ->get();

        // Retorna os pedidos como JSON
        return response()->json($pedidos);
    }


    public function AceitarPedido(Request $request){
        
        $pedido_id = $request->id;
        $pedido = Pedido::find($pedido_id);
        $pedido->update([
            'pedido_status' => "PREPARANDO",
            'pedido_datahora_preparo' => Carbon::now()
        ]);
        return response()->json(['message' => 'Pedido aceito!'], 200);
    }
    public function RejeitarPedido(Request $request){
        
        $pedido_id = $request->id;
        $pedido = Pedido::find($pedido_id);
        $pedido->update([
            'pedido_status' => "CANCELADO",
            'pedido_datahora_cancelado' => Carbon::now()
        ]);
        return response()->json(['message' => 'Pedido Cancelado!'], 200);
    }

    
    public function AvancarPedidoPronto(Request $request){
        
        $pedido_id = $request->id;
        $pedido = Pedido::find($pedido_id);
        $pedido->update([
            'pedido_status' => "PRONTO",
            'pedido_datahora_pronto' => Carbon::now()
        ]);
        return response()->json(['message' => 'Pedido Pronto!'], 200);
    }

    
    public function AvancarPedidoEmTransporte(Request $request){
        
        $pedido_id = $request->id;
        $pedido = Pedido::find($pedido_id);
        $pedido->update([
            'pedido_status' => "EM TRANSPORTE",
            'pedido_datahora_transporte' => Carbon::now()
        ]);
        return response()->json(['message' => 'Pedido Pronto!'], 200);
    }


    public function AvancarPedidoEntregue(Request $request){
        
        $pedido_id = $request->id;
        $pedido = Pedido::find($pedido_id);
        $pedido->update([
            'pedido_status' => "ENTREGUE",
            'pedido_datahora_entrega' => Carbon::now()
        ]);
        return response()->json(['message' => 'Pedido Pronto!'], 200);
    }

    /**
     * Salvar um pedido após ele ser inciado
     */
    public function SalvarPedido(UpdatePedidoRequest $request, Pedido $pedido, string|int $id)
    {
        $pedido = $pedido->find($id);
        $pedido->update([
            'pedido_cliente_id' => $request->input("pedido_cliente_id"),
            'pedido_sessao_mesa_id' => $request->input("pedido_mesa_id"),
            'pedido_usuario_garcom_id' => $request->input("pedido_usuario_garcom_id"),
            'pedido_opcaoentrega_id' => $request->input("pedido_opcaoentrega_id"),
            'pedido_descricao_pagamento' => $request->input("pedido_descricao_pagamento"),
            'pedido_observacao_pagamento' => $request->input("pedido_observacao_pagamento"),
            'pedido_endereco_entrega' => $request->input("pedido_endereco_entrega"),
            'pedido_valor_itens' => $request->input("pedido_valor_itens") ? str_replace(',', '.', $request->input('pedido_valor_itens')) : '0.00',
            'pedido_valor_desconto' => $request->input("pedido_valor_desconto") ? str_replace(',', '.', $request->input('pedido_valor_desconto')) : '0.00',
            'pedido_valor_total' => $request->input("pedido_valor_total") ? str_replace(',', '.', $request->input('pedido_valor_total')) : '0.00',
            'pedido_status' => "ABERTO",
            'pedido_datahora_abertura' => Carbon::now() // Define a data e hora de abertura do pedido
        ]);

        return redirect()->route('dashboard')->with('success', 'Pedido aberto com sucesso!');
    }
    
    /**
     * Salvar um pedido de uma mesa após ele ser inciado
     */
    public function SalvarPedidoMesa(UpdatePedidoRequest $request, Pedido $pedido, string|int $id)
    {
        $pedido = $pedido->find($id);
        $pedido->update([
            'pedido_sessao_mesa_id' => $request->input("pedido_sessao_mesa_id"),
            'pedido_usuario_garcom_id' => $request->input("pedido_usuario_garcom_id"),
            'pedido_opcaoentrega_id' => 1, // 1 é o codigo de "Comer no Local"
            'pedido_valor_itens' => $request->input("pedido_valor_itens") ? str_replace(',', '.', $request->input('pedido_valor_itens')) : '0.00',
            'pedido_valor_desconto' => $request->input("pedido_valor_desconto") ? str_replace(',', '.', $request->input('pedido_valor_desconto')) : '0.00',
            'pedido_valor_total' => $request->input("pedido_valor_total") ? str_replace(',', '.', $request->input('pedido_valor_total')) : '0.00',
            'pedido_status' => "ABERTO",
            'pedido_datahora_abertura' => Carbon::now() // Define a data e hora de abertura do pedido
        ]);

        return redirect()->route('dashboard')->with('success', 'Pedido aberto com sucesso!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Pedido $pedido)
    {
        //
    }
}
