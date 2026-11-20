<?php

namespace App\Mcp\Tools\Gestao;

use App\Models\User;
use App\Services\RelatorioFechamentoCaixaService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('fechamento_caixa')]
#[Description('Relatório de Fechamento de Caixa de um dia ou período: resumo (faturamento, recebido, fiado, troco, taxas de maquininha, CMV, margem, cancelamentos), conferência por sessão (esperado × apurado e diferença) e recebimentos por forma de pagamento.')]
#[IsReadOnly]
class FechamentoCaixa extends FerramentaGestao
{
    private const CHAVES_RESUMO = [
        'vendas', 'faturamento', 'ticket_medio', 'descontos', 'frete', 'taxa_servico', 'recebido', 'fiado',
        'fiado_recebido', 'troco', 'volume_maquininha', 'mdr', 'mdr_efetivo', 'tarifa_pix_cnpj', 'imposto_nfe',
        'cmv', 'cmv_percentual', 'margem_contribuicao', 'margem_percentual', 'canceladas', 'valor_cancelado',
        'taxa_cancelamento',
    ];

    public function schema(JsonSchema $schema): array
    {
        return [
            'inicio' => $schema->string()->description('Data inicial AAAA-MM-DD (padrão: hoje).'),
            'fim' => $schema->string()->description('Data final AAAA-MM-DD (padrão: igual ao início).'),
        ];
    }

    protected function permitido(User $usuario): bool
    {
        return Gate::forUser($usuario)->allows('view:relatorio_fechamento_caixa');
    }

    protected function executar(Request $request): Response
    {
        [$inicio, $fim] = $this->periodo($request, 92);
        $relatorio = new RelatorioFechamentoCaixaService(['inicio' => $inicio->toDateString(), 'fim' => $fim->toDateString()]);

        return Response::json([
            'periodo' => self::rotuloPeriodo($inicio, $fim),
            'resumo' => Arr::only($relatorio->resumo(), self::CHAVES_RESUMO),
            'conferencia_por_sessao' => $relatorio->conferencia()->map(fn (array $linha): array => [
                ...Arr::except($linha, ['key', 'abertura', 'fechamento']),
                'abertura' => $linha['abertura']?->toIso8601String(),
                'fechamento' => $linha['fechamento']?->toIso8601String(),
            ])->values()->all(),
            'por_forma_pagamento' => $relatorio->porFormaPagamento()->map(fn (array $linha): array => Arr::except($linha, ['key']))->values()->all(),
            'observacao' => 'diferenca = apurado − esperado (negativo é falta). Percentuais já em %.',
        ]);
    }
}
