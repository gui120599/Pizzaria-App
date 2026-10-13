<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Permissions do módulo de checklists para o Gerente (Admin passa por
     * Gate::before). Mesmo padrão de 2026_10_11_115025 (repetido no
     * PermissionSeeder). Executar checklist não é permission: depende do papel
     * atribuído ao modelo (checklist_modelo_papeis).
     *
     * @var array<int, string>
     */
    private array $permissions = [
        'view_any:checklist_modelo', 'view:checklist_modelo', 'create:checklist_modelo',
        'update:checklist_modelo', 'delete:checklist_modelo', 'delete_any:checklist_modelo',
        'restore:checklist_modelo', 'restore_any:checklist_modelo',
        'view_any:checklist_execucao', 'view:checklist_execucao', 'aprovar:checklist_execucao',
        'view_any:checklist_nao_conformidade', 'view:checklist_nao_conformidade', 'update:checklist_nao_conformidade',
        'view:checklists_dashboard', 'view:checklists_hoje_widget',
        'view:checklist_conformidade_stats_overview', 'view:checklist_itens_mais_falham_widget',
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
