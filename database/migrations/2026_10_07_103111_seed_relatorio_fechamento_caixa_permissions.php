<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Página e widgets do Relatório de Fechamento de Caixa (Filament Shield)
     * para o Gerente. Em migration (e repetido no PermissionSeeder) para chegar
     * em produção sem depender de rodar seeder no deploy.
     *
     * @var array<int, string>
     */
    private array $permissions = [
        'view:relatorio_fechamento_caixa',
        'view:fechamento_caixa_stats_overview',
        'view:fechamento_caixa_dre_widget',
        'view:fechamento_caixa_fluxo_caixa_widget',
        'view:fechamento_caixa_formas_pagamento_widget',
        'view:fechamento_caixa_por_maquininha_widget',
        'view:fechamento_caixa_maquininhas_widget',
        'view:fechamento_caixa_bandeiras_widget',
        'view:fechamento_caixa_previsao_recebimento_widget',
        'view:fechamento_caixa_movimentacoes_widget',
        'view:fechamento_caixa_conferencia_widget',
        'view:fechamento_caixa_conferencia_maquininhas_widget',
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
