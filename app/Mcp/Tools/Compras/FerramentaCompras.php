<?php

namespace App\Mcp\Tools\Compras;

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Base das tools do servidor MCP de compras: registra toda chamada (tool,
 * argumentos, resultado) no log do agente e transforma erro de validação
 * em resposta de erro legível, sem derrubar a conversa.
 */
abstract class FerramentaCompras extends Tool
{
    /** Chave do início da rodada atual do agente (fechada por registrar_resumo). */
    public const CACHE_INICIO_RODADA = 'compras.agente.inicio_rodada';

    public function handle(Request $request): Response
    {
        Cache::add(self::CACHE_INICIO_RODADA, now()->toIso8601String(), now()->addDay());

        try {
            $resposta = $this->executar($request);
            $this->registrar($request, $resposta->isError() ? 'erro' : 'ok');

            return $resposta;
        } catch (ValidationException $e) {
            $mensagem = (string) collect($e->errors())->flatten()->first();
            $this->registrar($request, 'recusado', $mensagem);

            return Response::error($mensagem);
        } catch (ModelNotFoundException) {
            $this->registrar($request, 'recusado', 'registro não encontrado');

            return Response::error('Registro não encontrado.');
        }
    }

    abstract protected function executar(Request $request): Response;

    protected function agente(Request $request): ?User
    {
        $usuario = $request->user();

        return $usuario instanceof User ? $usuario : null;
    }

    private function registrar(Request $request, string $resultado, ?string $detalhe = null): void
    {
        Log::channel('agente_compras')->info('mcp.'.$this->name(), [
            'usuario_id' => $request->user()?->getAuthIdentifier(),
            'argumentos' => Str::limit(json_encode($request->all(), JSON_UNESCAPED_UNICODE) ?: '', 2000),
            'resultado' => $resultado,
            'detalhe' => $detalhe,
        ]);
    }
}
