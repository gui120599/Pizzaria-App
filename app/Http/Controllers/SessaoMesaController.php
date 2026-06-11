<?php

namespace App\Http\Controllers;

use App\Models\MovimentacaoPedido;
use App\Models\SessaoMesa;
use App\Models\SessaoMesaCliente;
use App\Http\Requests\StoreSessaoMesaRequest;
use App\Http\Requests\UpdateSessaoMesaRequest;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\ItensPedido;
use App\Models\Mesa;
use App\Models\OpcoesEntregas;
use App\Models\OpcoesPagamento;
use App\Enums\PedidoOrigemEnum;
use App\Models\Pedido;
use App\Models\Produto;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

class SessaoMesaController extends Controller
{
    /**
     * 
     * Display a listing of the resource.
     * 
     */

    public function index(string|int $mesa_id)
    {
        $mesa = Mesa::find($mesa_id);

        switch ($mesa->mesa_status) {
            case 'LIBERADA':
                $clientes = Cliente::all();
                $sessaoMesas = SessaoMesa::where('sessao_mesa_mesa_id', '=', $mesa_id)
                    ->with([
                        'pedidos' => function ($query) {
                            $query->where('pedido_status', '<>', ['CANCELADO'])
                                ->with([
                                    'item_pedido_pedido_id' => function ($query) {
                                        $query->where('item_pedido_status', 'INSERIDO');
                                    }
                                ]);
                        }
                    ])
                    ->orderByDesc('id')
                    ->paginate(10);
                return view('app.sessao_mesa.index', ['sessaoMesas' => $sessaoMesas, 'clientes' => $clientes, 'mesa' => $mesa]);
                break;
            case 'OCUPADA':
                return redirect()->route('sessaoMesa.pedidosMesa', ['mesa_id' => $mesa->id]);
                break;
            default:
                return redirect()->route('dashboard');
                break;
        }
    }

    /**
     * 
     * Tela que abre quando acessa uma mesa já com sessão aberta
     * 
     */

    public function PedidosMesa(string|int $mesa_id)
    {
        $mesa = Mesa::find($mesa_id);
        $sessaoMesa = SessaoMesa::where('sessao_mesa_mesa_id', $mesa_id)->where('sessao_mesa_status', 'ABERTA')->first();
        if (!$sessaoMesa) {
            return redirect()->route('sessaoMesa', ['mesa_id' => $mesa_id])->with('error', '');
        }
        $sessaoMesaId = $sessaoMesa->id;

        $pedidos = Pedido::with('produtosInseridosPedido', 'sessaoMesa')
        ->where('pedido_sessao_mesa_id', $sessaoMesaId)
        ->whereNotIn('pedido_status', ['INICIADO', 'CANCELADO'])
        ->orderByDesc('id')->get();

        $pedidosRemover = Pedido::with('produtosInseridosPedido', 'sessaoMesa')
        ->where('pedido_sessao_mesa_id', $sessaoMesaId)
        ->whereNotIn('pedido_status', ['INICIADO', 'CANCELADO'])
        ->orderByDesc('id')
        ->paginate(8,['*'],'page_RemoverPedidos');

        $pedidosExistentes = Pedido::whereNotIn('pedido_status', ['INICIADO', 'FINALIZADO', 'CANCELADO'])
            ->where(function ($query) use ($sessaoMesaId) {
                $query->whereNot('pedido_sessao_mesa_id', $sessaoMesaId)
                    ->orWhereNull('pedido_sessao_mesa_id');
            })
            ->orderByDesc('id')
            ->paginate(8,['*'],'page_PedidosExistentes');

        $sessaoMesaClientes = $sessaoMesa->clientes()
            ->with('cliente')
            ->get()
            ->map(fn($c) => [
                'smc_id'     => $c->id,
                'cliente_id' => $c->smc_cliente_id,
                'nome'       => $c->cliente?->cliente_nome ?? 'Cliente',
            ])
            ->toArray();

        return view(
            'app.sessao_mesa.pedidos_mesa',
            [
                'mesa'               => $mesa,
                'sessao_mesa'        => $sessaoMesa,
                'pedidos'            => $pedidos,
                'pedidosExistentes'  => $pedidosExistentes,
                'pedidosRemover'     => $pedidosRemover,
                'sessaoMesaClientes' => $sessaoMesaClientes,
            ]
        );
    }

