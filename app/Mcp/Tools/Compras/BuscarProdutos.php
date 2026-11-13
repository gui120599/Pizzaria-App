<?php

namespace App\Mcp\Tools\Compras;

use App\Models\FornecedorProduto;
use App\Models\Produto;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('buscar_produtos')]
#[Description('Busca produtos do cadastro por nome, EAN ou pela descrição com que algum fornecedor já os vendeu (sinônimos do de-para). Devolve unidade de estoque e categoria.')]
#[IsReadOnly]
class BuscarProdutos extends FerramentaCompras
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'termo' => $schema->string()->description('Parte do nome, EAN ou descrição usada pelo fornecedor (mínimo 2 caracteres).')->required(),
        ];
    }

    protected function executar(Request $request): Response
    {
        $termo = trim((string) $request->validate(['termo' => ['required', 'string', 'min:2', 'max:100']])['termo']);

        $porSinonimo = FornecedorProduto::query()
            ->where('fp_descricao_fornecedor', 'like', "%{$termo}%")
            ->pluck('fp_produto_id');

        $produtos = Produto::query()
            ->with('categoria')
            ->where(fn ($query) => $query
                ->where('produto_descricao', 'like', "%{$termo}%")
                ->orWhere('produto_codigo_EAN', $termo)
                ->orWhereIn('id', $porSinonimo))
            ->orderBy('produto_descricao')
            ->limit(30)
            ->get();

        return Response::json([
            'total' => $produtos->count(),
            'produtos' => $produtos->map(fn (Produto $p): array => [
                'produto_id' => $p->id,
                'descricao' => $p->produto_descricao,
                'tipo' => $p->produto_tipo,
                'unidade_estoque' => $p->produto_unidade_estoque,
                'ean' => $p->produto_codigo_EAN !== 'SEM GTIN' ? $p->produto_codigo_EAN : null,
                'categoria_id' => $p->produto_categoria_id,
                'categoria' => $p->categoria?->categoria_nome,
                'pendente_revisao' => (bool) $p->produto_cadastro_pendente_revisao,
            ])->all(),
        ]);
    }
}
