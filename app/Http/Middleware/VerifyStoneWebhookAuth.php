<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica os webhooks do Connect Stone (POST /api/webhook/stone-connect).
 * O Connect Stone roda sobre o Pagar.me v5, que não assina o corpo com HMAC —
 * a autenticidade vem do HTTP Basic Auth configurado na URL de webhook na
 * Dashboard do Pagar.me. Anota o resultado em
 * $request->attributes('stone_webhook_autenticado') — null quando as
 * credenciais não estão configuradas (ex. ambiente local, onde a Stone nem
 * alcança o servidor), true/false quando configuradas.
 *
 * NUNCA bloqueia a requisição aqui: quem decide se responde 401 é o
 * StoneWebhookController, depois de já ter persistido o payload bruto — assim
 * uma tentativa não autenticada ainda fica registrada pra auditoria em vez de
 * simplesmente sumir.
 */
class VerifyStoneWebhookAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = config('services.stone.webhook_user');
        $password = config('services.stone.webhook_password');

        if (blank($user) && blank($password)) {
            Log::warning('Credenciais do webhook Stone não configuradas — webhook aceito sem autenticação.');
            $request->attributes->set('stone_webhook_autenticado', null);

            return $next($request);
        }

        $valido = hash_equals((string) $user, (string) $request->getUser())
            && hash_equals((string) $password, (string) $request->getPassword());

        if (! $valido) {
            Log::warning('Webhook da Stone com credenciais inválidas', ['user' => $request->getUser()]);
        }

        $request->attributes->set('stone_webhook_autenticado', $valido);

        return $next($request);
    }
}