    public function adicionarClientesSessao(Request $request, SessaoMesa $sessaoMesa)
    {
        $clientesIds   = $request->input('clientes_ids', []);
        $clientesNomes = $request->input('clientes_nomes', []);
        $clientesTels  = $request->input('clientes_tels', []);

        $existentes = $sessaoMesa->clientes()->pluck('smc_cliente_id')->toArray();

        foreach ($clientesIds as $i => $clienteId) {
            if ($clienteId) {
                if (! in_array((int) $clienteId, $existentes)) {
                    SessaoMesaCliente::create([
                        'smc_sessao_mesa_id' => $sessaoMesa->id,
                        'smc_cliente_id'     => $clienteId,
                    ]);
                }
            } elseif (! empty($clientesNomes[$i])) {
                $telefone = preg_replace('/\D/', '', $clientesTels[$i] ?? '');
                $cliente  = $telefone
                    ? Cliente::where('cliente_celular', 'like', "%{$telefone}%")->first()
                    : null;
                if (! $cliente) {
                    $cliente = Cliente::create([
                        'cliente_nome'    => $clientesNomes[$i],
                        'cliente_celular' => $telefone ?: null,
                        'cliente_tipo'    => 'Física',
                    ]);
                }
                if (! in_array($cliente->id, $existentes)) {
                    SessaoMesaCliente::create([
                        'smc_sessao_mesa_id' => $sessaoMesa->id,
                        'smc_cliente_id'     => $cliente->id,
                    ]);
                }
            }
        }

        return redirect()
            ->route('sessaoMesa.pedidosMesa', ['mesa_id' => $sessaoMesa->sessao_mesa_mesa_id])
            ->with('success', 'Clientes adicionados à sessão!');
    }

    public function removerClienteSessao(SessaoMesa $sessaoMesa, SessaoMesaCliente $sessaoMesaCliente)
    {
        if ($sessaoMesaCliente->smc_sessao_mesa_id === $sessaoMesa->id) {
            $sessaoMesaCliente->delete();
        }

        return redirect()
            ->route('sessaoMesa.pedidosMesa', ['mesa_id' => $sessaoMesa->sessao_mesa_mesa_id])
            ->with('success', 'Cliente removido da sessão.');
    }

    /**
     * 
     * Tela de pedido para a sessãoa da mesa selecionada
     * 
     */

    public function PedidoMesa(string|int $mesa_id)
    {
        $mesa       = Mesa::find($mesa_id);
        $sessaoMesa = SessaoMesa::where('sessao_mesa_mesa_id', $mesa_id)->where('sessao_mesa_status', 'ABERTA')->first();

        if (!$sessaoMesa) {
            return redirect()->route('sessaoMesa', ['mesa_id' => $mesa_id])->with('error', 'Sessão não encontrada!');
        }

        $pedido = Pedido::where('pedido_sessao_mesa_id', $sessaoMesa->id)
            ->where('pedido_status', 'INICIADO')
            ->latest()
            ->first()
            ?? Pedido::create([
                'pedido_status'         => 'INICIADO',
                'pedido_origem'         => PedidoOrigemEnum::MESA,
                'pedido_sessao_mesa_id' => $sessaoMesa->id,
            ]);

        $opcoes_pagamento = OpcoesPagamento::orderBy('opcaopag_nome')->get();

        $sessaoMesaClientes = $sessaoMesa->clientes()
            ->with('cliente')
            ->get()
            ->map(fn($c) => [
                'id'   => $c->smc_cliente_id,
                'nome' => $c->cliente?->cliente_nome ?? 'Cliente',
            ])
            ->toArray();

        return view('app.sessao_mesa.pedido_mesa', compact('mesa', 'sessaoMesa', 'pedido', 'opcoes_pagamento', 'sessaoMesaClientes'));
    }

