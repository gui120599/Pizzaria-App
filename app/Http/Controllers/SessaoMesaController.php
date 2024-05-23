<?php

namespace App\Http\Controllers;

use App\Models\SessaoMesa;
use App\Http\Requests\StoreSessaoMesaRequest;
use App\Http\Requests\UpdateSessaoMesaRequest;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\ItensPedido;
use App\Models\Mesa;
use App\Models\OpcoesEntregas;
use App\Models\OpcoesPagamento;
use App\Models\Pedido;
use App\Models\Produto;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

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
        $sessaoMesaId = $sessaoMesa->id;
        // Carregar itens de pedido com os pedidos relacionados
        $itensInseridoPedido = ItensPedido::whereHas('pedido', function ($query) use ($sessaoMesaId) {
            $query->where('pedido_sessao_mesa_id', $sessaoMesaId)->where('pedido_status', '<>', 'CANCELADO');
        })->where('item_pedido_status', 'INSERIDO')->with('pedido')->get();

        $pedidos = Pedido::where('pedido_sessao_mesa_id', $sessaoMesaId)->where('pedido_status', '<>', 'CANCELADO')->get();


        return view(
            'app.sessao_mesa.pedido_mesa',
            [
                'mesa' => $mesa,
                'sessao_mesa' => $sessaoMesa,
                'opcoes_entregas' => $opcoes_entregas,
                'opcoes_pagamento' => $opcoes_pagamento,
                'produtos' => $produtos,
                'categorias' => $categorias,
                'itens_inserido_pedido' => $itensInseridoPedido,
                'pedidos' => $pedidos
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

    public function RemoverItemPedidoMesa(string|int $item_pedido_id, $pedido_id)
    {
        // Encontrar o item de pedido pelo ID ou lançar um erro 404 se não encontrado
        $itemPedido = ItensPedido::findOrFail($item_pedido_id);

        // Encontrar o pedido pelo ID ou lançar um erro 404 se não encontrado
        $pedido = Pedido::findOrFail($pedido_id);

        // Obter o valor atual dos itens do pedido
        $pedidoValorItens = $pedido->pedido_valor_itens;

        // Obter o valor total atual do pedido
        $pedidoValorTotal = $pedido->pedido_valor_total;

        // Obter o valor do item do pedido
        $itemPedidoValor = $itemPedido->item_pedido_valor;

        // Obter o ID da sessão da mesa associada ao pedido
        $sessaoMesaId = $pedido->sessaoMesa->mesa->id;

        // Subtrair o valor do item removido do valor total dos itens e do valor total do pedido
        $pedidoValorItens = $pedidoValorItens - $itemPedidoValor;
        $pedidoValorTotal = $pedidoValorTotal - $itemPedidoValor;

        // Atualizar o valor total dos itens e o valor total do pedido
        $pedido->update([
            'pedido_valor_itens' => $pedidoValorItens,
            'pedido_valor_total' => $pedidoValorTotal
        ]);

        // Atualizar o status do item do pedido para 'REMOVIDO' e registrar o usuário que removeu o item
        $itemPedido->update([
            'item_pedido_status' => 'REMOVIDO',
            'item_pedido_usuario_removeu' => Auth::user()->id // Corrigido para armazenar o ID do usuário
        ]);

        // Contar a quantidade de itens restantes no pedido
        $itensRestantes = ItensPedido::where('item_pedido_pedido_id', $pedido_id)
            ->where('item_pedido_status', '!=', 'REMOVIDO')
            ->count();

        // Se houver apenas um item restante, mudar o status do pedido para 'CANCELADO'
        if ($itensRestantes == 0) {
            $pedido->update([
                'pedido_status' => 'CANCELADO',
                'pedido_datahora_cancelado' => Carbon::now(),
            ]);
            // Redirecionar para a rota da sessão da mesa com uma mensagem de PEDIDO CANCELADO
        return redirect()->route('sessaoMesa.pedidoMesa', ['mesa_id' => $sessaoMesaId])->with('success', 'Como era o último item do pedido, o mesmo foi CANCELADO com sucesso!');
        }

        // Redirecionar para a rota da sessão da mesa com uma mensagem de sucesso
        return redirect()->route('sessaoMesa.pedidoMesa', ['mesa_id' => $sessaoMesaId])->with('success', 'Item removido do pedido com sucesso!');
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
