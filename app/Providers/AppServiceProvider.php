<?php

namespace App\Providers;

use App\Services\PrecificadorService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Singleton (efetivamente por requisição): memoiza as promoções relâmpago
        // vigentes uma vez, evitando N+1 quando cada card do cardápio resolve preço.
        $this->app->singleton(PrecificadorService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
