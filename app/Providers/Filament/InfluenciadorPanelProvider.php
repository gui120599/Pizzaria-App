<?php

namespace App\Providers\Filament;

use App\Filament\Influenciador\Pages\AceitarRegulamento;
use App\Filament\Influenciador\Pages\Auth\EditProfile;
use App\Filament\Influenciador\Pages\Auth\Register;
use App\Filament\Influenciador\Pages\Dashboard;
use App\Filament\Influenciador\Pages\KitDivulgacao;
use App\Filament\Influenciador\Pages\MinhaCarteira;
use App\Http\Middleware\ExigePendenciasDoInfluenciador;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class InfluenciadorPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel
            ->id('influenciador')
            ->path('influenciadores')
            ->authGuard('influenciador')
            ->login()
            ->registration(Register::class)
            ->favicon(asset('favicon.png'))
            ->colors([
                'primary' => Color::Emerald,
            ])
            // Sem discoverPages(): as únicas páginas em Filament/Influenciador/Pages
            // são a de registro (já referenciada acima via
            // registration(Register::class)) e o Dashboard (registrado
            // explicitamente abaixo) — nenhuma delas deve ser descoberta
            // automaticamente como item de navegação solto.
            ->discoverResources(in: app_path('Filament/Influenciador/Resources'), for: 'App\Filament\Influenciador\Resources')
            ->pages([
                Dashboard::class,
                KitDivulgacao::class,
                MinhaCarteira::class,
                AceitarRegulamento::class,
            ])
            ->profile(EditProfile::class)
            ->databaseNotifications()
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
                ExigePendenciasDoInfluenciador::class,
            ]);

        // "Esqueci a senha" só liga com e-mail configurado em produção (MAIL_*);
        // sem isso o link nunca chegaria e a tela só confundiria. Sem e-mail, o
        // Admin redefine a senha pelo cadastro do influenciador.
        if (self::emailConfigurado()) {
            config(['auth.passwords.influenciadores' => [
                'provider' => 'influenciadores',
                'table' => 'password_reset_tokens',
                'expire' => 60,
                'throttle' => 60,
            ]]);

            $panel->passwordReset()->authPasswordBroker('influenciadores');
        }

        return $panel;
    }

    public static function emailConfigurado(): bool
    {
        $mailer = (string) config('mail.default');

        return ! in_array($mailer, ['log', 'array', ''], true)
            && ($mailer !== 'smtp' || filled(config('mail.mailers.smtp.host')))
            && filled(config('mail.from.address'));
    }
}
