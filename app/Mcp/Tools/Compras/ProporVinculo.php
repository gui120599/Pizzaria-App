<?php

namespace App\Mcp\Tools\Compras;

use App\Models\CompraPendencia;
use App\Services\Compras\PendenciaService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('propor_vinculo')]
#[Description('Propõe ligar o item de uma pendência (produto não identificado ou conversão de unidade) a um produto EXISTENTE, com o fator de conversão (1 unidade da nota = X unidades de estoque). O sistema decide: executa se a autonomia do fornecedor permitir e a confiança for alta; senão deixa como sugestão para aprovação humana.')]
class ProporVinculo extends FerramentaCompras
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'pendencia_id' => $schema->integer()->required(),
            'produto_id' => $schema->integer()->description('Produto existente (buscar_produtos).')->required(),
            'fator_conversao' => $schema->number()->min(0.0001)->description('1 unidade da nota = X unidades de estoque do produto.')->required(),
            'confianca' => $schema->number()->min(0)->max(1)->description('Confiança honesta de 0 a 1. Na dúvida entre dois produtos, use valor baixo.')->required(),
            'justificativa' => $schema->string()->description('Por que este produto e este fator (curto).')->required(),
        ];
    }

    protected function executar(Request $request): Response
    {
        $dados = $request->validate([
            'pendencia_id' => ['required', 'integer'],
            'produto_id' => ['required', 'integer'],
            'fator_conversao' => ['required', 'numeric', 'gt:0'],
            'confianca' => ['required', 'numeric', 'between:0,1'],
            'justificativa' => ['required', 'string', 'max:1000'],
        ]);

        $resultado = app(PendenciaService::class)->proporVinculo(
            CompraPendencia::query()->findOrFail($dados['pendencia_id']),
            (int) $dados['produto_id'],
            (float) $dados['fator_conversao'],
            (float) $dados['confianca'],
            $dados['justificativa'],
            $this->agente($request),
        );

        return Response::json($resultado);
    }
}
