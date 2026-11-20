<?php

namespace App\Mcp\Tools\Gestao;

use App\Models\ContaFinanceira;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('saldos_contas')]
#[Description('Saldo atual de cada conta financeira (bancos, contas digitais, cofre e Caixa PDV) e o total.')]
#[IsReadOnly]
class SaldosContas extends FerramentaGestao
{
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function permitido(User $usuario): bool
    {
        return Gate::forUser($usuario)->allows('view:relatorio_contas_pagar_receber');
    }

    protected function executar(Request $request): Response
    {
        $contas = ContaFinanceira::query()->ordenadas()->get();

        return Response::json([
            'contas' => $contas->map(fn (ContaFinanceira $conta): array => [
                'conta' => $conta->nome,
                'tipo' => $conta->tipo?->getLabel() ?? $conta->tipo?->value,
                'saldo' => round((float) $conta->saldo_atual, 2),
            ])->all(),
            'total' => round((float) $contas->sum('saldo_atual'), 2),
        ]);
    }
}
