<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

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
        // Compra avulsa pelo Claude (Fase 5). A permissão é do guard "web", mas a
        // chamada MCP chega autenticada pelo guard "api" (Passport): por isso a
        // checagem informa o guard. O papel Agente nunca passa (Admin já passa
        // antes, pelo Gate::before do Shield).
        Gate::define('compras.avulsa', fn (User $usuario): bool => ! $usuario->hasRole('Agente')
            && self::temPermissaoWeb($usuario, 'lancar:compra_avulsa'));
    }

    private static function temPermissaoWeb(User $usuario, string $permissao): bool
    {
        try {
            return $usuario->hasPermissionTo($permissao, 'web');
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
