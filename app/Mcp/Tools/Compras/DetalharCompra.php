<?php

namespace App\Mcp\Tools\Compras;

use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\CompraPendencia;
use App\Models\Lancamento;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('detalhar_compra')]
#[Description('Detalha uma compra: cabeçalho, itens (com o produto já vinculado), duplicatas da nota, títulos a pagar vinculados e pendências.')]
#[IsReadOnly]
class DetalharCompra extends FerramentaCompras
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'compra_id' => $schema->integer()->description('Id da compra.')->required(),
        ];
    }

    protected function executar(Request $request): Response
    {
        $dados = $request->validate(['compra_id' => ['required', 'integer']]);
        $compra = Compra::query()->with(['prestador', 'itens.insumo', 'lancamentos', 'pendencias'])->findOrFail($dados['compra_id']);

        return Response::json([
            'compra_id' => $compra->id,
            'situacao' => $compra->situacaoImportacao(),
            'numero' => $compra->compra_numero,
            'serie' => $compra->compra_serie,
            'chave_acesso' => $compra->compra_chave_nfe,
            'emissao' => $compra->compra_data_emissao?->toDateString(),
            'entrada' => $compra->compra_data_entrada?->toDateString(),
            'valor_nota' => (float) ($compra->compra_valor_nf ?? $compra->compra_valor_total),
            'operacao' => $compra->compra_operacao,
            'fornecedor' => ['fornecedor_id' => $compra->compra_prestador_id, 'nome' => $compra->prestador?->nome_exibicao, 'cnpj' => $compra->prestador?->cpf_cnpj],
            'itens' => $compra->itens->map(fn (CompraItem $item): array => [
                'compra_item_id' => $item->id,
                'codigo_fornecedor' => $item->ci_codigo_fornecedor,
                'descricao' => $item->ci_descricao_fornecedor,
                'ean' => $item->ci_ean,
                'unidade' => $item->ci_unidade_compra,
                'quantidade' => (float) $item->ci_quantidade_compra,
                'valor_unitario' => (float) $item->ci_custo_unitario_compra,
                'produto' => $item->insumo ? ['produto_id' => $item->insumo->id, 'descricao' => $item->insumo->produto_descricao, 'unidade_estoque' => $item->insumo->produto_unidade_estoque] : null,
                'fator_conversao' => (float) $item->ci_fator_conversao,
            ])->all(),
            'duplicatas' => $compra->compra_duplicatas_nf ?? [],
            'titulos' => $compra->lancamentos->map(fn (Lancamento $t): array => [
                'lancamento_id' => $t->id,
                'parcela' => $t->parcela_numero,
                'valor' => (float) $t->valor,
                'vencimento' => $t->vencimento?->toDateString(),
                'status' => $t->status->value,
            ])->all(),
            'pendencias' => $compra->pendencias->map(fn (CompraPendencia $p): array => [
                'pendencia_id' => $p->id, 'tipo' => $p->tipo->value, 'status' => $p->status->value,
            ])->all(),
        ]);
    }
}
