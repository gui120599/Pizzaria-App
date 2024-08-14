<?php

namespace App\Http\Controllers;

use App\Models\PagamentosVenda;
use App\Http\Requests\StorePagamentosVendaRequest;
use App\Http\Requests\UpdatePagamentosVendaRequest;
use App\Models\OpcoesPagamento;
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
        $valor_pagamento = (float)str_replace(',', '.', $request->input('pg_venda_valor_pagamento') ?? 0);
        $valor_acrescimo = (float)str_replace(',', '.', $request->input('pg_venda_valor_acrescimo') ?? 0);
        $valor_desconto = (float)str_replace(',', '.', $request->input('pg_venda_valor_desconto') ?? 0);

        // Verifica se a taxa deve ser acrescida
        if ($opcao_pagamento->opcaopag_tipo_taxa == 'ACRESCENTAR') {
            // Cria o registro de pagamento com o valor ajustado pela taxa
            PagamentosVenda::create([
                'pg_venda_venda_id' => $request->input('venda_id'),
                'pg_venda_opcaopagamento_id' => $request->input('pg_venda_opcaopagamento_id'),
                'pg_venda_cartao_id' => $request->input('pg_venda_cartao_id') ?? null,
                'pg_venda_numero_autorizacao_cartao' => $request->input('pg_venda_numero_autorizacao_cartao') ?? null,
                'pg_venda_valor_pagamento' => $valor_pagamento,
                'pg_venda_valor_acrescimo' => $valor_acrescimo,
            ]);

            // Atualiza os valores da venda considerando o acréscimo
            $venda->venda_valor_acrescimo += $valor_acrescimo;
            $venda->venda_valor_pago += $valor_pagamento + $valor_acrescimo;
            $venda->venda_valor_total += $valor_acrescimo;

            // Verifica se há troco a ser devolvido
            if ($venda->venda_valor_total < $venda->venda_valor_pago) {
                $venda->venda_valor_troco = $venda->venda_valor_pago - $venda->venda_valor_total;
            }

            // Salva as alterações na venda
            $venda->save();
        } else if ($opcao_pagamento->opcaopag_tipo_taxa == 'DESCONTAR') {
            // Cria o registro de pagamento com o valor ajustado pela taxa
            PagamentosVenda::create([
                'pg_venda_venda_id' => $request->input('venda_id'),
                'pg_venda_opcaopagamento_id' => $request->input('pg_venda_opcaopagamento_id'),
                'pg_venda_cartao_id' => $request->input('pg_venda_cartao_id') ?? null,
                'pg_venda_numero_autorizacao_cartao' => $request->input('pg_venda_numero_autorizacao_cartao') ?? null,
                'pg_venda_valor_pagamento' => $valor_pagamento,
                'pg_venda_valor_desconto' => $valor_desconto,
            ]);

            // Atualiza os valores da venda considerando o desconto
            $venda->venda_valor_desconto += $valor_desconto;
            $venda->venda_valor_pago += $valor_pagamento - $valor_desconto;
            $venda->venda_valor_total -= $valor_desconto;

            // Verifica se há troco a ser devolvido
            if ($venda->venda_valor_total < $venda->venda_valor_pago) {
                $venda->venda_valor_troco = $venda->venda_valor_pago - $venda->venda_valor_total;
            }

            // Salva as alterações na venda
            $venda->save();
        } else {
            // Quando não há taxa a ser acrescentada, apenas cria o pagamento com o valor fornecido
            PagamentosVenda::create([
                'pg_venda_venda_id' => $request->input('venda_id'),
                'pg_venda_opcaopagamento_id' => $request->input('pg_venda_opcaopagamento_id'),
                'pg_venda_cartao_id' => $request->input('pg_venda_cartao_id') ?? null,
                'pg_venda_numero_autorizacao_cartao' => $request->input('pg_venda_numero_autorizacao_cartao') ?? null,
                'pg_venda_valor_pagamento' => $valor_pagamento,
            ]);

            // Atualiza os valores da venda com o valor do pagamento
            $venda->venda_valor_pago += $valor_pagamento;

            // Verifica se há troco a ser devolvido
            if ($venda->venda_valor_total < $venda->venda_valor_pago) {
                $venda->venda_valor_troco = $venda->venda_valor_pago - $venda->venda_valor_total;
            }

            // Salva as alterações na venda
            $venda->save();
        }

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

        if(!$pagamentoVenda){
            return response()->json(['erro','Pagamento não encontrado!'],200);
        }
        $pagamentoVenda->delete();

        // Atualizar valores da venda
        $this->vendaService->atualizarValoresdaVenda($venda->id);

        $pagamentosVenda = PagamentosVenda::with('opcaoPagamento')->where('pg_venda_venda_id', $venda->id)->get();

        return response()->json(['success' => 'Pagamento removido!','pagamentosVenda' => $pagamentosVenda, 'venda' => $venda],200);

    }
 
}
