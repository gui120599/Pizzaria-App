<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Liquidar e conciliar recebíveis de maquininha para o Gerente — mesmo
     * padrão de 2026_10_10_134929 (repetido no PermissionSeeder).
     *
     * @var array<int, string>
     */
    private array $permissions = ['liquidar_recebivel:lancamento', 'conciliar:lancamento', 'view:recebiveis_adquirentes_widget', 'view:fechamento_caixa_contabilizacao_widget'];

    public function up(): void
    {
        foreach ($this->permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        Role::where('name', 'Gerente')->where('guard_name', 'web')->first()?->givePermissionTo($this->permissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', $this->permissions)->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
