<?php

namespace App\Providers\Filament;

use App\Filament\Garcom\Pages\AtenderMesa;
use App\Filament\Garcom\Pages\AtenderRetirada;
use App\Filament\Garcom\Pages\Auth\LoginGarcom;
use App\Filament\Garcom\Pages\MapaMesas;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Painel do Garçom: mapa de mesas/comandas, lançamento de rodadas e conta,
 * pensado para celular/tablet no salão. Mesmo guard `web` do /admin; quem
 * entra é decidido por User::canAccessPanel() (GARCOM_PANEL_ROLES).
 */
class GarcomPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('garcom')
            ->path('garcom')
            ->login(LoginGarcom::class)
            ->favicon(asset('favicon.png'))
            ->brandName('Salão')
            ->colors([
                'primary' => Color::Orange,
            ])
            ->viteTheme('resources/css/filament/garcom/theme.css')
            ->spa()
            ->topNavigation()
            ->maxContentWidth(Width::Full)
            ->homeUrl(fn (): string => MapaMesas::getUrl())
            // Sem discoverPages(): só as telas do salão, registradas aqui.
            ->pages([
                MapaMesas::class,
                AtenderMesa::class,
                AtenderRetirada::class,
            ])
            ->userMenuItems([
                'logout' => fn (Action $action) => $action
                    ->label('Trocar garçom / Sair')
                    ->icon(Heroicon::OutlinedArrowsRightLeft),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
