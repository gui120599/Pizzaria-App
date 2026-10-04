<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Página e widgets do Relatório de Taxa de Serviço (Filament Shield) para o
     * Gerente. Em migration (e repetido no PermissionSeeder) para chegar em
     * produção sem depender de rodar seeder no deploy.
     *
     * @var array<int, string>
     */
    private array $permissions = [
        'view:relatorio_taxa_servico',
        'view:taxa_servico_stats_overview',
        'view:taxa_servico_por_garcom_widget',
        'view:taxa_servico_detalhamento_widget',
    ];

    public function up(): void
    {
        foreach ($this->permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
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
