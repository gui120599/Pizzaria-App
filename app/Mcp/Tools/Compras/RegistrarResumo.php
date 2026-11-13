<?php

namespace App\Mcp\Tools\Compras;

use App\Enums\Compras\StatusPendencia;
use App\Models\AgenteExecucao;
use App\Models\CompraPendencia;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('registrar_resumo')]
#[Description('Encerra a rodada: grava o resumo do dia (notas importadas, resolvidas automaticamente, sugestões aguardando aprovação, pagamentos a confirmar e alertas de preço). Chame uma vez, no fim.')]
class RegistrarResumo extends FerramentaCompras
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'texto' => $schema->string()->description('Resumo em português, curto e objetivo.')->required(),
        ];
    }

    protected function executar(Request $request): Response
    {
        $texto = (string) $request->validate(['texto' => ['required', 'string', 'max:10000']])['texto'];
        $agente = $this->agente($request);
        $inicio = Carbon::parse((string) Cache::pull(self::CACHE_INICIO_RODADA, now()->toIso8601String()));

        $execucao = AgenteExecucao::create([
            'user_id' => $agente?->id,
            'iniciada_em' => $inicio,
            'finalizada_em' => now(),
            'pendencias_resolvidas' => CompraPendencia::query()
                ->where('status', StatusPendencia::Resolvida)
                ->where('resolvida_por', $agente?->id)
                ->where('resolvida_em', '>=', $inicio)
                ->count(),
            'pendencias_sugeridas' => CompraPendencia::query()
                ->where('status', StatusPendencia::Sugerida)
                ->where('updated_at', '>=', $inicio)
                ->count(),
            'resumo' => $texto,
        ]);

        return Response::json(['agente_execucao_id' => $execucao->id, 'registrado' => true]);
    }
}
