<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Página e widgets do Relatório de Sessão de Caixa (Filament Shield) e a
     * correção de lançamentos (corrigir:pagamento_venda) para o Gerente —
     * mesmo padrão de 2026_10_07_103111 (repetido no PermissionSeeder).
     *
     * @var array<int, string>
     */
    private array $permissions = [
        'view:relatorio_sessao_caixa',
        'view:sessao_caixa_stats_overview',
        'view:sessao_caixa_vendas_widget',
        'view:sessao_caixa_fiado_widget',
        'corrigir:pagamento_venda',
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
