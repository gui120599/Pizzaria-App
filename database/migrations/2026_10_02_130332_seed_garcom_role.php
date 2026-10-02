<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Role do Painel do Garçom e as permissões de ações sensíveis do salão.
     * Feito em migration (e repetido no PermissionSeeder) para chegar em
     * produção sem depender de rodar seeder no deploy.
     *
     * @var array<string, array<int, string>>
     */
    private array $permissionsPorRole = [
        'Garcom' => [
            'view:sessao_mesa', 'view_any:sessao_mesa', 'create:sessao_mesa',
            'view:pedido', 'view_any:pedido', 'create:pedido', 'update:pedido',
            'cancel:pedido', 'advance:pedido',
            'create:cliente', 'update:cliente',
        ],
        'Gerente' => [
            'cancelar_item:pedido', 'remover_taxa:sessao_mesa', 'transferir:sessao_mesa',
        ],
    ];

    public function up(): void
    {
        Role::firstOrCreate(['name' => 'Garcom', 'guard_name' => 'web']);

        foreach ($this->permissionsPorRole as $roleName => $permissions) {
            foreach ($permissions as $name) {
                Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            }

            Role::where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::where('name', 'Garcom')->where('guard_name', 'web')->delete();
        Permission::whereIn('name', ['cancelar_item:pedido', 'remover_taxa:sessao_mesa', 'transferir:sessao_mesa'])
            ->where('guard_name', 'web')
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
