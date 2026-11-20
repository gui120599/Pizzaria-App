<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Servidor MCP de compra avulsa (/mcp/compras-avulsa): só usuário humano com
 * o Gate compras.avulsa (Admin e Gerente). O papel Agente é sempre recusado —
 * a tarefa agendada nunca lança compra nem movimenta caixa.
 */
class GarantirUsuarioCompraAvulsa
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if (! $usuario instanceof User || $usuario->hasRole('Agente') || ! Gate::forUser($usuario)->allows('compras.avulsa')) {
            abort(403, 'Acesso restrito a quem pode lançar compra avulsa.');
        }

        return $next($request);
    }
}
