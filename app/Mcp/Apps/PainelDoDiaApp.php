<?php

namespace App\Mcp\Apps;

use App\Filament\Pages\FinanceiroDashboard;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\AppResource;
use Laravel\Mcp\Server\Attributes\AppMeta;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * Cartão "Painel do dia" (roteiro claude-mcp-gestao.md, G4): mini-dashboard
 * que o Claude mostra no chat quando chama painel_do_dia. Os números vêm da
 * própria tool (mesmas seções e permissões do resumo_do_dia); o cartão só
 * desenha e navega entre os dias.
 */
#[Name('painel-do-dia')]
#[Title('Painel do dia')]
#[Description('Cartão com vendas, caixa e pontos de atenção do dia.')]
#[AppMeta(prefersBorder: true)]
class PainelDoDiaApp extends AppResource
{
    public function handle(Request $request): Response
    {
        try {
            $linkPainel = FinanceiroDashboard::getUrl();
        } catch (\Throwable) {
            $linkPainel = url('/admin');
        }

        return Response::view('mcp.painel-do-dia', [
            'title' => $this->title(),
            'linkPainel' => $linkPainel,
        ]);
    }
}
