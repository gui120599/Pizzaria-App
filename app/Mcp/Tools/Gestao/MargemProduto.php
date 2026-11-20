<?php

namespace App\Mcp\Tools\Gestao;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('margem_produto')]
#[Description('Custo de hoje de um produto pela ficha técnica (custo médio atual dos insumos), preço de venda, margem e CMV %. Use para "quanto me custa uma pizza X?" ou "a margem caiu?".')]
#[IsReadOnly]
class MargemProduto extends FerramentaGestao
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'produto_id' => $schema->integer()->description('Id do produto (se souber).'),
            'busca' => $schema->string()->description('Nome do produto, se não tiver o id.'),
        ];
    }

    protected function permitido(User $usuario): bool
    {
        return Gate::forUser($usuario)->allows('view_any:ficha_tecnica');
    }

    protected function executar(Request $request): Response
    {
        $produto = $this->produto($request)->load('fichaItens.insumo');
        $preco = round((float) $produto->produto_preco_venda, 2);
        $custo = round($produto->custoUnitario(), 4);

        return Response::json([
            'produto_id' => $produto->id,
            'produto' => $produto->produto_descricao,
            'tem_ficha_tecnica' => $produto->fichaItens->isNotEmpty(),
            'preco_venda' => $preco,
            'custo_unitario' => $custo,
            'margem' => round($preco - $custo, 2),
            'margem_pct' => $preco > 0 ? round(($preco - $custo) / $preco * 100, 1) : null,
            'cmv_pct' => $preco > 0 ? round($custo / $preco * 100, 1) : null,
            'insumos' => $produto->fichaItens->map(fn ($item): array => [
                'insumo' => $item->insumo?->produto_descricao,
                'quantidade' => (float) $item->fti_quantidade,
                'unidade' => $item->fti_unidade ?? $item->insumo?->produto_unidade_estoque,
                'perda_pct' => (float) $item->fti_percentual_perda,
            ])->all(),
            'observacao' => 'Custo calculado com o custo médio atual dos insumos (preço de venda cheio, sem promoção).',
        ]);
    }
}
