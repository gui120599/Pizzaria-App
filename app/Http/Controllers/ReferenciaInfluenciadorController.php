<?php

namespace App\Http\Controllers;

use App\Enums\StatusInfluenciadorEnum;
use App\Models\CliqueReferencia;
use App\Models\Influenciador;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Link rastreável /r/{slug}: registra o clique, guarda a referência por 30
 * dias e abre o cardápio com o cupom principal do influenciador. A comissão
 * continua exigindo cupom aplicado; a referência serve só para métricas.
 */
class ReferenciaInfluenciadorController extends Controller
{
    public const COOKIE = 'ref_influenciador';

    private const DIAS_COOKIE = 30;

    public function __invoke(Request $request, string $slug): RedirectResponse
    {
        $influenciador = Influenciador::where('influenciador_slug', $slug)->first();

        // Slug desconhecido, excluído ou influenciador fora do ar: cardápio puro, sem rastrear.
        if (! $influenciador || $influenciador->influenciador_status !== StatusInfluenciadorEnum::ATIVO) {
            return redirect()->route('cardapio');
        }

        CliqueReferencia::create([
            'clique_influenciador_id' => $influenciador->id,
            'clique_ip_hash' => hash('sha256', (string) $request->ip().'|'.config('app.key')),
            'clique_user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
        ]);

        $cupom = $influenciador->cupomPrincipal;
        $parametros = $cupom?->estaValido() ? ['cupom' => $cupom->cupom_codigo] : [];

        return redirect()
            ->route('cardapio', $parametros)
            ->withCookie(cookie(self::COOKIE, (string) $influenciador->id, self::DIAS_COOKIE * 24 * 60));
    }
}
