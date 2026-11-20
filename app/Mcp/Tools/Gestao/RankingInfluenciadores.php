<?php

namespace App\Mcp\Tools\Gestao;

use App\Models\User;
use App\Services\RelatorioInfluenciadoresService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('ranking_influenciadores')]
#[Description('Relatório de Influenciadores num período: cliques, pedidos, vendas, descontos de cupom, comissões, resgates PIX, consumo e saldo de carteira de cada influenciador, do que mais vendeu para o que menos vendeu.')]
#[IsReadOnly]
class RankingInfluenciadores extends FerramentaGestao
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'inicio' => $schema->string()->description('AAAA-MM-DD (padrão: hoje).'),
            'fim' => $schema->string()->description('AAAA-MM-DD (padrão: igual ao início).'),
        ];
    }

    protected function permitido(User $usuario): bool
    {
        return Gate::forUser($usuario)->allows('view:relatorio_influenciadores');
    }

    protected function executar(Request $request): Response
    {
        [$inicio, $fim] = $this->periodo($request, 400);

        return Response::json([
            'periodo' => self::rotuloPeriodo($inicio, $fim),
            'influenciadores' => (new RelatorioInfluenciadoresService($inicio, $fim))->linhas()->sortByDesc('vendas')->values()->all(),
        ]);
    }
}
