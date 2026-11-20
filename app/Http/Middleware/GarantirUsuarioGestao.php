<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Conector "PizzariaAPP Gestão" (/mcp/gestao): o token chega pelo guard
 * "api" (Passport), mas as permissões e policies do sistema são do guard
 * "web". Depois de autenticar, o usuário passa a valer no guard "web" desta
 * requisição — assim cada tool enxerga exatamente as permissões do painel.
 * Exige usar:claude_gestao e recusa o papel Agente.
 */
class GarantirUsuarioGestao
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if (! $usuario instanceof User || $usuario->hasRole('Agente')) {
            abort(403, 'Acesso restrito ao dono e aos gerentes.');
        }

        Auth::shouldUse('web');
        Auth::guard('web')->setUser($usuario);

        if (! Gate::forUser($usuario)->allows('usar:claude_gestao')) {
            abort(403, 'Seu perfil não tem acesso ao conector de gestão.');
        }

        return $next($request);
    }
}
