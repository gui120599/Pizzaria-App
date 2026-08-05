<?php

namespace App\Providers;

use App\Models\Empresa;
use App\Services\PrecificadorService;
use App\Services\Sefaz\Contracts\SefazClient;
use App\Services\Sefaz\SefazNfephpClient;
use App\Services\Sefaz\SefazRespostaDecoder;
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

        // A empresa é tratada como singleton em todo o sistema (Empresa::first()),
        // então o client SEFAZ sempre monta pra essa mesma empresa. Testes trocam
        // esse binding por um fake (ver App\Services\Sefaz\Contracts\SefazClient).
        $this->app->bind(SefazClient::class, fn ($app) => new SefazNfephpClient(
            Empresa::firstOrFail(),
            $app->make(SefazRespostaDecoder::class),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
