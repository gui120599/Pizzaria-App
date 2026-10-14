<?php

namespace App\Providers\Filament;

use App\Filament\Cozinha\Pages\ChecklistsCozinha;
use App\Filament\Cozinha\Pages\ConsultaFichas;
use App\Filament\Cozinha\Pages\Estacao;
use App\Filament\Cozinha\Pages\ExecutarChecklistCozinha;
use App\Filament\Cozinha\Pages\Expedicao;
use App\Http\Controllers\Cozinha\PareamentoController;
use App\Http\Middleware\IdentificaDispositivoCozinha;
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
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Painel da cozinha (/cozinha, spec §6.1): tablets pareados por QR (conta
 * técnica da estação + cookie do dispositivo) e o celular do gerente com
 * login próprio. Tema escuro, navegação no topo, PWA em tela cheia.
 */
class CozinhaPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('cozinha')
            ->path('cozinha')
            ->login()
            ->favicon(asset('favicon.png'))
            ->brandName('Cozinha')
            ->colors([
                'primary' => Color::Orange,
            ])
            ->darkMode(true, isForced: true)
            ->viteTheme('resources/css/filament/cozinha/theme.css')
            ->spa()
            ->topNavigation()
            ->maxContentWidth(Width::Full)
            ->databaseNotifications()
            ->homeUrl(fn (): string => Estacao::getUrl(panel: 'cozinha'))
            ->pages([
                Estacao::class,
                Expedicao::class,
                ChecklistsCozinha::class,
                ExecutarChecklistCozinha::class,
                ConsultaFichas::class,
            ])
            // Fora do authMiddleware: o tablet ainda não está logado ao ler o QR.
            ->routes(function (): void {
                Route::get('parear/{token}', PareamentoController::class)->name('parear');
                Route::get('manifest.webmanifest', fn (): JsonResponse => response()->json([
                    'name' => config('app.name').' — Cozinha',
                    'short_name' => 'Cozinha',
                    'start_url' => '/cozinha',
                    'scope' => '/cozinha',
                    'display' => 'fullscreen',
                    'orientation' => 'landscape',
                    'background_color' => '#111827',
                    'theme_color' => '#f97316',
                    'icons' => [['src' => asset('favicon.png'), 'sizes' => '512x512', 'type' => 'image/png']],
                ], headers: ['Content-Type' => 'application/manifest+json']))->name('manifest');
            })
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('cozinha.partials.pwa-head'))
            ->renderHook(PanelsRenderHook::BODY_END, fn () => view('cozinha.partials.pwa-body'))
            ->userMenuItems([
                'logout' => fn (Action $action) => $action
                    ->label('Sair')
                    ->icon(Heroicon::OutlinedArrowRightStartOnRectangle),
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
            // Persistente: as atualizações do Livewire (poll, botões) também
            // passam pela identificação do tablet.
            ->authMiddleware([
                Authenticate::class,
                IdentificaDispositivoCozinha::class,
            ], isPersistent: true);
    }
}
