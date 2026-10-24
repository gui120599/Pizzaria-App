<?php

namespace App\Providers;

use App\Models\PagamentoComissao;
use App\Policies\ResgateCarteiraPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Policies de App\Models\X são descobertas automaticamente (convenção
     * App\Models\X -> App\Policies\XPolicy). RolePolicy (Spatie\Permission\Models\Role)
     * já é registrada pelo próprio FilamentShieldServiceProvider (config
     * filament-shield.register_role_policy), não precisa duplicar aqui.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        // Temporário: a Fase 2 renomeia PagamentoComissao para ResgateCarteira
        // (descoberta automática passa a valer e esta linha sai).
        PagamentoComissao::class => ResgateCarteiraPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        //
    }
}
