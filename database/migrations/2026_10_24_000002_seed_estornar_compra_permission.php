<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Estornar uma compra confirmada (devolve o estoque e cancela os títulos).
     * Só cria a permissão: Admin já passa por Gate::before e quem mais puder
     * estornar recebe pela tela de perfis.
     */
    public function up(): void
    {
        Permission::firstOrCreate(['name' => 'estornar:compra', 'guard_name' => 'web']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'estornar:compra')->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
