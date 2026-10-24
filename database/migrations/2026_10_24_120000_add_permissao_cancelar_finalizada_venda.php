<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** Cancelar venda finalizada em lote (tabela de vendas): só o Gerente, ao contrário de cancel:venda que o Caixa também tem. */
    private string $permission = 'cancelar_finalizada:venda';

    public function up(): void
    {
        Permission::firstOrCreate(['name' => $this->permission, 'guard_name' => 'web']);

        Role::where('name', 'Gerente')->where('guard_name', 'web')->first()?->givePermissionTo($this->permission);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', $this->permission)->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
