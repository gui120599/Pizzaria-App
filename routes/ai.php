<?php

use App\Http\Middleware\GarantirAgenteCompras;
use App\Http\Middleware\GarantirUsuarioCompraAvulsa;
use App\Http\Middleware\GarantirUsuarioGestao;
use App\Mcp\Servers\CompraAvulsaServer;
use App\Mcp\Servers\ComprasServer;
use App\Mcp\Servers\GestaoServer;
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

/*
| Compras avulsas sem NF-e (Fase 5): usuário humano com o Gate compras.avulsa
| (Admin e Gerente), logado com o próprio usuário. O papel Agente recebe 403.
*/
Mcp::web('/mcp/compras-avulsa', CompraAvulsaServer::class)
    ->middleware(['auth:api', GarantirUsuarioCompraAvulsa::class, 'throttle:60,1'])
    ->name('mcp.compras-avulsa');

/*
| Gestão (roteiro claude-mcp-gestao.md, G1): dono e gerentes, só leitura.
| Cada tool segue a permissão da tela correspondente; papel Agente recebe 403.
*/
Mcp::web('/mcp/gestao', GestaoServer::class)
    ->middleware(['auth:api', GarantirUsuarioGestao::class, 'throttle:60,1'])
    ->name('mcp.gestao');
