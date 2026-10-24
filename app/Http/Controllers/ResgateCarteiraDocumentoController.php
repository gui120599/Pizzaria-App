<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use App\Models\ResgateCarteira;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Recibo simples (PDF) e comprovante privado do resgate: só quem vê os resgates abre. */
class ResgateCarteiraDocumentoController extends Controller
{
    public function recibo(ResgateCarteira $resgate)
    {
        Gate::authorize('view', $resgate);

        $resgate->load(['influenciador', 'usuario']);

        return Pdf::loadView('pdf.recibo-resgate-carteira', [
            'resgate' => $resgate,
            'empresa' => Empresa::first(),
        ])->stream("recibo-resgate-{$resgate->id}.pdf");
    }

    public function comprovante(ResgateCarteira $resgate): StreamedResponse
    {
        Gate::authorize('view', $resgate);

        abort_unless($resgate->rc_comprovante_path && Storage::disk('local')->exists($resgate->rc_comprovante_path), 404);

        return Storage::disk('local')->response($resgate->rc_comprovante_path);
    }
}
