<?php

namespace App\Mcp\Tools\Compras\Avulsa;

use App\Services\Compras\CompraAvulsaService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('descartar_rascunho')]
#[Description('Descarta um rascunho de compra avulsa sem gravar nada.')]
class DescartarRascunho extends FerramentaCompraAvulsa
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
        app(CompraAvulsaService::class)->descartar($uuid, $this->usuario($request));

        return Response::text('Rascunho descartado. Nada foi gravado.');
    }
}
