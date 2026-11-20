<?php

namespace App\Mcp\Tools\Compras\Avulsa;

use App\Filament\Resources\Compras\CompraResource;
use App\Services\Compras\CompraAvulsaService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('confirmar_compra_avulsa')]
#[Description('GRAVA a compra do rascunho: compra, itens, título a pagar e, se paga, a baixa na conta escolhida (com retirada na sessão se for Caixa PDV) e a entrada no estoque. Só chame depois que o usuário confirmar explicitamente o resumo. Confirmar de novo devolve a mesma compra.')]
#[IsIdempotent]
class ConfirmarCompraAvulsa extends FerramentaCompraAvulsa
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'rascunho_uuid' => $schema->string()->required(),
        ];
    }

    protected function executar(Request $request): Response
    {
        $uuid = (string) $request->validate(['rascunho_uuid' => ['required', 'string', 'size:36']])['rascunho_uuid'];
        $servico = app(CompraAvulsaService::class);
        $compra = $servico->confirmar($uuid, $this->usuario($request))->load(['lancamentos', 'pendencias']);

        return Response::json([
            'compra_id' => $compra->id,
            'situacao' => $compra->compra_status->label(),
            'total' => (float) $compra->compra_valor_total,
            'titulos' => $compra->lancamentos->map(fn ($titulo): array => [
                'lancamento_id' => $titulo->id,
                'valor' => (float) $titulo->valor,
                'vencimento' => $titulo->vencimento?->toDateString(),
                'status' => $titulo->status->getLabel(),
            ])->all(),
            'itens_pendentes' => $compra->pendencias->count(),
            'link_painel' => CompraResource::getUrl('edit', ['record' => $compra]),
            'link_anexar_comprovante' => $servico->linkAnexo($compra),
        ]);
    }
}
