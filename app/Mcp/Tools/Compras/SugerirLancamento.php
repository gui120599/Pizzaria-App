<?php

namespace App\Mcp\Tools\Compras;

use App\Models\CompraPendencia;
use App\Services\Compras\PendenciaService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('sugerir_lancamento')]
#[Description('Para pendências de lançamento ambíguo: sugere qual dos lançamentos candidatos (em pendencia.dados.candidatos) é o desta nota. Sempre fica aguardando aprovação humana; o agente nunca vincula nem dá baixa sozinho.')]
class SugerirLancamento extends FerramentaCompras
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'pendencia_id' => $schema->integer()->required(),
            'lancamento_id' => $schema->integer()->required(),
            'confianca' => $schema->number()->min(0)->max(1),
            'justificativa' => $schema->string()->required(),
        ];
    }

    protected function executar(Request $request): Response
    {
        $dados = $request->validate([
            'pendencia_id' => ['required', 'integer'],
            'lancamento_id' => ['required', 'integer'],
            'confianca' => ['nullable', 'numeric', 'between:0,1'],
            'justificativa' => ['required', 'string', 'max:1000'],
        ]);

        $resultado = app(PendenciaService::class)->sugerirLancamento(
            CompraPendencia::query()->findOrFail($dados['pendencia_id']),
            (int) $dados['lancamento_id'],
            isset($dados['confianca']) ? (float) $dados['confianca'] : null,
            $dados['justificativa'],
        );

        return Response::json($resultado);
    }
}
