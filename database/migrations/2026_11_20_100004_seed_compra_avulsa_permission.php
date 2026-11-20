<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Lançar compra avulsa pelo Claude (/mcp/compras-avulsa): Admin (Gate::before)
     * e Gerente, decisão de 2026-11-20. O papel Agente nunca recebe.
     */
    private const PERMISSAO = 'lancar:compra_avulsa';

    public function up(): void
    {
        $permissao = Permission::firstOrCreate(['name' => self::PERMISSAO, 'guard_name' => 'web']);

        Role::query()->where('name', 'Gerente')->where('guard_name', 'web')->first()?->givePermissionTo($permissao);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', self::PERMISSAO)->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
