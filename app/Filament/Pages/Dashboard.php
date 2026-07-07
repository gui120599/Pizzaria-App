<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\AcessoRapidoWidget;
use App\Filament\Widgets\ResumoOperacionalWidget;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Widgets\AccountWidget;

/**
 * Página inicial do painel (Painel de Controle): retrato de "agora" +
 * acesso rápido aos módulos, para quem chega e precisa decidir para onde ir.
 * Lista os widgets explicitamente para que os widgets financeiros/operacionais
 * (descobertos automaticamente) NÃO apareçam aqui — são exclusivos dos seus
 * próprios dashboards (Painel Financeiro / Painel Operacional).
 */
class Dashboard extends BaseDashboard
{
    public function getColumns(): int|array
    {
        return 1;
    }

    public function getWidgets(): array
    {
        return [
            AccountWidget::class,
            ResumoOperacionalWidget::class,
            AcessoRapidoWidget::class,
        ];
    }
}
