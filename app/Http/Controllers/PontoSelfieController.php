<?php

namespace App\Http\Controllers;

use App\Models\PontoMarcacao;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Selfie de uma marcação, para o espelho. O disco do ponto é privado: a foto
 * só sai por aqui, para quem pode ver o espelho daquele colaborador (gestor
 * ou o próprio). Depois da retenção (ponto:limpar-selfies) responde 404.
 */
class PontoSelfieController extends Controller
{
    public function __invoke(PontoMarcacao $marcacao): StreamedResponse
    {
        Gate::authorize('verEspelho', $marcacao->colaborador);

        $disco = Storage::disk((string) config('ponto.disco'));

        abort_if(blank($marcacao->foto_caminho) || ! $disco->exists($marcacao->foto_caminho), 404);

        return $disco->response($marcacao->foto_caminho, headers: ['Cache-Control' => 'private, max-age=86400']);
    }
}
