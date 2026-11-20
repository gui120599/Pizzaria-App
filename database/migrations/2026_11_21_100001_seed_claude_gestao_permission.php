<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Usar o conector "PizzariaAPP Gestão" no Claude (/mcp/gestao): Admin
     * (Gate::before) e Gerente. Cada tool ainda exige a permissão da tela
     * correspondente; o papel Agente nunca recebe.
     */
    private const PERMISSAO = 'usar:claude_gestao';

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
