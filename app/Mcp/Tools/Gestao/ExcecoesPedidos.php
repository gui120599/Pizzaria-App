<?php

namespace App\Mcp\Tools\Gestao;

use App\Models\User;
use App\Services\RelatorioExcecoesPedidosService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('excecoes_pedidos')]
#[Description('Relatório de Exceções e Perdas: cancelamentos (por motivo e por quem lançou/cancelou), itens removidos, descontos, cortesias, pedidos excluídos e entregues sem pagamento, num período.')]
#[IsReadOnly]
class ExcecoesPedidos extends FerramentaGestao
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
        return Gate::forUser($usuario)->allows('view:relatorio_excecoes_pedidos');
    }

    protected function executar(Request $request): Response
    {
        [$inicio, $fim] = $this->periodo($request, 92);
        $relatorio = new RelatorioExcecoesPedidosService($inicio, $fim);

        return Response::json([
            'periodo' => self::rotuloPeriodo($inicio, $fim),
            'resumo' => $relatorio->resumo(),
            'cancelamentos_por_motivo' => $relatorio->cancelamentosPorMotivo()->map(fn (array $l): array => Arr::except($l, ['chave', 'observacoes']))->values()->all(),
            'cancelamentos_por_usuario' => $relatorio->cancelamentosPorUsuario()->take(15)->map(fn (array $l): array => Arr::except($l, ['chave']))->values()->all(),
            'itens_removidos_por_usuario' => $relatorio->itensRemovidosPorUsuario()->take(15)->map(fn (array $l): array => Arr::except($l, ['chave']))->values()->all(),
        ]);
    }
}
