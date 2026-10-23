<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMovimentacoesSessaoCaixaRequest;
use App\Http\Requests\UpdateMovimentacoesSessaoCaixaRequest;
use App\Models\MovimentacoesSessaoCaixa;
use App\Models\SessaoCaixa;
use Illuminate\Support\Facades\Auth;

class MovimentacoesSessaoCaixaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = Auth::user();  // Recupera o usuário logado
        $userId = $user->id;   // Acessa o ID do usuário
        $firstName = $user->name_first; // Acessa o campo name_first
        // Obtém a sessão de caixa que está aberta
        $sessaoCaixa = SessaoCaixa::where('sessaocaixa_status', 'ABERTA')->where('sessaocaixa_user_id', $userId)->first();
        // Buscar todas as movimentações de saída
        $movimentacoes = MovimentacoesSessaoCaixa::where('mov_tipo', 'SAIDA')->get();

        if ($sessaoCaixa) {
            // Retornar a view com os dados
            return view('app.saida_caixa.index', [
                'movimentacoes' => $movimentacoes,
                'sessaoCaixa' => $sessaoCaixa,
            ]);
        }

        return redirect()->route('sessao_caixa')->with('error', 'Nenhuma sessão de caixa aberta para o usuário: '.$firstName.'!');

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
        return $this->encaminharParaOFilament();
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
    public function edit(MovimentacoesSessaoCaixa $movimentacoesSessaoCaixa, int $id)
    {
        $movimentacao = MovimentacoesSessaoCaixa::find($id);

        // Retornar a view com os dados
        return view('app.saida_caixa.edit', [
            'movimentacao' => $movimentacao,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateMovimentacoesSessaoCaixaRequest $request, MovimentacoesSessaoCaixa $movimentacoesSessaoCaixa, int $id)
    {
        return $this->encaminharParaOFilament();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(MovimentacoesSessaoCaixa $movimentacoesSessaoCaixa, int $id)
    {
        return $this->encaminharParaOFilament();
    }

    /**
     * Saída de caixa agora é transferência do Caixa PDV para outra conta (ou
     * pagamento de despesa) e só existe no Filament: o fluxo Blade gravava
     * saída sem destino, e o dinheiro sumia do razão financeiro.
     */
    private function encaminharParaOFilament()
    {
        return redirect()->route('mov_saida')->with('error', 'Sangria, suprimento e pagamento de despesa agora são registrados em Sessões de caixa › Registrar saída/suprimento, informando a conta de destino.');
    }
}
