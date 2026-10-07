<?php

namespace App\Providers;

use App\Http\Controllers\MesaClienteController;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/dashboard';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Página do QR da mesa: por celular identificado (ou IP + mesa, antes de
        // se identificar). O polling consome 4/min; sobra folga para os toques.
        RateLimiter::for('mesa-cliente', function (Request $request) {
            $token = $request->cookie(MesaClienteController::COOKIE);

            // O throttle roda antes do route model binding: aqui {mesa} ainda é o código do QR.
            return Limit::perMinute(60)->by($token ? 'mp:'.sha1($token) : 'ip:'.$request->ip().'|'.$request->route()?->originalParameter('mesa'));
        });

        // Identificação e pedido de abertura: por IP, contra cadastro em massa.
        RateLimiter::for('mesa-cliente-entrada', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