    public function salvarNovoPedidoMesa(Request $request, string|int $mesa_id)
    {
        $pedido = Pedido::findOrFail($request->input('pedido_id'));

        $itens         = ItensPedido::where('item_pedido_pedido_id', $pedido->id)
            ->where('item_pedido_status', 'INSERIDO')
            ->get();

        if ($itens->isEmpty()) {
            return back()->with('error', 'Adicione pelo menos um item antes de abrir o pedido.');
        }

        $valorLiquido  = round($itens->sum('item_pedido_valor'), 2);      // item_pedido_valor já é líquido
        $totalDesconto = round($itens->sum('item_pedido_desconto'), 2);
        $valorItens    = round($valorLiquido + $totalDesconto, 2);        // bruto (antes do desconto), para exibição

        $clienteId = $this->resolverClienteMesa($request);

        $pedido->update([
            'pedido_cliente_id'           => $clienteId,
            'pedido_opcaoentrega_id'      => $request->input('pedido_opcaoentrega_id') ?: 1,
            'pedido_usuario_garcom_id'    => $request->input('pedido_usuario_garcom_id') ?: auth()->id(),
            'pedido_descricao_pagamento'  => $request->input('pedido_descricao_pagamento') ?: null,
            'pedido_observacao_pagamento' => $request->input('pedido_observacao_pagamento') ?: null,
            'pedido_status'               => 'ABERTO',
            'pedido_datahora_abertura'    => Carbon::now(),
            'pedido_valor_itens'          => $valorItens,
            'pedido_valor_desconto'       => $totalDesconto,
            'pedido_valor_total'          => round(max(0, $valorLiquido), 2),
        ]);

        return redirect()->route('sessaoMesa.pedidosMesa', ['mesa_id' => $mesa_id])
            ->with('success', 'Pedido #' . $pedido->id . ' aberto com sucesso!');
    }

    public function editarPedidoMesa(string|int $mesa_id, Pedido $pedido)
    {
        $mesa       = Mesa::find($mesa_id);
        $sessaoMesa = SessaoMesa::where('sessao_mesa_mesa_id', $mesa_id)->where('sessao_mesa_status', 'ABERTA')->first();

        if (! $sessaoMesa) {
            return redirect()->route('sessaoMesa.pedidosMesa', ['mesa_id' => $mesa_id])
                ->with('error', 'Sessão não encontrada!');
        }

        $statusNaoEditaveis = ['INICIADO', 'ENTREGUE', 'FINALIZADO', 'CANCELADO'];
        if (in_array($pedido->pedido_status, $statusNaoEditaveis)) {
            return redirect()->route('sessaoMesa.pedidosMesa', ['mesa_id' => $mesa_id])
                ->with('error', 'Este pedido não pode ser editado.');
        }

        $sessaoMesaClientes = $sessaoMesa->clientes()
            ->with('cliente')
            ->get()
            ->map(fn($c) => [
                'id'   => $c->smc_cliente_id,
                'nome' => $c->cliente?->cliente_nome ?? 'Cliente',
            ])
            ->toArray();

        return view('app.sessao_mesa.pedido_edit', compact('mesa', 'sessaoMesa', 'pedido', 'sessaoMesaClientes'));
    }

    public function salvarEdicaoPedidoMesa(Request $request, string|int $mesa_id, Pedido $pedido)
    {
        $statusNaoEditaveis = ['INICIADO', 'ENTREGUE', 'FINALIZADO', 'CANCELADO'];
        if (in_array($pedido->pedido_status, $statusNaoEditaveis)) {
            return redirect()->route('sessaoMesa.pedidosMesa', ['mesa_id' => $mesa_id])
                ->with('error', 'Este pedido não pode ser editado.');
        }

        $itens         = ItensPedido::where('item_pedido_pedido_id', $pedido->id)
            ->where('item_pedido_status', 'INSERIDO')
            ->get();

        $valorLiquido  = round($itens->sum('item_pedido_valor'), 2);      // item_pedido_valor já é líquido
        $totalDesconto = round($itens->sum('item_pedido_desconto'), 2);
        $valorItens    = round($valorLiquido + $totalDesconto, 2);        // bruto (antes do desconto), para exibição

        $pedido->update([
            'pedido_valor_itens'    => $valorItens,
            'pedido_valor_desconto' => $totalDesconto,
            'pedido_valor_total'    => round(max(0, $valorLiquido), 2),
        ]);

        return redirect()->route('sessaoMesa.pedidosMesa', ['mesa_id' => $mesa_id])
            ->with('success', 'Pedido #' . $pedido->id . ' atualizado com sucesso!');
    }

    private function resolverClienteMesa(Request $request): ?int
    {
        $clienteId = $request->input('pedido_cliente_id') ?: null;
        if ($clienteId) {
            return (int) $clienteId;
        }
        $nome     = trim($request->input('cliente_nome_novo', ''));
        $telefone = preg_replace('/\D/', '', $request->input('cliente_celular_novo', ''));
        if (! $nome) {
            return null;
        }
        $cliente = $telefone
            ? Cliente::where('cliente_celular', 'like', "%{$telefone}%")->first()
            : null;
        if ($cliente) {
            $cliente->update(['cliente_nome' => $nome]);
        } else {
            $cliente = Cliente::create([
                'cliente_nome'    => $nome,
                'cliente_celular' => $telefone ?: null,
                'cliente_tipo'    => 'Física',
            ]);
        }
        return $cliente->id;
    }

