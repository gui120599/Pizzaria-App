<?php

namespace App\Http\Controllers;

use App\Models\OpcoesPagamento;
use App\Http\Requests\StoreOpcoesPagamentoRequest;
use App\Http\Requests\UpdateOpcoesPagamentoRequest;

class OpcoesPagamentoController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $opcoes_pagamento = OpcoesPagamento::all();
        $tipos_taxa = [
            ['id' => 'N/A', 'opcaopag_tipo_taxa' => 'N/A'],
            ['id' => 'DESCONTAR', 'opcaopag_tipo_taxa' => 'DESCONTAR'],
            ['id' => 'ACRESCENTAR', 'opcaopag_tipo_taxa' => 'ACRESCENTAR']
        ];

        return view('app.opcoes_pagamento.index', ['opcoes_pagamento' => $opcoes_pagamento, 'tipos_taxas' => $tipos_taxa]);
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
    public function store(StoreOpcoesPagamentoRequest $request)
    {
        $opcoes_pagamento = OpcoesPagamento::create([
            'opcaopag_nome' => $request->input('opcaopag_nome'),
            'opcaopag_tipo_taxa' => $request->input('opcaopag_tipo_taxa'),
            'opcaopag_valor_percentual_taxa' => $request->input('opcaopag_valor_percentual_taxa') ? str_replace(',', '.', $request->input('opcaopag_valor_percentual_taxa')) : '0.00',
        ]);

        $opcoes_pagamento->save();

        // Redireciona para a página da opcoes_pagamento recém-criada
        return redirect()->route('opcoes_pagamento')->with('success', 'Opção Pagamento criada com sucesso!');
    }

    /**
     * Display the specified resource.
     */
    public function show(OpcoesPagamento $opcoes_pagamento)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(OpcoesPagamento $opcoes_pagamento)
    {
        $tipos_taxa = [
            ['id' => 'N/A', 'opcaopag_tipo_taxa' => 'N/A'],
            ['id' => 'DESCONTAR', 'opcaopag_tipo_taxa' => 'DESCONTAR'],
            ['id' => 'ACRESCENTAR', 'opcaopag_tipo_taxa' => 'ACRESCENTAR']
        ];
        return view('app.opcoes_pagamento.edit', ['opcoes_pagamento' => $opcoes_pagamento, 'tipos_taxas' => $tipos_taxa]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateOpcoesPagamentoRequest $request, OpcoesPagamento $opcoes_pagamento)
    {
        $opcoes_pagamento->update([
            'opcaopag_nome' => $request->input('opcaopag_nome'),
            'opcaopag_tipo_taxa' => $request->input('opcaopag_tipo_taxa'),
            'opcaopag_valor_percentual_taxa' => $request->input('opcaopag_valor_percentual_taxa') ? str_replace(',', '.', $request->input('opcaopag_valor_percentual_taxa')) : '0.00',
        ]);

        // Redireciona para a página da opcoes_pagamento recém-criada
        return redirect()->route('opcoes_pagamento')->with('success', 'Opção de Pagamento atualizada com sucesso!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(OpcoesPagamento $opcoes_pagamento, $id)
    {
        $opcoes_pagamento = OpcoesPagamento::find($id);

        if (!$opcoes_pagamento) {
            return redirect('/OpcoesPagamento')->with('error', 'OpcoesPagamento não encontrada!');
        }
        $opcoes_pagamento->delete();
        return redirect('/OpcoesPagamento')->with('success', 'OpcoesPagamento Inativada com sucesso');
    }

    /**
     * Show form Inactives.
     */
    public function inactive()
    {
        $opcoes_pagamento_inativas = OpcoesPagamento::onlyTrashed()->get();

        return view('app.opcoes_pagamento.inactive', ['opcoes_pagamento_inativadas' => $opcoes_pagamento_inativas]);
    }

    /**
     * Active object.
     */
    public function active(OpcoesPagamento $opcoes_pagamento, $id)
    {
        $opcoes_pagamento = OpcoesPagamento::withTrashed()->find($id);

        if (!$opcoes_pagamento) {
            return redirect('/OpcoesPagamento')->with('error', 'OpcoesPagamento não encontrada!');
        }
        $opcoes_pagamento->restore();
        return redirect('/OpcoesPagamento')->with('success', 'OpcoesPagamento Ativada com sucesso');
    }
}
