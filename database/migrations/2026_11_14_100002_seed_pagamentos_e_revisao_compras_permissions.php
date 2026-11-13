<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Confirmar em lote os pagamentos das compras automatizadas (Gerente) e
     * revisar produtos cadastrados pelo agente (só Admin, via Gate::before).
     */
    private const PERMISSOES = ['confirmar:pagamento_compra', 'revisar:produto_automacao'];

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