    /**
     * Store a newly created resource in storage.
     */
    public function AbrirSessaoMesa(StoreSessaoMesaRequest $request)
    {
        $sessaoMesa = SessaoMesa::create($request->only([
            'sessao_mesa_mesa_id',
            'sessao_mesa_status',
            'sessao_mesa_usuario_id',
            'sessao_mesa_cliente_id',
        ]));

        $mesa_id = $request->input('sessao_mesa_mesa_id');
        $mesa    = Mesa::findOrFail($mesa_id);
        $mesa->mesa_status = 'OCUPADA';
        $mesa->mesa_sessao_atual_id = $sessaoMesa->id;
        $mesa->save();

        // Registrar clientes na sessão
        $clientesIds   = $request->input('clientes_ids', []);
        $clientesNomes = $request->input('clientes_nomes', []);
        $clientesTels  = $request->input('clientes_tels', []);

        foreach ($clientesIds as $i => $clienteId) {
            if ($clienteId) {
                SessaoMesaCliente::create([
                    'smc_sessao_mesa_id' => $sessaoMesa->id,
                    'smc_cliente_id'     => $clienteId,
                ]);
            } elseif (!empty($clientesNomes[$i])) {
                $telefone = preg_replace('/\D/', '', $clientesTels[$i] ?? '');
                $cliente  = $telefone
                    ? Cliente::where('cliente_celular', 'like', "%{$telefone}%")->first()
                    : null;
                if (! $cliente) {
                    $cliente = Cliente::create([
                        'cliente_nome'    => $clientesNomes[$i],
                        'cliente_celular' => $telefone ?: null,
                        'cliente_tipo'    => 'Física',
                    ]);
                }
                SessaoMesaCliente::create([
                    'smc_sessao_mesa_id' => $sessaoMesa->id,
                    'smc_cliente_id'     => $cliente->id,
                ]);
            }
        }

        return redirect()->route('sessaoMesa.pedidosMesa', ['mesa_id' => $mesa_id]);
    }

    /**
     * Fechar sessão da mesa para que enquanto os atuais clientes da sessão estão realizando o pagamento outros clientes já possam
     * Abrir uma nova sessão e realizarem pedidos
     */
    public function FecharSessaoMesa(SessaoMesa $sessaoMesa)
    {
        $pedidos_sessao_mesa = Pedido::where('pedido_sessao_mesa_id', '=', $sessaoMesa->id)
            ->where('pedido_status', '<>', 'CANCELADO');

        if ($pedidos_sessao_mesa->exists()) {
            // Encontrou pedidos ativos: fecha a sessão
            $sessaoMesa->update(['sessao_mesa_status' => 'FECHADA']);
        } else {
            // Não encontrou pedidos ativos: cancela a sessão
            $sessaoMesa->update(['sessao_mesa_status' => 'CANCELADA']);
        }

        // Atualiza a mesa para 'LIBERADA' em ambos os casos.
        // Só limpa a sessão atual da mesa se for esta sessão que a ocupa.
        $mesa = Mesa::find($sessaoMesa->sessao_mesa_mesa_id);
        $dadosMesa = ['mesa_status' => 'LIBERADA'];
        if ((int) $mesa->mesa_sessao_atual_id === (int) $sessaoMesa->id) {
            $dadosMesa['mesa_sessao_atual_id'] = null;
        }
        $mesa->update($dadosMesa);

        // Redireciona para a rota da sessão da mesa
        return redirect()->route('sessaoMesa', ['mesa_id' => $sessaoMesa->sessao_mesa_mesa_id]);
    }


    /**
     * Reabrir a sessão caso o usuário a feche e precise atualizar os itens da mesma
     */
    public function ReabrirSessaoMesa(SessaoMesa $sessaoMesa)
    {
        //Verifica se a mesa não possui uma nova sessão aberta
        $mesa = Mesa::find($sessaoMesa->sessao_mesa_mesa_id);
        if ($mesa) {
            switch ($mesa->mesa_status) {
                case 'OCUPADA':
                    return redirect()->route('dashboard')->with('error', 'Sessão não pode ser reaberta, pois já existe uma nova sessão aberta para a ' . $mesa->mesa_nome);
                    break;

                default:
                    $sessaoMesa->update([
                        'sessao_mesa_status' => 'ABERTA'
                    ]);
                    $mesa->update([
                        'mesa_status'          => 'OCUPADA',
                        'mesa_sessao_atual_id' => $sessaoMesa->id,
                    ]);
                    return redirect()->route('sessaoMesa.pedidoMesa', ['mesa_id' => $mesa->id]);
                    break;
            }
        }
        return redirect()->route('dashboard')->with('error', 'Mesa não encontrada!');

    }

