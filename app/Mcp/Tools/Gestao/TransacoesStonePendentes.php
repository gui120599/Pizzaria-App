<?php

namespace App\Mcp\Tools\Gestao;

use App\Enums\StonePedidoStatus;
use App\Models\StonePedido;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('transacoes_stone_pendentes')]
#[Description('Cobranças da maquininha Stone (Connect) que precisam de atenção: com falha, em situação indeterminada ou aguardando há mais de 30 minutos, com valor, maquininha e erro.')]
#[IsReadOnly]
class TransacoesStonePendentes extends FerramentaGestao
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'dias' => $schema->integer()->min(1)->max(60)->description('Olhar os últimos N dias (padrão 7).'),
        ];
    }

    protected function permitido(User $usuario): bool
    {
        return Gate::forUser($usuario)->allows('view_any:stone_pedido');
    }

    protected function executar(Request $request): Response
    {
        $dias = (int) ($request->validate(['dias' => ['nullable', 'integer', 'min:1', 'max:60']])['dias'] ?? 7);

        $pedidos = StonePedido::query()
            ->with('maquininha')
            ->where('created_at', '>=', now()->subDays($dias))
            ->where(fn ($q) => $q
                ->whereIn('stp_status', [StonePedidoStatus::Falha, StonePedidoStatus::Indeterminado])
                ->orWhere(fn ($a) => $a->where('stp_status', StonePedidoStatus::Aguardando)->where('created_at', '<', now()->subMinutes(30))))
            ->latest()
            ->limit(40)
            ->get();

        return Response::json([
            'ultimos_dias' => $dias,
            'total' => $pedidos->count(),
            'cobrancas' => $pedidos->map(fn (StonePedido $p): array => [
                'id' => $p->id,
                'criada_em' => $p->created_at?->toIso8601String(),
                'status' => $p->stp_status?->value ?? $p->stp_status,
                'valor_solicitado' => round((float) $p->stp_valor_solicitado, 2),
                'valor_pago' => round((float) $p->stp_valor_pago, 2),
                'maquininha' => $p->maquininha?->nome,
                'venda_id' => $p->stp_venda_id,
                'pedido_id' => $p->stp_pedido_id,
                'erro' => $p->stp_erro,
            ])->all(),
        ]);
    }
}
