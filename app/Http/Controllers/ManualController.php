<?php

namespace App\Http\Controllers;

use App\Support\CatalogoManuais;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ManualController extends Controller
{
    /**
     * Abre o manual no navegador, ou baixa com ?baixar=1. Só serve arquivos
     * do catálogo (docs/*.pdf), então o nome na URL não alcança outro arquivo.
     */
    public function __invoke(Request $request, string $arquivo): BinaryFileResponse
    {
        $caminho = CatalogoManuais::caminho($arquivo);

        abort_if($caminho === null, 404);

        return $request->boolean('baixar')
            ? response()->download($caminho, $arquivo)
            : response()->file($caminho, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$arquivo.'"',
            ]);
    }
}
