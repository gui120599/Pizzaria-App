<?php

namespace App\Mcp\Tools\Compras;

use App\Filament\Pages\PagamentosAConfirmar;
use App\Models\Lancamento;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('listar_pagamentos_a_confirmar')]
#[Description('Parcelas de compras automatizadas que esperam confirmação humana de pagamento (regra manual ou baixa automática que falhou), vencidas ou vencendo nos próximos dias. Só leitura, para o resumo.')]
#[IsReadOnly]
class ListarPagamentosAConfirmar extends FerramentaCompras
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'dias' => $schema->integer()->min(0)->max(60)->description('Vencendo até hoje + N dias (padrão 7).'),
        ];
    }

    protected function executar(Request $request): Response
    {
        $dias = (int) ($request->validate(['dias' => ['nullable', 'integer', 'min:0', 'max:60']])['dias'] ?? 7);

        $titulos = PagamentosAConfirmar::consulta()
            ->whereDate('vencimento', '<=', today()->addDays($dias))
            ->with(['compra.prestador', 'pagamentos'])
            ->orderBy('vencimento')
            ->limit(200)
            ->get();

        return Response::json([
            'total' => $titulos->count(),
            'valor_total' => round($titulos->sum(fn (Lancamento $t): float => (float) $t->valorRestante), 2),
            'parcelas' => $titulos->map(fn (Lancamento $t): array => [
                'lancamento_id' => $t->id,
                'fornecedor' => $t->compra?->prestador?->nome_exibicao,
                'descricao' => $t->descricao,
                'vencimento' => $t->vencimento?->toDateString(),
                'vencido' => $t->vencimento?->lt(today()) ?? false,
                'valor_a_pagar' => (float) $t->valorRestante,
                'forma' => $t->forma_pagamento?->value,
            ])->all(),
        ]);
    }
}
