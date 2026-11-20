<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Executar ações pelo conector "PizzariaAPP Gestão" (G3): Admin
     * (Gate::before) e Gerente. Cada ação ainda exige a permissão da tela
     * correspondente; tirar esta permissão deixa o perfil só com perguntas.
     */
    private const PERMISSAO = 'executar_acoes:claude_gestao';

    public function up(): void
    {
        $permissao = Permission::firstOrCreate(['name' => self::PERMISSAO, 'guard_name' => 'web']);

        Role::query()->where('name', 'Gerente')->where('guard_name', 'web')->first()?->givePermissionTo($permissao);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', self::PERMISSAO)->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
