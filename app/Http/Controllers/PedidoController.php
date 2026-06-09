<?php

namespace App\Http\Controllers;

use App\Models\Pedido;
use App\Http\Requests\UpdatePedidoRequest;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\ItensPedido;
use App\Models\OpcoesEntregas;
use App\Models\OpcoesPagamento;
use App\Models\Produto;
use App\Models\SessaoMesa;
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
        // Ordena as categorias: primeiro as que começam com 'P', depois as demais em ordem alfabética
        $categorias = Categoria::orderByRaw("
            CASE
                WHEN categoria_nome LIKE 'Pi%' THEN 0
                ELSE 1
            END, categoria_nome
        ")->get();

        $top10Ids = Produto::where('produto_destaque_mais_vendidos', true)
            ->where('produto_qtd_vendas', '>', 0)
            ->orderByDesc('produto_qtd_vendas')
            ->limit(10)
            ->pluck('id')
            ->all();

        $promocoes = Produto::where('produto_preco_promocional', '>', 0)
            ->with('categoria')
            ->orderByDesc('produto_qtd_vendas')
            ->get();

        $maisVendidos = Produto::where('produto_qtd_vendas', '>', 0)
            ->where('produto_destaque_mais_vendidos', true)
            ->with('categoria')
            ->orderByDesc('produto_qtd_vendas')
            ->limit(8)
            ->get();

        return view('app.pedido.index', [
            'pedidos' => $pedidos,
            'clientes' => $clientes,
            'opcoes_entregas' => $opcoes_entregas,
            'opcoes_pagamento' => $opcoes_pagamento,
            'produtos' => $produtos,
            'categorias' => $categorias,
            'promocoes' => $promocoes,
            'maisVendidos' => $maisVendidos,
            'top10Ids' => $top10Ids,
        ]);
    }

    /**
     * Lista todos os pedidos
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View
     */
    public function list(Request $request)
    {
        if ($request->input('search')) {
            $pedidos = Pedido::where('id', '=', $request->input('search'))->where('pedido_status', '<>', 'INICIADO')->with('mov_pedido','item_pedido_pedido_id')->orderByDesc('id')->paginate(50);
        } else {
            $pedidos = Pedido::with('mov_pedido','item_pedido_pedido_id')->orderByDesc('id')->where('pedido_status', '<>', 'INICIADO')->paginate(50);
        }
        //return response()->json($pedidos);

        $clientes = Cliente::all();
        $opcoes_pagamento = OpcoesPagamento::all();
        $opcoes_entregas = OpcoesEntregas::all();

        return view('app.pedido.list', [
            'pedidos' => $pedidos,
            'clientes' => $clientes,
            'opcoes_entregas' => $opcoes_entregas,
            'opcoes_pagamento' => $opcoes_pagamento,
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
        $pedido           = Pedido::create(['pedido_status' => 'INICIADO']);
        $clientes         = Cliente::orderBy('cliente_nome')->get();
        $opcoes_entregas  = OpcoesEntregas::orderBy('opcaoentrega_nome')->get();
        $opcoes_pagamento = OpcoesPagamento::orderBy('opcaopag_nome')->get();

        return view('app.pedido.create', compact('pedido', 'clientes', 'opcoes_entregas', 'opcoes_pagamento'));
    }

    public function relatorio()
    {
        return view('app.pedido.relatorio');
    }

    /**
     * Listar Pedidos para Relatório
     */
    public function relatorioLista(Request $request)
    {
        $ano = $request->input('ano');
        $mes = $request->input('mes');
        $pedidos = Pedido::with(['cliente', 'sessaoMesa.mesa', 'garcom', 'entregador', 'opcaoEntrega', 'item_pedido_pedido_id.produto.categoria', 'item_pedido_pedido_id.adicionaisItemPedido.adicional'])
            ->whereYear('pedido_datahora_finalizado', $ano)
            ->whereMonth('pedido_datahora_finalizado', $mes)
            ->where('pedido_status', 'FINALIZADO')
            ->get();

        return view('pedidosMensalPDF', ['pedidosMensais' => $pedidos]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $pedido    = Pedido::findOrFail($request->input('pedido_id'));
        $clienteId = $this->resolverCliente($request);

        $itens         = ItensPedido::where('item_pedido_pedido_id', $pedido->id)
            ->where('item_pedido_status', 'INSERIDO')
            ->get();

        if ($itens->isEmpty()) {
            return back()->with('error', 'Adicione pelo menos um item antes de abrir o pedido.');
        }

        $valorItens    = round($itens->sum('item_pedido_valor'), 2);
        $totalDesconto = round($itens->sum('item_pedido_desconto'), 2);
        $valorFrete    = $this->calcularFrete($request->input('pedido_opcaoentrega_id'), $valorItens - $totalDesconto);

        $pedido->update([
            'pedido_cliente_id'           => $clienteId,
            'pedido_opcaoentrega_id'      => $request->input('pedido_opcaoentrega_id') ?: null,
            'pedido_endereco_entrega'     => $request->input('pedido_endereco_entrega') ?: null,
            'pedido_descricao_pagamento'  => $request->input('pedido_descricao_pagamento') ?: null,
            'pedido_observacao_pagamento' => $request->input('pedido_observacao_pagamento') ?: null,
            'pedido_status'               => 'ABERTO',
            'pedido_datahora_abertura'    => Carbon::now(),
            'pedido_valor_itens'          => $valorItens,
            'pedido_valor_desconto'       => $totalDesconto,
            'pedido_valor_frete'          => $valorFrete,
            'pedido_valor_total'          => round(max(0, $valorItens - $totalDesconto + $valorFrete), 2),
        ]);

        return redirect()->route('pedidos')->with('success', 'Pedido #' . $pedido->id . ' criado com sucesso!');
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

    public function editarPedido(int $id)
    {
        $pedido = Pedido::with([
            'cliente',
            'opcaoEntrega',
            'item_pedido_pedido_id',
        ])->findOrFail($id);

        $clientes         = Cliente::orderBy('cliente_nome')->get();
        $opcoes_entregas  = OpcoesEntregas::orderBy('opcaoentrega_nome')->get();
        $opcoes_pagamento = OpcoesPagamento::orderBy('opcaopag_nome')->get();

        return view('app.pedido.edit', compact('pedido', 'clientes', 'opcoes_entregas', 'opcoes_pagamento'));
    }

    public function salvarEdicaoPedido(Request $request, int $id)
    {
        $pedido    = Pedido::findOrFail($id);
        $clienteId = $this->resolverCliente($request);

        $itens         = ItensPedido::where('item_pedido_pedido_id', $id)
            ->where('item_pedido_status', 'INSERIDO')
            ->get();

        $valorItens    = round($itens->sum('item_pedido_valor'), 2);
        $totalDesconto = round($itens->sum('item_pedido_desconto'), 2);
        $valorFrete    = $this->calcularFrete($request->input('pedido_opcaoentrega_id'), $valorItens - $totalDesconto);

        $pedido->update([
            'pedido_cliente_id'           => $clienteId,
            'pedido_opcaoentrega_id'      => $request->input('pedido_opcaoentrega_id') ?: null,
            'pedido_endereco_entrega'     => $request->input('pedido_endereco_entrega') ?: null,
            'pedido_descricao_pagamento'  => $request->input('pedido_descricao_pagamento') ?: null,
            'pedido_observacao_pagamento' => $request->input('pedido_observacao_pagamento') ?: null,
            'pedido_status'               => $request->input('pedido_status') ?: $pedido->pedido_status,
            'pedido_valor_itens'          => $valorItens,
            'pedido_valor_desconto'       => $totalDesconto,
            'pedido_valor_frete'          => $valorFrete,
            'pedido_valor_total'          => round(max(0, $valorItens - $totalDesconto + $valorFrete), 2),
        ]);

        return redirect()->route('pedidos')->with('success', 'Pedido #' . $id . ' atualizado com sucesso!');
    }

    private function calcularFrete(?string $opcaoEntregaId, float $totalLiquido): float
    {
        if (! $opcaoEntregaId) {
            return 0.0;
        }

        $opcao = OpcoesEntregas::find($opcaoEntregaId);
        if (! $opcao || $opcao->opcaoentrega_valor_frete <= 0) {
            return 0.0;
        }

        if ($opcao->opcaoentrega_min_valor_frete > 0 && $totalLiquido >= $opcao->opcaoentrega_min_valor_frete) {
            return 0.0;
        }

        return (float) $opcao->opcaoentrega_valor_frete;
    }

    private function resolverCliente(Request $request): ?int
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
     * Update the specified resource in storage.
     */
    public function update(UpdatePedidoRequest $request, Pedido $pedido)
    {
        $pedido->update([
            'pedido_opcaoentrega_id' => $request->input('pedido_opcaoentrega_id'),
            'pedido_endereco_entrega' => $request->input('pedido_endereco_entrega')
        ]);

        return redirect()->route('pedidos')->with('success', 'Pedido alterado com sucesso!');
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
        $pedidos = Pedido::with(['cliente', 'sessaoMesa.mesa', 'garcom', 'entregador', 'opcaoEntrega', 'item_pedido_pedido_id.produto.categoria', 'item_pedido_pedido_id.adicionaisItemPedido.adicional'])
            ->where('pedido_status', 'ABERTO')
            ->get();

        // Retorna os pedidos como JSON
        return response()->json($pedidos);
    }


    public function PedidosPreparandoLista()
    {
        // Pega todos os pedidos com status 'aberto' (ajuste o valor do status conforme sua lógica)
        $pedidos = Pedido::with(['cliente', 'sessaoMesa.mesa', 'garcom', 'entregador', 'opcaoEntrega', 'item_pedido_pedido_id.produto.categoria', 'item_pedido_pedido_id.adicionaisItemPedido.adicional'])
            ->where('pedido_status', 'PREPARANDO')
            ->get();

        // Retorna os pedidos como JSON
        return response()->json($pedidos);
    }


    public function PedidosProntoLista()
    {
        // Pega todos os pedidos com status 'aberto' (ajuste o valor do status conforme sua lógica)
        $pedidos = Pedido::with(['cliente', 'sessaoMesa.mesa', 'garcom', 'entregador', 'opcaoEntrega', 'item_pedido_pedido_id.produto.categoria', 'item_pedido_pedido_id.adicionaisItemPedido.adicional'])
            ->where('pedido_status', 'PRONTO')
            ->get();

        // Retorna os pedidos como JSON
        return response()->json($pedidos);
    }


    public function PedidosEmTransporteLista()
    {
        // Pega todos os pedidos com status 'aberto' (ajuste o valor do status conforme sua lógica)
        $pedidos = Pedido::with(['cliente', 'sessaoMesa.mesa', 'garcom', 'entregador', 'opcaoEntrega', 'item_pedido_pedido_id.produto.categoria', 'item_pedido_pedido_id.adicionaisItemPedido.adicional'])
            ->where('pedido_status', 'EM TRANSPORTE')
            ->get();

        // Retorna os pedidos como JSON
        return response()->json($pedidos);
    }


    public function PedidosEntregueLista()
    {
        // Obter a data de início a partir do parâmetro da URL
        $dataInicio = Carbon::now();

        // Criar uma cópia de $dataInicio e adicionar um dia
        $dataFinal = $dataInicio->copy()->addDay()->format('Y-m-d');

        // Definir o horário de 17h do dia inicial
        $DatahoraInicio = $dataInicio->copy()->setTime(07, 0, 0); // 17:00:00 no dia inicial

        // Definir o horário de 03h do dia final
        $DatahoraFinal = Carbon::parse($dataFinal)->setTime(3, 0, 0); // 03:00:00 no dia final

        // Pega todos os pedidos com status 'aberto' (ajuste o valor do status conforme sua lógica)
        $pedidos = Pedido::with(['cliente', 'sessaoMesa.mesa', 'garcom', 'entregador', 'opcaoEntrega', 'item_pedido_pedido_id.produto.categoria', 'item_pedido_pedido_id.adicionaisItemPedido.adicional'])
            ->where('pedido_status', 'ENTREGUE')
            ->whereBetween('pedido_datahora_entrega', [$DatahoraInicio, $DatahoraFinal])
            ->get();

        // Retorna os pedidos como JSON
        return response()->json($pedidos);
    }


    public function AceitarPedido(Request $request)
    {

        $pedido_id = $request->id;
        $pedido = Pedido::find($pedido_id);
        $pedido->update([
            'pedido_status' => "PREPARANDO",
            'pedido_datahora_preparo' => Carbon::now()
        ]);
        return response()->json(['message' => 'Pedido aceito!'], 200);
    }


    public function RejeitarPedido(Request $request)
    {

        $pedido_id = $request->id;
        $pedido = Pedido::find($pedido_id);
        $pedido->update([
            'pedido_status' => "CANCELADO",
            'pedido_datahora_cancelado' => Carbon::now()
        ]);
        return response()->json(['message' => 'Pedido Cancelado!'], 200);
    }

    /**
     * Cancelar pedido
     */
    public function CancelarPedido(Request $request)
    {
        $pedido_id = $request->id;
        $pedido = Pedido::find($pedido_id);

        if (!$pedido) {
            return redirect()->route('pedidos')->with('error', 'Pedido não encontrado!');
        }

        if ($pedido->pedido_sessao_mesa_id != null) {
            $sessaoMesa = SessaoMesa::find($pedido->pedido_sessao_mesa_id);

            if (!$sessaoMesa) {
                return redirect()->route('pedidos')->with('error', 'Sessão de mesa não encontrada!');
            }

            if ($sessaoMesa->sessao_mesa_status != 'ABERTA') {
                return redirect()->route('pedidos')->with(
                    'error',
                    'Pedido não pode ser restaurado, pois a sessão(' . $sessaoMesa->id . ') da ' . $sessaoMesa->mesa->mesa_nome . ' está ' . $sessaoMesa->sessao_mesa_status
                );
            }
        }

        if ($pedido->pedido_venda_id != null) {
            return redirect()->route('pedidos')->with(
                'error',
                'Pedido não pode ser restaurado, pois já está pago! Venda: ' . $pedido->pedido_venda_id
            );
        }

        $pedido->update([
            'pedido_status' => 'CANCELADO',
            'pedido_datahora_cancelado' => Carbon::now(),
        ]);

        return redirect()->route('pedidos')->with('success', 'Pedido ' . $pedido->id . ' cancelado com sucesso!');
    }

    /**
     * Restaura o pedido solicitado
     */
    public function RestaurarPedido(Request $request)
    {
        $pedido_id = $request->id;
        $pedido = Pedido::find($pedido_id);

        if (!$pedido) {
            return redirect()->route('pedidos')->with('error', 'Pedido não encontrado!');
        }

        if ($pedido->pedido_sessao_mesa_id != null) {
            $sessaoMesa = SessaoMesa::find($pedido->pedido_sessao_mesa_id);

            if (!$sessaoMesa) {
                return redirect()->route('pedidos')->with('error', 'Sessão de mesa não encontrada!');
            }

            if ($sessaoMesa->sessao_mesa_status != 'ABERTA') {
                return redirect()->route('pedidos')->with(
                    'error',
                    'Pedido não pode ser restaurado, pois a sessão(' . $sessaoMesa->id . ') da ' . $sessaoMesa->mesa->mesa_nome . ' está ' . $sessaoMesa->sessao_mesa_status
                );
            }
        }

        // Atualizar status com base nas condições
        if ($pedido->pedidodatahora_aberto != null && $pedido->pedido_datahora_preparo == null) {
            return $this->restaurarStatusPedido($pedido, "ABERTO", 'pedido_datahora_abertura');
        }

        if ($pedido->pedido_datahora_preparo != null && $pedido->pedido_datahora_pronto == null) {
            return $this->restaurarStatusPedido($pedido, "PREPARANDO", 'pedido_datahora_preparando');
        }

        if ($pedido->pedido_datahora_pronto != null && $pedido->pedido_datahora_transporte == null) {
            return $this->restaurarStatusPedido($pedido, "PRONTO", 'pedido_datahora_pronto');
        }

        if ($pedido->pedido_datahora_transporte != null && $pedido->pedido_datahora_entregue == null) {
            return $this->restaurarStatusPedido($pedido, "EM TRANSPORTE", 'pedido_datahora_transporte');
        }

        if ($pedido->pedido_datahora_entregue != null) {
            return $this->restaurarStatusPedido($pedido, "ENTREGUE", 'pedido_datahora_entregue');
        }

        return redirect()->route('pedidos')->with('error', 'Erro ao restaurar o pedido!');
    }

    private function restaurarStatusPedido($pedido, $status, $campoDataHora)
    {
        $pedido->update([
            'pedido_status' => $status,
            $campoDataHora => Carbon::now(),
            'pedido_datahora_cancelado' => null,
        ]);

        return redirect()->route('pedidos')->with('success', 'Pedido ' . $pedido->id . ' restaurado com sucesso! STATUS: ' . $status);
    }



    public function AvancarPedidoPronto(Request $request)
    {

        $pedido_id = $request->id;
        $pedido = Pedido::find($pedido_id);
        $pedido->update([
            'pedido_status' => "PRONTO",
            'pedido_datahora_pronto' => Carbon::now()
        ]);
        return response()->json(['message' => 'Pedido Pronto!'], 200);
    }


    public function AvancarPedidoEmTransporte(Request $request)
    {

        $pedido_id = $request->id;
        $pedido = Pedido::find($pedido_id);
        $pedido->update([
            'pedido_status' => "EM TRANSPORTE",
            'pedido_datahora_transporte' => Carbon::now()
        ]);
        return response()->json(['message' => 'Pedido Pronto!'], 200);
    }


    public function AvancarPedidoEntregue(Request $request)
    {

        $pedido_id = $request->id;
        $pedido = Pedido::find($pedido_id);
        if ($pedido->pedido_datahora_finalizado) {
            $pedido->update([
                'pedido_status' => "FINALIZADO",
                'pedido_datahora_entrega' => Carbon::now()
            ]);
        } else {
            $pedido->update([
                'pedido_status' => "ENTREGUE",
                'pedido_datahora_entrega' => Carbon::now()
            ]);
        }

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
     * 
     */
    public function AlterarSessaoMesaPedido()
    {

    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Pedido $pedido)
    {
        //
    }
}
