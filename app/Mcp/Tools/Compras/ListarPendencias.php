<?php

namespace App\Mcp\Tools\Compras;

use App\Enums\Compras\AutonomiaAgente;
use App\Enums\Compras\StatusPendencia;
use App\Enums\Compras\TipoPendenciaCompra;
use App\Models\CompraPendencia;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('listar_pendencias')]
#[Description('Lista as pendências ABERTAS da automação de compras com o contexto da nota: fornecedor, item (código, descrição, EAN, unidade, quantidade, valor) a autonomia do agente para aquele fornecedor e as observações do fornecedor — orientações do usuário que o agente deve seguir.')]
#[IsReadOnly]
class ListarPendencias extends FerramentaCompras
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'tipo' => $schema->string()->enum(array_column(TipoPendenciaCompra::cases(), 'value'))->description('Filtra por tipo de pendência.'),
            'limite' => $schema->integer()->min(1)->max(200)->description('Máximo de pendências (padrão 50).'),
        ];
    }

    protected function executar(Request $request): Response
    {
        $dados = $request->validate([
            'tipo' => ['nullable', 'string'],
            'limite' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $pendencias = CompraPendencia::query()
            ->where('status', StatusPendencia::Aberta)
            ->when($dados['tipo'] ?? null, fn ($query, string $tipo) => $query->where('tipo', $tipo))
            ->with(['compra.prestador.condicaoCompra', 'item'])
            ->orderBy('id')
            ->limit($dados['limite'] ?? 50)
            ->get();

        return Response::json([
            'total' => $pendencias->count(),
            'pendencias' => $pendencias->map(fn (CompraPendencia $p): array => [
                'pendencia_id' => $p->id,
                'tipo' => $p->tipo->value,
                'aberta_em' => $p->created_at?->toIso8601String(),
                'compra' => [
                    'compra_id' => $p->compra_id,
                    'numero' => $p->compra?->compra_numero,
                    'emissao' => $p->compra?->compra_data_emissao?->toDateString(),
                    'valor_nota' => (float) ($p->compra?->compra_valor_nf ?? $p->compra?->compra_valor_total),
                ],
                'fornecedor' => [
                    'fornecedor_id' => $p->compra?->compra_prestador_id,
                    'nome' => $p->compra?->prestador?->nome_exibicao,
                    'cnpj' => $p->compra?->prestador?->cpf_cnpj,
                    'autonomia_agente' => ($p->compra?->prestador?->condicaoCompra?->autonomiaAgente() ?? AutonomiaAgente::PADRAO)->value,
                    'observacoes' => $p->compra?->prestador?->condicaoCompra?->fcc_observacoes,
                ],
                'item' => $p->item ? [
                    'compra_item_id' => $p->item->id,
                    'codigo_fornecedor' => $p->item->ci_codigo_fornecedor,
                    'descricao' => $p->item->ci_descricao_fornecedor,
                    'ean' => $p->item->ci_ean,
                    'unidade' => $p->item->ci_unidade_compra,
                    'quantidade' => (float) $p->item->ci_quantidade_compra,
                    'valor_unitario' => (float) $p->item->ci_custo_unitario_compra,
                ] : null,
                'dados' => $p->dados,
            ])->all(),
        ]);
    }
}
