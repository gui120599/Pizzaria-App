<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Automação de compras: editar os parâmetros de automação do fornecedor
     * (só Admin, que passa por Gate::before) e tratar as pendências.
     */
    private const PERMISSOES = ['configurar:automacao_compra', 'resolver:pendencia_compra'];

    public function up(): void
    {
        foreach (self::PERMISSOES as $nome) {
            Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', self::PERMISSOES)->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
