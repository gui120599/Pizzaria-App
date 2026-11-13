<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Servidor MCP de compras: só o papel "Agente" passa. Verificado pelo papel
 * (não por Gate) de propósito: o Admin passa em todo Gate via Gate::before
 * (Filament Shield), e o conector do agente não deve funcionar com um token
 * de administrador.
 */
class GarantirAgenteCompras
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->hasRole('Agente')) {
            abort(403, 'Acesso restrito ao agente de compras.');
        }

        return $next($request);
    }
}
