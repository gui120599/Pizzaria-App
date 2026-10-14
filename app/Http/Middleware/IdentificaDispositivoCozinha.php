<?php

namespace App\Http\Middleware;

use App\Models\DispositivoCozinha;
use App\Services\Cozinha\PareamentoDispositivoService;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identifica o tablet da cozinha pelo cookie gravado no pareamento e deixa o
 * dispositivo no container (DispositivoCozinha::atual()). O cookie guarda o
 * id e o instante do pareamento: parear de novo ou revogar invalida o
 * cookie antigo.
 *
 * A conta técnica da estação só funciona com um tablet válido; quem entra
 * com login próprio (gerente) usa o painel sem tablet.
 */
class IdentificaDispositivoCozinha
{
    public const COOKIE = 'dispositivo_cozinha';

    /** Intervalo mínimo entre gravações do "último sinal". */
    private const PING_SEGUNDOS = 30;

    public function handle(Request $request, Closure $next): Response
    {
        $dispositivo = self::doCookie($request->cookie(self::COOKIE));

        if ($dispositivo !== null) {
            app()->instance(DispositivoCozinha::ATUAL, $dispositivo);
            $this->registrarSinal($dispositivo, $request);
        }

        if ($dispositivo === null && Auth::user()?->hasRole(PareamentoDispositivoService::PAPEL_ESTACAO)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->to(Filament::getPanel('cozinha')->getLoginUrl())
                ->with('status', 'Este tablet foi desconectado. Peça ao gerente um QR novo para parear.');
        }

        return $next($request);
    }

    public static function valorDoCookie(DispositivoCozinha $dispositivo): string
    {
        return $dispositivo->id.'|'.$dispositivo->pareado_em?->timestamp;
    }

    private static function doCookie(mixed $valor): ?DispositivoCozinha
    {
        if (! is_string($valor) || ! str_contains($valor, '|')) {
            return null;
        }

        [$id, $pareadoEm] = explode('|', $valor, 2);
        $dispositivo = DispositivoCozinha::ativos()->find((int) $id);

        return $dispositivo !== null && $dispositivo->pareado_em !== null && (string) $dispositivo->pareado_em->timestamp === $pareadoEm
            ? $dispositivo
            : null;
    }

    /** Por query builder: o "estou vivo" não passa pela auditoria. */
    private function registrarSinal(DispositivoCozinha $dispositivo, Request $request): void
    {
        if ($dispositivo->ultimo_ping_em !== null && $dispositivo->ultimo_ping_em->gt(now()->subSeconds(self::PING_SEGUNDOS))) {
            return;
        }

        DispositivoCozinha::whereKey($dispositivo->id)->update(['ultimo_ping_em' => now(), 'ultimo_ip' => $request->ip()]);
    }
}