    /**
     * 
     * Função que remove item do pedido da mesa selecionada
     * 
     */
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
     * Display onde o usuario Altera a mesa da sessão, caso o cliente mude de mesa
     */
    public function editAlterarMesaSessaMesa(SessaoMesa $sessaoMesa)
    {
        $mesasDisponiveis = Mesa::where('mesa_status', 'LIBERADA')->get();
        $mesasOcupadas = Mesa::where('mesa_status', 'OCUPADA')->get();
        return view('app.sessao_mesa.altera_mesa', ['mesasDisponiveis' => $mesasDisponiveis, 'mesasOcupadas' => $mesasOcupadas, 'sessaoMesa' => $sessaoMesa]);
        //dd($mesasDisponiveis);
    }

    /**
     * Atualiza a mesa da sessão seelecionada
     */
    public function updateAlterarMesaSessaMesa(SessaoMesa $sessaoMesa, Request $request)
    {
        // Atualiza a mesa da sessão
        $sessaoMesa->update([
            'sessao_mesa_mesa_id' => $request->input('mesa_id_nova')
        ]);

        // Atualiza a mesa antiga para LIBERADA
        $mesaAntiga = Mesa::find($request->input('mesa_id_antiga'));
        if ($mesaAntiga) {
            $dadosMesaAntiga = ['mesa_status' => 'LIBERADA'];
            if ((int) $mesaAntiga->mesa_sessao_atual_id === (int) $sessaoMesa->id) {
                $dadosMesaAntiga['mesa_sessao_atual_id'] = null;
            }
            $mesaAntiga->update($dadosMesaAntiga);
        } else {
            return redirect()->route('sessaoMesa.pedidoMesa', ['mesa_id' => $request->input('mesa_id_antiga')])->with('error', 'Mesa antiga não encontrada!');
        }

        // Atualiza a mesa nova para OCUPADA
        $mesaNova = Mesa::find($request->input('mesa_id_nova'));
        if ($mesaNova) {
            $mesaNova->update([
                'mesa_status'          => 'OCUPADA',
                'mesa_sessao_atual_id' => $sessaoMesa->id,
            ]);
        } else {
            return redirect()->route('sessaoMesa.pedidoMesa', ['mesa_id' => $request->input('mesa_id_antiga')])->with('error', 'Mesa antiga não encontrada!');
        }

        // Redireciona para a nova mesa
        return redirect()->route('sessaoMesa.pedidoMesa', ['mesa_id' => $request->input('mesa_id_nova')])->with('success', 'Mesa alterada!');
    }

    /**
     * Adiciona pedidos selecionados na tela da sessão da Mesa
     */
    public function updateAdicionarPedidosExistentes(SessaoMesa $sessaoMesa, Request $request)
    {
        foreach ($request->input('pedidoExistente') as $pedidoId) {
            $pedido = Pedido::find($pedidoId);

            if ($pedido && !in_array($pedido->pedido_status, ['INICIADO', 'FINALIZADO', 'CANCELADO'])) {
                $pedido->update([
                    'pedido_sessao_mesa_id' => $sessaoMesa->id,
                ]);
            }
        }

        return redirect()
            ->route('sessaoMesa.pedidosMesa', ['mesa_id' => $sessaoMesa->sessao_mesa_mesa_id])
            ->with('success', 'Pedidos incluídos!');
    }

    /**
     * Remove pedidos selecionados na tela da sessão da Mesa
     */
    public function updateRemoverPedidosSessaoMesa(SessaoMesa $sessaoMesa, Request $request)
    {
        foreach ($request->input('pedidoExistente') as $pedidoId) {
            $pedido = Pedido::find($pedidoId);

            if ($pedido && !in_array($pedido->pedido_status, ['INICIADO', 'FINALIZADO', 'CANCELADO'])) {
                $pedido->update([
                    'pedido_sessao_mesa_id' => null,
                ]);
            }
        }

        return redirect()
            ->route('sessaoMesa.pedidosMesa', ['mesa_id' => $sessaoMesa->sessao_mesa_mesa_id])
            ->with('success', 'Pedidos incluídos!');
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
