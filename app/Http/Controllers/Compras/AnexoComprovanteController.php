<?php

namespace App\Http\Controllers\Compras;

use App\Http\Controllers\Controller;
use App\Models\Compra;
use App\Models\CompraAnexo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Comprovante da compra avulsa (Fase 5): o Claude devolve um link assinado
 * (24 h) que abre esta página no celular para enviar a foto do cupom. A foto
 * é reduzida no navegador antes de subir; o arquivo fica no disco local.
 */
class AnexoComprovanteController extends Controller
{
    public function formulario(Compra $compra): View
    {
        return view('compras.comprovante', [
            'compra' => $compra->load('prestador'),
            'anexos' => $compra->anexos()->count(),
        ]);
    }

    public function enviar(Request $request, Compra $compra): JsonResponse
    {
        $dados = $request->validate([
            'foto' => ['required', 'file', 'image', 'max:'.(int) config('compras.avulsa.anexo_max_kb', 10240)],
        ], [
            'foto.image' => 'Envie uma imagem (foto do cupom ou recibo).',
            'foto.max' => 'A imagem passou do tamanho máximo.',
        ]);

        $arquivo = $dados['foto'];
        $caminho = $arquivo->storeAs(
            "compras_anexos/{$compra->id}",
            Str::uuid().'.'.($arquivo->extension() ?: 'jpg'),
            'local',
        );

        CompraAnexo::query()->create([
            'compra_id' => $compra->id,
            'caminho' => $caminho,
            'mime' => (string) $arquivo->getMimeType(),
            'tamanho' => (int) $arquivo->getSize(),
            'enviado_por' => $compra->compra_user_id,
        ]);

        return response()->json(['mensagem' => 'Comprovante anexado à compra #'.$compra->id.'.']);
    }

    /** Imagem para o painel: só quem pode ver a compra. */
    public function ver(Request $request, CompraAnexo $anexo): StreamedResponse
    {
        abort_unless($request->user()?->can('view', $anexo->compra), 403);
        abort_unless(Storage::disk('local')->exists($anexo->caminho), 404);

        return Storage::disk('local')->response($anexo->caminho, null, ['Content-Type' => $anexo->mime]);
    }
}
