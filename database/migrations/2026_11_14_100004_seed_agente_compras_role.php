<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Papel do usuário técnico do agente de compras (servidor MCP). Não está
     * em User::PANEL_ROLES: quem tem só este papel não entra no painel.
     */
    public function up(): void
    {
        Role::firstOrCreate(['name' => 'Agente', 'guard_name' => 'web']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::where('name', 'Agente')->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
