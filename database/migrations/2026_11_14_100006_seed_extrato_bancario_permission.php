<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** Ver o extrato bancário importado e conciliar à mão: só Admin (Gate::before). */
    public function up(): void
    {
        Permission::firstOrCreate(['name' => 'view:extrato_bancario', 'guard_name' => 'web']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'view:extrato_bancario')->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
