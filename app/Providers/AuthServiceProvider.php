<?php

namespace App\Providers;

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
        //
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        //
    }
}
