<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Permissions do módulo de ponto (Admin passa por Gate::before). O Gerente
     * recebe cadastro de colaborador/escala, espelho, correção e aprovação de
     * ajustes. Ficam só com o Admin: ver remuneração, relatório de correções,
     * fechar período e aprovar repasse (spec §5.6). Mesmo padrão de
     * 2026_10_13_000011_seed_checklist_permissions.
     *
     * @var array<int, string>
     */
    private array $doGerente = [
        'view_any:colaborador', 'view:colaborador', 'create:colaborador', 'update:colaborador',
        'delete:colaborador', 'restore:colaborador',
        'view_any:escala', 'view:escala', 'create:escala', 'update:escala', 'delete:escala', 'restore:escala',
        'view_any:feriado', 'view:feriado', 'create:feriado', 'update:feriado', 'delete:feriado',
        'view_any:ponto_ajuste', 'view:ponto_ajuste', 'aprovar:ponto_ajuste',
        'view_espelho:ponto', 'corrigir:ponto',
    ];

    /** @var array<int, string> */
    private array $soAdmin = [
        'view_remuneracao:colaborador',
        'view:relatorio_correcoes_ponto',
        'fechar:ponto_periodo',
        'aprovar:repasse',
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
