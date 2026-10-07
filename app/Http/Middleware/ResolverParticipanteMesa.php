<?php

namespace App\Http\Middleware;

use App\Exceptions\AcessoMesaNegadoException;
use App\Http\Controllers\MesaClienteController;
use App\Models\Mesa;
use App\Services\MesaCliente\ParticipanteMesaService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rotas da mesa que exigem o celular identificado: reconhece o participante
 * pelo token do cookie, nesta mesa e nesta conta aberta, e o deixa no request
 * (atributo "participante"). Nenhum id de conta ou de pedido vem do navegador.
 */
class ResolverParticipanteMesa
{
    public function __construct(private ParticipanteMesaService $participantes) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Mesa $mesa */
        $mesa = $request->route('mesa');

        if (! $this->participantes->pedidoPeloCelularLigado($mesa)) {
            return response()->json(['message' => 'O pedido pelo celular não está disponível nesta mesa. Chame o garçom.'], 403);
        }

        try {
            $participante = $this->participantes->resolver($mesa, $request->cookie(MesaClienteController::COOKIE));
        } catch (AcessoMesaNegadoException $e) {
            $status = match ($e->motivo) {
                AcessoMesaNegadoException::SEM_IDENTIFICACAO => 401,
                AcessoMesaNegadoException::CONTA_ENCERRADA => 410,
                AcessoMesaNegadoException::MESA_TROCADA => 409,
                default => 403,
            };

            return response()->json(['message' => $e->getMessage(), 'motivo' => $e->motivo], $status);
        }

        $request->attributes->set('participante', $participante);

        return $next($request);
    }
}
