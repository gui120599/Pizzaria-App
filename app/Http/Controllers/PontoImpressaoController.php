<?php

namespace App\Http\Controllers;

use App\Models\Colaborador;
use App\Models\PontoPeriodo;
use App\Models\Repasse;
use App\Services\Ponto\EspelhoPontoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** Recibo do repasse e espelho do período para imprimir / salvar em PDF (envio à contabilidade). */
class PontoImpressaoController extends Controller
{
    public function recibo(Repasse $repasse): View
    {
        Gate::authorize('view', $repasse);

        return view('relatorios.ponto-recibo-repasse', [
            'repasse' => $repasse->load(['colaborador', 'periodo', 'itens', 'lancamento', 'aprovadoPor']),
        ]);
    }

    public function espelho(Request $request, EspelhoPontoService $espelho): View
    {
        $periodo = PontoPeriodo::query()->findOrFail($request->integer('periodo'));

        $colaboradores = Colaborador::query()
            ->doPeriodo($periodo)
            ->when($request->integer('colaborador'), fn ($query, int $id) => $query->whereKey($id))
            ->when($request->string('setor')->isNotEmpty(), fn ($query) => $query->where('setor', $request->string('setor')->toString()))
            ->orderBy('nome')
            ->get()
            ->filter(fn (Colaborador $colaborador): bool => Gate::allows('verEspelho', $colaborador))
            ->values();

        abort_if($colaboradores->isEmpty(), 403);

        return view('relatorios.ponto-espelho', [
            'periodo' => $periodo,
            'grade' => $espelho->grade($periodo, $colaboradores),
        ]);
    }
}
