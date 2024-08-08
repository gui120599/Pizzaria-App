<?php

namespace App\Http\Controllers;

use App\Models\PagamentosVenda;
use App\Http\Requests\StorePagamentosVendaRequest;
use App\Http\Requests\UpdatePagamentosVendaRequest;
use App\Models\OpcoesPagamento;
use App\Models\Venda;

class PagamentosVendaController extends Controller
{
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

        // Verifica se a taxa deve ser acrescida
        if ($opcao_pagamento->opcaopag_tipo_taxa == 'ACRESCENTAR') {
            // Converte o valor do pagamento para um número válido e calcula o valor com a taxa
            $valor_pagamento = (float)str_replace(',', '.', $request->input('pg_venda_valor_pagamento'));
            $valor_com_taxa = ($valor_pagamento * (float)$opcao_pagamento->opcaopag_valor_percentual_taxa) / 100;

            // Cria o registro de pagamento com o valor ajustado pela taxa
            PagamentosVenda::create([
                'pg_venda_venda_id' => $request->input('venda_id'),
                'pg_venda_opcaopagamento_id' => $request->input('pg_venda_opcaopagamento_id'),
                'pg_venda_cartao_id' => $request->input('pg_venda_cartao_id') ?? null,
                'pg_venda_numero_autorizacao_cartao' => $request->input('pg_venda_numero_autorizacao_cartao') ?? null,
                'pg_venda_valor_pagamento' => $valor_pagamento + $valor_com_taxa,
            ]);

            // Atualiza os valores da venda considerando o acréscimo
            $venda->venda_valor_acrescimo += $valor_com_taxa;
            $venda->venda_valor_pago += $valor_pagamento + $valor_com_taxa;
            $venda->venda_valor_total += $valor_pagamento + $valor_com_taxa;

            // Verifica se há troco a ser devolvido
            if ($venda->venda_valor_total < $venda->venda_valor_pago) {
                $venda->venda_valor_troco = $venda->venda_valor_pago - $venda->venda_valor_total;
            }

            // Salva as alterações na venda
            $venda->save();
        } else {
            // Quando não há taxa a ser acrescentada, apenas cria o pagamento com o valor fornecido
            $valor_pagamento = (float)str_replace(',', '.', $request->input('pg_venda_valor_pagamento'));

            PagamentosVenda::create([
                'pg_venda_venda_id' => $request->input('venda_id'),
                'pg_venda_opcaopagamento_id' => $request->input('pg_venda_opcaopagamento_id'),
                'pg_venda_cartao_id' => $request->input('pg_venda_cartao_id') ?? null,
                'pg_venda_numero_autorizacao_cartao' => $request->input('pg_venda_numero_autorizacao_cartao') ?? null,
                'pg_venda_valor_pagamento' => $valor_pagamento,
            ]);

            // Atualiza os valores da venda com o valor do pagamento
            $venda->venda_valor_pago += $valor_pagamento;
            $venda->venda_valor_total += $valor_pagamento;

            // Verifica se há troco a ser devolvido
            if ($venda->venda_valor_total < $venda->venda_valor_pago) {
                $venda->venda_valor_troco = $venda->venda_valor_pago - $venda->venda_valor_total;
            }

            // Salva as alterações na venda
            $venda->save();
        }

        // Retorna uma resposta de sucesso
        return response()->json(['success' => 'Pagamento adicionado com sucesso!'], 200);
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
    public function destroy(PagamentosVenda $pagamentosVenda)
    {
        //
    }
}
