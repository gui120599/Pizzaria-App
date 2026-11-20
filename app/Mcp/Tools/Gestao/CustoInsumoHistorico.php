<?php

namespace App\Mcp\Tools\Gestao;

use App\Enums\MovimentacaoOrigemEnum;
use App\Models\MovimentacaoProduto;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('custo_insumo_historico')]
#[Description('Como o custo de um insumo mudou: custo médio no início e no fim do período (mesma leitura de "Custo médio histórico") e as compras do período com quantidade e custo unitário. Padrão: últimos 90 dias.')]
#[IsReadOnly]
class CustoInsumoHistorico extends FerramentaGestao
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'produto_id' => $schema->integer()->description('Id do produto (se souber).'),
            'busca' => $schema->string()->description('Nome do produto, se não tiver o id.'),
            'inicio' => $schema->string()->description('AAAA-MM-DD (padrão: 90 dias atrás).'),
            'fim' => $schema->string()->description('AAAA-MM-DD (padrão: hoje).'),
        ];
    }

    protected function permitido(User $usuario): bool
    {
        return Gate::forUser($usuario)->allows('view:custo_medio_historico');
    }

    protected function executar(Request $request): Response
    {
        $produto = $this->produto($request);
        $dados = $request->validate(['inicio' => ['nullable', 'date_format:Y-m-d'], 'fim' => ['nullable', 'date_format:Y-m-d']]);
        $inicio = isset($dados['inicio']) ? Carbon::parse($dados['inicio'])->startOfDay() : today()->subDays(90);
        $fim = isset($dados['fim']) ? Carbon::parse($dados['fim'])->endOfDay() : now()->endOfDay();

        // Mesma leitura da página Custo médio histórico: último custo médio até a data.
        $custoAte = fn (Carbon $data): ?float => ($valor = MovimentacaoProduto::query()
            ->where('mov_produto_id', $produto->id)
            ->where('mov_data', '<=', $data)
            ->orderByDesc('id')
            ->value('mov_custo_medio_apos')) !== null ? round((float) $valor, 4) : null;

        $inicial = $custoAte($inicio->copy()->subSecond());
        $final = $custoAte($fim);

        $compras = MovimentacaoProduto::query()
            ->where('mov_produto_id', $produto->id)
            ->where('mov_origem', MovimentacaoOrigemEnum::COMPRA)
            ->whereBetween('mov_data', [$inicio, $fim])
            ->orderBy('mov_data')
            ->limit(60)
            ->get();

        return Response::json([
            'produto' => ['produto_id' => $produto->id, 'descricao' => $produto->produto_descricao, 'unidade' => $produto->produto_unidade_estoque],
            'periodo' => self::rotuloPeriodo($inicio, $fim),
            'custo_medio_inicio' => $inicial,
            'custo_medio_fim' => $final,
            'variacao_pct' => $inicial !== null && $final !== null ? self::variacao($final, $inicial) : null,
            'compras' => $compras->map(fn (MovimentacaoProduto $m): array => [
                'data' => Carbon::parse($m->mov_data)->toDateString(),
                'quantidade' => round((float) $m->mov_quantidade, 3),
                'custo_unitario' => round((float) $m->mov_custo_unitario, 4),
                'custo_medio_apos' => round((float) $m->mov_custo_medio_apos, 4),
            ])->all(),
        ]);
    }
}
