<?php

namespace App\Mcp\Tools\Gestao;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Base das tools do conector "PizzariaAPP Gestão": só leitura, cada uma
 * protegida pela mesma permissão da tela de onde o número vem (o middleware
 * GarantirUsuarioGestao já pôs o usuário no guard "web"). A tool só aparece
 * (shouldRegister) e só responde para quem tem essa permissão. Toda chamada
 * vai para o log claude_gestao.
 */
abstract class FerramentaGestao extends Tool
{
    /** O usuário pode ver os dados desta tool (mesma regra da tela). */
    abstract protected function permitido(User $usuario): bool;

    abstract protected function executar(Request $request): Response;

    public function shouldRegister(): bool
    {
        $usuario = auth()->user();

        return $usuario instanceof User && $this->permitido($usuario);
    }

    public function handle(Request $request): Response
    {
        $usuario = $request->user();

        if (! $usuario instanceof User || ! $this->permitido($usuario)) {
            $this->registrar($request, 'negado');

            return Response::error('Seu perfil não tem acesso a estes dados no painel.');
        }

        try {
            $resposta = $this->executar($request);
            $this->registrar($request, 'ok');

            return $resposta;
        } catch (ValidationException $e) {
            $mensagem = (string) collect($e->errors())->flatten()->first();
            $this->registrar($request, 'recusado', $mensagem);

            return Response::error($mensagem);
        }
    }

    /**
     * Período das perguntas: `inicio`/`fim` (AAAA-MM-DD). Sem datas, o dia de
     * hoje; só `inicio`, aquele dia.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function periodo(Request $request, int $maximoDias = 400): array
    {
        $dados = $request->validate([
            'inicio' => ['nullable', 'date_format:Y-m-d'],
            'fim' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:inicio'],
        ]);

        $inicio = isset($dados['inicio']) ? Carbon::parse($dados['inicio'])->startOfDay() : today()->startOfDay();
        $fim = isset($dados['fim']) ? Carbon::parse($dados['fim'])->endOfDay() : $inicio->copy()->endOfDay();

        if ($inicio->diffInDays($fim) > $maximoDias) {
            throw ValidationException::withMessages(['fim' => "Período longo demais: no máximo {$maximoDias} dias por pergunta."]);
        }

        return [$inicio, $fim];
    }

    /** @return array{inicio: string, fim: string} */
    protected static function rotuloPeriodo(Carbon $inicio, Carbon $fim): array
    {
        return ['inicio' => $inicio->toDateString(), 'fim' => $fim->toDateString()];
    }

    protected static function variacao(float $atual, float $anterior): ?float
    {
        return $anterior != 0.0 ? round(($atual - $anterior) / abs($anterior) * 100, 1) : null;
    }

    private function registrar(Request $request, string $resultado, ?string $detalhe = null): void
    {
        Log::channel('claude_gestao')->info('mcp.'.$this->name(), [
            'usuario_id' => $request->user()?->getAuthIdentifier(),
            'argumentos' => Str::limit(json_encode($request->all(), JSON_UNESCAPED_UNICODE) ?: '', 1000),
            'resultado' => $resultado,
            'detalhe' => $detalhe,
        ]);
    }
}
