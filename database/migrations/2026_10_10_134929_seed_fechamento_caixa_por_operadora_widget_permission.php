<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Widget "Por marca da maquininha" do Relatório de Fechamento de Caixa
     * para o Gerente — mesmo padrão de 2026_10_07_103111 (repetido no
     * PermissionSeeder).
     */
    private string $permission = 'view:fechamento_caixa_por_operadora_widget';

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
