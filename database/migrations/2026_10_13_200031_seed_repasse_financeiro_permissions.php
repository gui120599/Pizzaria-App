<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Permissions de adiantamentos, repasses e contas financeiras (Admin passa
     * por Gate::before). O Gerente dá vale, vê saldos/extrato e transfere
     * entre contas. Ficam só com o Admin: cancelar vale (estorna dinheiro),
     * cadastrar/editar contas, ajustar saldo, estornar movimentação e ver
     * repasses (spec ponto §5.6 e contas financeiras §6).
     *
     * @var array<int, string>
     */
    private array $doGerente = [
        'view_any:adiantamento', 'view:adiantamento', 'create:adiantamento',
        'view_any:conta_financeira', 'view:conta_financeira', 'transferir:conta_financeira',
        'view_any:movimentacao_financeira', 'view:movimentacao_financeira',
    ];

    /** @var array<int, string> */
    private array $soAdmin = [
        'cancelar:adiantamento',
        'create:conta_financeira', 'update:conta_financeira', 'delete:conta_financeira', 'restore:conta_financeira',
        'ajustar_saldo:conta_financeira',
        'estornar:movimentacao_financeira',
        'view_any:repasse', 'view:repasse', 'update:repasse',
    ];

    public function up(): void
    {
        foreach ([...$this->doGerente, ...$this->soAdmin] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        Role::where('name', 'Gerente')->where('guard_name', 'web')->first()?->givePermissionTo($this->doGerente);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', [...$this->doGerente, ...$this->soAdmin])->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
