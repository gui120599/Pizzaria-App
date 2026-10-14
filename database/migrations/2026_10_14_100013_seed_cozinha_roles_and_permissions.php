<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Papéis e permissions do módulo de cozinha (Admin passa por
     * Gate::before). Repetido no PermissionSeeder, mesmo padrão de
     * 2026_10_13_000011_seed_checklist_permissions.
     *
     * - Expedicao: o passe (despacha, refaz).
     * - EstacaoCozinha: conta técnica dos tablets; a pessoa é o operador.
     *
     * @var array<string, list<string>>
     */
    private array $porRole = [
        'Gerente' => [
            'view_any:setor_producao', 'view:setor_producao', 'create:setor_producao', 'update:setor_producao',
            'delete:setor_producao', 'delete_any:setor_producao', 'restore:setor_producao', 'restore_any:setor_producao', 'reorder:setor_producao',
            'view_any:ficha_tecnica', 'view:ficha_tecnica', 'create:ficha_tecnica', 'update:ficha_tecnica',
            'delete:ficha_tecnica', 'delete_any:ficha_tecnica', 'restore:ficha_tecnica', 'restore_any:ficha_tecnica',
            'view_any:dispositivo_cozinha', 'view:dispositivo_cozinha', 'create:dispositivo_cozinha', 'update:dispositivo_cozinha',
            'delete:dispositivo_cozinha', 'delete_any:dispositivo_cozinha', 'parear:dispositivo_cozinha',
            'view_any:producao_item', 'produzir:producao_item', 'despachar:producao_item', 'refazer:producao_item',
            'priorizar:producao_item', 'forcar:producao_item', 'liberar_sem_abertura:producao_item',
            'view:relatorio_producao',
        ],
        'Cozinha' => ['view_any:ficha_tecnica', 'view:ficha_tecnica', 'produzir:producao_item'],
        'Expedicao' => ['view_any:ficha_tecnica', 'view:ficha_tecnica', 'produzir:producao_item', 'despachar:producao_item', 'refazer:producao_item'],
        // view:checklist_execucao: sem operador, o tablet abre o checklist só para leitura.
        'EstacaoCozinha' => ['view_any:ficha_tecnica', 'view:ficha_tecnica', 'produzir:producao_item', 'view:checklist_execucao'],
    ];

    public function up(): void
    {
        foreach (['Cozinha', 'Expedicao', 'EstacaoCozinha'] as $papel) {
            Role::firstOrCreate(['name' => $papel, 'guard_name' => 'web']);
        }

        foreach ($this->porRole as $papel => $permissions) {
            foreach ($permissions as $name) {
                Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            }

            Role::where('name', $papel)->where('guard_name', 'web')->first()?->givePermissionTo($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // view:checklist_execucao é do módulo de checklists: fica.
        $permissions = collect($this->porRole)->flatten()->unique()->reject(fn (string $p) => $p === 'view:checklist_execucao')->all();

        Permission::whereIn('name', $permissions)->where('guard_name', 'web')->delete();
        // Cozinha é do módulo de checklists (2026_10_13_000010) e fica.
        Role::whereIn('name', ['Expedicao', 'EstacaoCozinha'])->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
