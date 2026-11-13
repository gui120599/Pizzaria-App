<?php

namespace App\Mcp\Tools\Compras;

use App\Models\CompraPendencia;
use App\Services\Compras\PendenciaService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('propor_novo_produto')]
#[Description('Propõe cadastrar um produto NOVO para o item da pendência, quando nenhum produto existente serve. Só é executado se o fornecedor tiver autonomia "completa" e a confiança for alta; o produto nasce marcado para revisão. Nos demais casos fica como sugestão.')]
class ProporNovoProduto extends FerramentaCompras
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'pendencia_id' => $schema->integer()->required(),
            'descricao' => $schema->string()->description('Nome do produto no nosso cadastro (não o texto da nota).')->required(),
            'unidade_estoque' => $schema->string()->description('Unidade de estoque (ex.: KG, UN, L).')->required(),
            'categoria_id' => $schema->integer()->description('Categoria (veja categoria_id em buscar_produtos). Opcional.'),
            'fator_conversao' => $schema->number()->min(0.0001)->description('1 unidade da nota = X unidades de estoque.')->required(),
            'confianca' => $schema->number()->min(0)->max(1)->required(),
            'justificativa' => $schema->string()->required(),
        ];
    }

    protected function executar(Request $request): Response
    {
        $dados = $request->validate([
            'pendencia_id' => ['required', 'integer'],
            'descricao' => ['required', 'string', 'max:255'],
            'unidade_estoque' => ['required', 'string', 'max:10'],
            'categoria_id' => ['nullable', 'integer', 'exists:categorias,id'],
            'fator_conversao' => ['required', 'numeric', 'gt:0'],
            'confianca' => ['required', 'numeric', 'between:0,1'],
            'justificativa' => ['required', 'string', 'max:1000'],
        ]);

        $resultado = app(PendenciaService::class)->proporNovoProduto(
            CompraPendencia::query()->findOrFail($dados['pendencia_id']),
            array_filter([
                'produto_descricao' => $dados['descricao'],
                'produto_unidade_estoque' => mb_strtoupper($dados['unidade_estoque']),
                'produto_categoria_id' => $dados['categoria_id'] ?? null,
            ]),
            (float) $dados['fator_conversao'],
            (float) $dados['confianca'],
            $dados['justificativa'],
            $this->agente($request),
        );

        return Response::json($resultado);
    }
}
