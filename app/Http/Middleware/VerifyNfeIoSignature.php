<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifica a assinatura HMAC-SHA1 (header X-Hub-Signature) dos webhooks da
 * NFe.io e anota o resultado em $request->attributes('nfe_assinatura_valida')
 * — null quando NFE_IO_SECRET não está configurado (ex. ambiente local, onde
 * a NFe.io nem consegue alcançar o servidor pra mandar webhook de verdade),
 * true/false quando configurado. NUNCA bloqueia a requisição aqui: quem
 * decide se responde 403 é o NfeWebhookController, depois de já ter
 * persistido o payload bruto — assim uma assinatura inválida ainda fica
 * registrada pra auditoria, em vez de simplesmente sumir.
 */
class VerifyNfeIoSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.nfeio.webhook_secret');

        if (blank($secret)) {
            Log::warning('NFE_IO_SECRET não configurado — webhook aceito sem verificação de assinatura.');
            $request->attributes->set('nfe_assinatura_valida', null);

            return $next($request);
        }

        $assinaturaRecebida = $request->header('X-Hub-Signature');
        $assinaturaEsperada = hash_hmac('sha1', $request->getContent(), $secret);
        $valida = $assinaturaRecebida && hash_equals($assinaturaEsperada, $assinaturaRecebida);

        if (! $valida) {
            Log::warning('Webhook da NFe.io com assinatura inválida', ['received_signature' => $assinaturaRecebida]);
        }

        $request->attributes->set('nfe_assinatura_valida', $valida);

        return $next($request);
    }
}
