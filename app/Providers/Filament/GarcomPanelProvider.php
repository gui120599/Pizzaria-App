<?php

namespace App\Providers\Filament;

use App\Filament\Garcom\Pages\AtenderMesa;
use App\Filament\Garcom\Pages\AtenderRetirada;
use App\Filament\Garcom\Pages\Auth\LoginGarcom;
use App\Filament\Garcom\Pages\BaterPonto;
use App\Filament\Garcom\Pages\ChecklistsDoDia;
use App\Filament\Garcom\Pages\ExecutarChecklist;
use App\Filament\Garcom\Pages\MapaMesas;
use App\Filament\Garcom\Pages\MeuEspelho;
use App\Filament\Garcom\Pages\MeusRepasses;
use App\Filament\Garcom\Pages\NotificacoesCelular;
use App\Filament\Garcom\Resources\ChecklistExecucoes\ChecklistExecucaoResource;
use App\Http\Controllers\Garcom\PwaGarcomController;
use App\Services\Push\DispositivoPushService;
use App\Support\PaletaMarca;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Vite;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
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
            ->favicon(asset('img/razelfood/favicon.ico'))
            ->brandName('RazelFood Salão')
            ->brandLogo(fn () => view('components.marca-razelfood', ['modulo' => 'Salão']))
            ->brandLogoHeight('2.4rem')
            ->colors([
                'primary' => PaletaMarca::brasa(),
                'info' => PaletaMarca::marinho(),
            ])
            // O desenho da pizza de sabores (seletor de produtos) vem de um módulo JS: o viteTheme só carrega CSS.
            ->renderHook(PanelsRenderHook::BODY_END, fn () => app(Vite::class)('resources/js/pizza-svg-painel.js'))
            ->viteTheme('resources/css/filament/garcom/theme.css')
            // Push (PWA): manifest e Service Worker sem login (o navegador os busca sozinho); 404 com PIZZARIA_PUSH_ATIVO=false.
            ->routes(function (): void {
                Route::get('manifest.webmanifest', [PwaGarcomController::class, 'manifest'])->name('manifest');
                Route::get('sw.js', [PwaGarcomController::class, 'serviceWorker'])->name('sw');
            })
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => DispositivoPushService::ativo() ? view('filament.garcom.partials.pwa-head') : '')
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn () => DispositivoPushService::ativo() && auth()->user()?->atendeSalao()
                    ? view('filament.garcom.partials.push-boot')
                    : '',
            )
            ->spa()
            ->topNavigation()
            ->maxContentWidth(Width::Full)
            // Sino com o "pedido pronto" da cozinha (ProducaoProntaNotification).
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            // Cozinha/Caixa entram só para checklists (User::CHECKLIST_PANEL_ROLES);
            // colaborador sem papel do salão entra só para o ponto.
            ->homeUrl(fn (): string => match (true) {
                auth()->user()?->atendeSalao() !== false => MapaMesas::getUrl(),
                auth()->user()->soBatePonto() => BaterPonto::getUrl(),
                default => ChecklistsDoDia::getUrl(),
            })
            // Sem discoverPages(): só as telas do salão, registradas aqui.
            ->pages([
                MapaMesas::class,
                AtenderMesa::class,
                AtenderRetirada::class,
                ChecklistsDoDia::class,
                ExecutarChecklist::class,
                // Folha de ponto: só aparecem para quem tem cadastro de colaborador.
                BaterPonto::class,
                MeuEspelho::class,
                MeusRepasses::class,
                NotificacoesCelular::class,
            ])
            ->resources([
                ChecklistExecucaoResource::class,
            ])
            ->userMenuItems([
                'notificacoes' => Action::make('notificacoes')
                    ->label('Notificações no celular')
                    ->icon(Heroicon::OutlinedBell)
                    ->url(fn (): string => NotificacoesCelular::getUrl())
                    ->visible(fn (): bool => NotificacoesCelular::canAccess()),
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
