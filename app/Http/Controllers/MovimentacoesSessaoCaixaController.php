<?php

namespace App\Http\Controllers;

use App\Models\MovimentacoesSessaoCaixa;
use App\Http\Requests\StoreMovimentacoesSessaoCaixaRequest;
use App\Http\Requests\UpdateMovimentacoesSessaoCaixaRequest;
use App\Models\SessaoCaixa;
use Request;

class MovimentacoesSessaoCaixaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // Buscar todas as movimentações de saída
        $movimentacoes = MovimentacoesSessaoCaixa::where('mov_tipo', 'SAIDA')->get();
        $sessaoCaixa = SessaoCaixa::where('sessaocaixa_status', 'ABERTA')->first();

        if($sessaoCaixa){
            // Retornar a view com os dados
        return view('app.saida_caixa.index', [
            'movimentacoes' => $movimentacoes,
            'sessaoCaixa' => $sessaoCaixa
        ]);
        }
        return redirect()->route('sessao_caixa')->with('error','Nenhuma sessão de caixa aberta!');
        
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
    public function store(StoreMovimentacoesSessaoCaixaRequest $request)
    {
        // Os dados já estarão validados aqui, graças à MovimentacaoRequest

        MovimentacoesSessaoCaixa::create([
            'mov_sessaocaixa_id' => $request->mov_sessaocaixa_id,
            'mov_venda_id' => $request->mov_venda_id ?? null,
            'mov_descricao' => $request->mov_descricao,
            'mov_tipo' => 'SAIDA', // Definido como SAÍDA
            'mov_valor' => str_replace(',','.',$request->mov_valor),
            'mov_observacoes' => $request->mov_observacoes,
        ]);

        $sessaoCaixa = SessaoCaixa::find($request->mov_sessaocaixa_id);
        $sessaoCaixa->update([
            'sessaocaixa_saldo_final' => $sessaoCaixa->sessaocaixa_saldo_final - str_replace(',','.',$request->mov_valor)
        ]);

        // Redirecionar para a página de listagem com uma mensagem de sucesso
        return redirect()->route('mov_saida')->with('success', 'Movimentação de saída criada com sucesso!');
    }

    /**
     * Display the specified resource.
     */
    public function show(MovimentacoesSessaoCaixa $movimentacoesSessaoCaixa)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(MovimentacoesSessaoCaixa $movimentacoesSessaoCaixa , int $id)
    {
        $movimentacao = MovimentacoesSessaoCaixa::find($id);

        // Retornar a view com os dados
        return view('app.saida_caixa.edit', [
            'movimentacao' => $movimentacao
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateMovimentacoesSessaoCaixaRequest $request, MovimentacoesSessaoCaixa $movimentacoesSessaoCaixa, int $id)
    {
        $movimentacao = MovimentacoesSessaoCaixa::find($id);
        $valorAntigo = $movimentacao->mov_valor;
        $movimentacao->update([
            'mov_sessaocaixa_id' => $request->mov_sessaocaixa_id,
            'mov_venda_id' => $request->mov_venda_id ?? null,
            'mov_descricao' => $request->mov_descricao,
            'mov_valor' => str_replace(',','.',$request->mov_valor),
            'mov_observacoes' => $request->mov_observacoes,
        ]);

        $sessaoCaixa = SessaoCaixa::find($request->mov_sessaocaixa_id);
        $sessaoCaixa->update([
            'sessaocaixa_saldo_final' => ($sessaoCaixa->sessaocaixa_saldo_final + $valorAntigo) - str_replace(',','.',$request->mov_valor)
        ]);

        // Redirecionar para a página de listagem com uma mensagem de sucesso
        return redirect()->route('mov_saida')->with('success', 'Movimentação de saída atualizada com sucesso!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(MovimentacoesSessaoCaixa $movimentacoesSessaoCaixa,int $id)
    {
        // Excluir a movimentação de saída
        $movimentacao = MovimentacoesSessaoCaixa::find($id);
        $valorAntigo = $movimentacao->mov_valor;
        $sessaoCaixa = SessaoCaixa::find($movimentacao->mov_sessaocaixa_id);
        $sessaoCaixa->update([
            'sessaocaixa_saldo_final' => $sessaoCaixa->sessaocaixa_saldo_final + $valorAntigo
        ]);

        $movimentacao->delete();

        // Redirecionar para a página de listagem com uma mensagem de sucesso
        return redirect()->route('mov_saida')->with('success', 'Movimentação de saída excluída com sucesso!');
    }
}
