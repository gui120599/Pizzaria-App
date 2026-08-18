<?php

namespace App\Http\Controllers;

use App\Enums\FormaPagamento;
use App\Http\Requests\StoreSessaoCaixaRequest;
use App\Http\Requests\UpdateSessaoCaixaRequest;
use App\Models\Caixa;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Services\SessaoCaixaService;
use Carbon\Carbon;

class SessaoCaixaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $usuarios = User::all();
        $caixas = Caixa::all();
        $sessao_caixa = SessaoCaixa::orderByDesc('id')->paginate(6);

        return view('app.sessao_caixa.index', ['usuarios' => $usuarios, 'caixas' => $caixas, 'sessao_caixa' => $sessao_caixa]);
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
    public function store(StoreSessaoCaixaRequest $request)
    {
        $this->authorize('open', SessaoCaixa::class);

        // Verifique se já existe uma sessão aberta com o mesmo usuário
        $existingSession = SessaoCaixa::where('sessaocaixa_user_id', $request->input('sessaocaixa_user_id'))
            ->where('sessaocaixa_status', 'ABERTA')
            ->first();

        if ($existingSession) {
            // Se uma sessão aberta já existir, redirecione de volta com uma mensagem de erro
            return redirect()->route('sessao_caixa')->with('error', 'Já existe uma sessão aberta para este usuário.');
        }

        $sessaoCaixaService = app(SessaoCaixaService::class);

        $motivoBloqueio = $sessaoCaixaService->motivoBloqueioAbertura((int) $request->input('sessaocaixa_caixa_id'));

        if ($motivoBloqueio) {
            return redirect()->route('sessao_caixa')->with('error', $motivoBloqueio);
        }

        $saldoInicial = $request->input('sessaocaixa_saldo_inicial') ? str_replace(',', '.', $request->input('sessaocaixa_saldo_inicial')) : '0.00';

        // Caso não exista, crie a nova sessão
        $sessao_caixa = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => $request->input('sessaocaixa_caixa_id'),
            'sessaocaixa_user_id' => $request->input('sessaocaixa_user_id'),
            'sessaocaixa_saldo_inicial' => $saldoInicial,
            'sessaocaixa_saldo_final' => $saldoInicial,
            'sessaocaixa_observacoes' => $request->input('sessaocaixa_observacoes'),
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => Carbon::now(),
        ]);

        // Entra no "esperado" do fechamento (ver FechamentoCaixaService::calcularEsperado)
        // pra bater com o dinheiro contado na conferência final.
        $sessaoCaixaService->registrarMovimentoAbertura($sessao_caixa, [
            FormaPagamento::Dinheiro->value => (float) $saldoInicial,
        ]);

        return redirect()->route('sessao_caixa')->with('success', 'Sessão Aberta com sucesso!');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function finalizar(SessaoCaixa $sessaoCaixa)
    {
        $this->authorize('close', $sessaoCaixa);

        if ($sessaoCaixa) {
            $sessaoCaixa->update([
                'sessaocaixa_status' => 'FECHADA',
                'sessaocaixa_data_hora_fechamento' => Carbon::now(),
            ]);

            return redirect()->route('sessao_caixa')->with('success', 'Sessão Finalizada com sucesso!');
        }

        return redirect()->route('sessao_caixa')->with('error', 'Sessão Caixa não encontrada!');
    }

    /**
     * Display the specified resource.
     */
    public function show(SessaoCaixa $sessaoCaixa)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(SessaoCaixa $sessaoCaixa)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateSessaoCaixaRequest $request, SessaoCaixa $sessaoCaixa)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(SessaoCaixa $sessaoCaixa)
    {
        //
    }

    public function listarVendasSessaoCaixa(SessaoCaixa $sessaoCaixa)
    {
        $sessaoCaixa = SessaoCaixa::findOrFail($sessaoCaixa->id);
        $vendas = $sessaoCaixa->vendas()->where('venda_status', 'FINALIZADA')->orderBy('id', 'desc')->get() ?? collect();
        $vendasIniciadas = $sessaoCaixa->vendas()->where('venda_status', 'INICIADA')->with('cliente')->orderBy('id', 'desc')->get() ?? collect();

        return view('app.sessao_caixa.vendas', compact('vendas', 'vendasIniciadas'));
    }
}
