<?php

namespace App\Mcp\Tools\Gestao;

use App\Models\User;
use App\Support\IndicadoresVendas;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('resumo_vendas')]
#[Description('Vendas finalizadas de um período, com os números do Painel Financeiro: faturamento, nº de vendas, ticket médio, CMV, margem bruta, descontos e cancelamentos — e o comparativo com o período anterior de mesma duração.')]
#[IsReadOnly]
class ResumoVendas extends FerramentaGestao
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'inicio' => $schema->string()->description('Data inicial AAAA-MM-DD (padrão: hoje).'),
            'fim' => $schema->string()->description('Data final AAAA-MM-DD (padrão: igual ao início).'),
        ];
    }

    protected function permitido(User $usuario): bool
    {
        return Gate::forUser($usuario)->allows('view:financeiro_dashboard');
    }

    protected function executar(Request $request): Response
    {
        [$inicio, $fim] = $this->periodo($request);
        $indicadores = new IndicadoresVendas;
        [$inicioAnterior, $fimAnterior] = $indicadores->anteriorA($inicio, $fim);

        $atual = self::arredondar($indicadores->indicadores($inicio, $fim));
        $anterior = self::arredondar($indicadores->indicadores($inicioAnterior, $fimAnterior));

        return Response::json([
            'periodo' => self::rotuloPeriodo($inicio, $fim),
            'indicadores' => $atual,
            'periodo_anterior' => [...self::rotuloPeriodo($inicioAnterior, $fimAnterior), 'indicadores' => $anterior],
            'variacao_pct' => collect(['faturamento', 'vendas', 'ticket', 'margem', 'descontos', 'canceladas'])
                ->mapWithKeys(fn (string $chave): array => [$chave => self::variacao((float) $atual[$chave], (float) $anterior[$chave])])
                ->all(),
            'observacao' => 'Valores em reais. Faturamento = soma das vendas finalizadas; CMV pelo custo congelado na venda.',
        ]);
    }

    /**
     * @param  array<string, float|int>  $indicadores
     * @return array<string, float|int>
     */
    private static function arredondar(array $indicadores): array
    {
        return collect($indicadores)->map(fn (float|int $valor): float|int => is_float($valor) ? round($valor, 2) : $valor)->all();
    }
}
