<?php

use App\Http\Middleware\GarantirAgenteCompras;
use App\Mcp\Servers\ComprasServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| Servidores MCP
|--------------------------------------------------------------------------
|
| Agente de compras (docs/planos/compras-automatizadas.md §9): OAuth 2.1 via
| Passport (descoberta + registro dinâmico de cliente pelo oauthRoutes) e só
| o usuário com papel "Agente" passa. Revogar os tokens desse usuário desliga
| o agente (Estoque › Agente de compras › Revogar acesso).
|
*/

Mcp::oauthRoutes();

Mcp::web('/mcp/compras', ComprasServer::class)
    ->middleware(['auth:api', GarantirAgenteCompras::class, 'throttle:60,1'])
    ->name('mcp.compras');
