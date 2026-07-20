<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePagamentosVendaRequest;
use App\Http\Requests\UpdatePagamentosVendaRequest;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\Venda;
use App\Services\VendaService;
use Illuminate\Http\Request;

class PagamentosVendaController extends Controller
{
    protected $vendaService;

    public function __construct(VendaService $vendaService)
    {
        $this->vendaService = $vendaService;
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
    public function store(StorePagamentosVendaRequest $request)
    {
        // Busca a venda e a opção de pagamento com base nos IDs fornecidos na requisição
        $venda = Venda::find($request->input('venda_id'));
        $opcao_pagamento = OpcoesPagamento::find($request->input('pg_venda_opcaopagamento_id'));

        // Converte valores monetários e trata valores nulos
        // pg_venda_valor_pagamento representa o valor RECEBIDO (aplicado à venda)
        $valor_recebido = (float) str_replace(',', '.', $request->input('pg_venda_valor_pagamento') ?? 0);
        $valor_acrescimo = (float) str_replace(',', '.', $request->input('pg_venda_valor_acrescimo') ?? 0);
        $valor_desconto = (float) str_replace(',', '.', $request->input('pg_venda_valor_desconto') ?? 0);
        $valor_pago_cliente = (float) str_replace(',', '.', $request->input('pg_venda_valor_pago_pelo_cliente') ?? 0);

        // Se o valor pago pelo cliente não foi informado, assume o recebido (sem troco)
        if ($valor_pago_cliente <= 0) {
            $valor_pago_cliente = $valor_recebido;
        }
        $valor_troco = max(0, round($valor_pago_cliente - $valor_recebido, 2));

        // Taxa só conta no campo correspondente ao tipo configurado na opção
        $tipoTaxa = $opcao_pagamento->opcaopag_tipo_taxa;
        $acrescimo = $tipoTaxa === 'ACRESCENTAR' ? $valor_acrescimo : 0.0;
        $desconto = $tipoTaxa === 'DESCONTAR' ? $valor_desconto : 0.0;

        PagamentosVenda::create([
            'pg_venda_venda_id' => $venda->id,
            'pg_venda_opcaopagamento_id' => $request->input('pg_venda_opcaopagamento_id'),
            'pg_venda_cartao_id' => $request->input('pg_venda_cartao_id') ?: null,
            'pg_venda_numero_autorizacao_cartao' => $request->input('pg_venda_numero_autorizacao_cartao') ?: null,
            'pg_venda_valor_pagamento' => $valor_recebido, // espelha o recebido (compatibilidade)
            'pg_venda_valor_recebido' => $valor_recebido,
            'pg_venda_valor_pago_pelo_cliente' => $valor_pago_cliente,
            'pg_venda_valor_troco' => $valor_troco,
            'pg_venda_valor_acrescimo' => $acrescimo,
            'pg_venda_valor_desconto' => $desconto,
        ]);

        // Atualiza os totais da venda (mesma semântica de taxa de antes; troco agora é por pagamento)
        $venda->venda_valor_acrescimo += $acrescimo;
        $venda->venda_valor_desconto += $desconto;
        $venda->venda_valor_pago += $valor_recebido + $acrescimo - $desconto;
        $venda->venda_valor_total += $acrescimo - $desconto;
        $venda->venda_valor_troco += $valor_troco;
        $venda->save();

        $pagamentosVenda = PagamentosVenda::with('opcaoPagamento')->where('pg_venda_venda_id', $venda->id)->get();

        // Retorna uma resposta de sucesso
        return response()->json(['success' => 'Pagamento adicionado com sucesso!', 'pagamentosVenda' => $pagamentosVenda], 200);
    }

    /**
     * Display the specified resource.
     */
    public function show(PagamentosVenda $pagamentosVenda)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(PagamentosVenda $pagamentosVenda)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdatePagamentosVendaRequest $request, PagamentosVenda $pagamentosVenda)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request)
    {
        $pagamentoVenda = PagamentosVenda::find($request->input('pg_venda_id'));

        $venda = Venda::find($pagamentoVenda->pg_venda_venda_id);

        if (! $pagamentoVenda) {
            return response()->json(['erro', 'Pagamento não encontrado!'], 200);
        }
        $pagamentoVenda->delete();

        // Atualizar valores da venda
        $this->vendaService->atualizarValoresdaVenda($venda->id);

        $pagamentosVenda = PagamentosVenda::with('opcaoPagamento')->where('pg_venda_venda_id', $venda->id)->get();

        return response()->json(['success' => 'Pagamento removido!', 'pagamentosVenda' => $pagamentosVenda, 'venda' => $venda], 200);

    }
}
