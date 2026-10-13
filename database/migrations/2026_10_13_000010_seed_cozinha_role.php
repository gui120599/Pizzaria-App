<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Equipe da cozinha: executa checklists no /garcom (sem acesso às telas de
     * mesa). Mesmo padrão de 2026_10_02_130332_seed_garcom_role.
     */
    public function up(): void
    {
        Role::firstOrCreate(['name' => 'Cozinha', 'guard_name' => 'web']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::where('name', 'Cozinha')->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
