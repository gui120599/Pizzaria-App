<?php

namespace App\Mcp\Tools\Compras;

use App\Models\FornecedorProduto;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('historico_vinculos_fornecedor')]
#[Description('De-paras já confirmados de um fornecedor: código e descrição na nota, unidade, produto vinculado, fator de conversão e último custo.')]
#[IsReadOnly]
class HistoricoVinculosFornecedor extends FerramentaCompras
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'fornecedor_id' => $schema->integer()->description('Id do fornecedor (vem em listar_pendencias).')->required(),
        ];
    }

    protected function executar(Request $request): Response
    {
        $fornecedorId = (int) $request->validate(['fornecedor_id' => ['required', 'integer']])['fornecedor_id'];

        $vinculos = FornecedorProduto::query()
            ->where('fp_prestador_id', $fornecedorId)
            ->with('produto')
            ->orderBy('fp_descricao_fornecedor')
            ->limit(300)
            ->get();

        return Response::json([
            'total' => $vinculos->count(),
            'vinculos' => $vinculos->map(fn (FornecedorProduto $v): array => [
                'codigo_fornecedor' => $v->fp_codigo_fornecedor,
                'descricao_fornecedor' => $v->fp_descricao_fornecedor,
                'unidade_fornecedor' => $v->fp_unidade_compra,
                'produto_id' => $v->fp_produto_id,
                'produto' => $v->produto?->produto_descricao,
                'unidade_estoque' => $v->produto?->produto_unidade_estoque,
                'fator_conversao' => (float) $v->fp_fator_conversao,
                'ultimo_custo_unitario' => $v->fp_ultimo_custo_unitario !== null ? (float) $v->fp_ultimo_custo_unitario : null,
                'origem' => $v->fp_origem?->value,
            ])->all(),
        ]);
    }
}
