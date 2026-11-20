<?php

namespace App\Mcp\Tools\Gestao;

use App\Models\User;
use App\Services\RelatorioCortesiasService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('cortesias_periodo')]
#[Description('Cortesias concedidas num período (Relatório de Cortesias): produto, quantidade, valor de tabela, custo, motivo e quem autorizou, com totais.')]
#[IsReadOnly]
class CortesiasPeriodo extends FerramentaGestao
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'inicio' => $schema->string()->description('AAAA-MM-DD (padrão: hoje).'),
            'fim' => $schema->string()->description('AAAA-MM-DD (padrão: igual ao início).'),
            'busca' => $schema->string()->description('Filtra por produto, motivo ou autorizador.'),
        ];
    }

    protected function permitido(User $usuario): bool
    {
        return Gate::forUser($usuario)->allows('conceder_cortesia:pedido');
    }

    protected function executar(Request $request): Response
    {
        [$inicio, $fim] = $this->periodo($request, 92);
        $busca = (string) ($request->validate(['busca' => ['nullable', 'string', 'max:100']])['busca'] ?? '');
        $relatorio = new RelatorioCortesiasService($inicio, $fim, $busca);
        $linhas = $relatorio->linhas();

        return Response::json([
            'periodo' => self::rotuloPeriodo($inicio, $fim),
            'totais' => $relatorio->totais(),
            'cortesias' => $linhas->take(40)->map(fn (array $l): array => [...$l, 'data' => $l['data']->toIso8601String()])->values()->all(),
            'lista_truncada' => $linhas->count() > 40,
        ]);
    }
}
