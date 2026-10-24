<?php

namespace App\Filament\Influenciador\Pages;

use App\Models\Influenciador;
use App\Models\RegulamentoInfluenciador;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Tela obrigatória quando sai uma versão nova do regulamento: o influenciador
 * lê e aceita antes de usar o resto do painel (ver ExigePendenciasDoInfluenciador).
 */
class AceitarRegulamento extends Page
{
    protected static ?string $title = 'Aceitar regulamento';

    protected static ?string $slug = 'aceitar-regulamento';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.influenciador.pages.aceitar-regulamento';

    public bool $aceito = false;

    public function regulamento(): ?RegulamentoInfluenciador
    {
        return RegulamentoInfluenciador::atual();
    }

    public function aceitar(): void
    {
        if (! $this->aceito) {
            Notification::make()->title('Marque que leu e aceita o regulamento')->danger()->send();

            return;
        }

        /** @var Influenciador $influenciador */
        $influenciador = Auth::guard('influenciador')->user();
        $influenciador->aceitarRegulamento(request()->ip());

        $this->redirect(Dashboard::getUrl(panel: 'influenciador'));
    }
}
