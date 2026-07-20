<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyNfeIoSignature
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = env('NFE_IO_SECRET'); // Pegue a chave secreta do .env

        // Cabeçalho da assinatura X-Hub-Signature
        $receivedSignature = $request->header('X-Hub-Signature');

        // Corpo da requisição (payload)
        $payload = $request->getContent();

        // Gerar a assinatura HMAC-SHA1 do payload
        $generatedSignature = hash_hmac('sha1', $payload, $secret);

        Log::warning('📝 Payload recebido: '.$payload);
        Log::warning('🔑 Assinatura esperada: '.$generatedSignature);
        Log::warning('🔍 Assinatura recebida: '.$receivedSignature);

        // Verificar se a assinatura gerada corresponde à recebida
        if ($receivedSignature === $generatedSignature) {
            // A assinatura é válida, prossiga com o processamento
            Log::info('Webhook recebido com sucesso!');

            // Processar a requisição (salvar dados, atualizar o status, etc.)
            // Exemplo: Processar a notificação e atualizar o banco de dados.

            return response()->json(['status' => 'sucesso'], 200);
        } else {
            // Assinatura inválida
            Log::warning('Webhook com assinatura inválida', [
                'received_signature' => $receivedSignature,
                'generated_signature' => $generatedSignature,
            ]);

            return response()->json(['status' => 'erro'], 403); // Retorna Forbidden
        }
    }
    /*public function handle(Request $request, Closure $next): Response
    {
        $secret = env('NFE_IO_SECRET');
        $signature = $request->header('X-NFEIO-SIGNATURE');

        if(!$signature || $signature !== hash_hmac('sha256', $request->getContent(), $secret)){
            Log::warning('Webhook com assinatura inválida', ['received_signature' => $signature]);
            return response()->json(['message' => 'Assinatura inválida'], 403);
        }

        return $next($request);
    }*/

}
