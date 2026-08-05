<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * Nomes que hoje existem como Permission (seed em 2024_01_08_231206_create_permission_tables.php)
     * mas representam perfis de usuário e deveriam ser Role. A Permission antiga não é apagada aqui
     * de propósito — a limpeza acontece numa migration separada, só depois de todo o código parar
     * de referenciar esses nomes via middleware('permission:X')/@can('X').
     */
    private array $rolesFromPermissions = ['Cliente', 'Admin', 'Gerente', 'Atendente'];

    public function up(): void
    {
        DB::transaction(function () {
            foreach ($this->rolesFromPermissions as $name) {
                Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);

                $permission = Permission::where('name', $name)->where('guard_name', 'web')->first();

                if (! $permission) {
                    continue;
                }

                $userIds = DB::table('model_has_permissions')
                    ->where('permission_id', $permission->id)
                    ->where('model_type', User::class)
                    ->pluck('model_id');

                foreach ($userIds as $userId) {
                    User::find($userId)?->assignRole($name);
                }
            }

            // Caixa é role nova: hoje a operação de caixa fica presa a permission:Admin,
            // sem perfil próprio. Sem usuários pra migrar, só cria a role.
            Role::firstOrCreate(['name' => 'Caixa', 'guard_name' => 'web']);
        });

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        // Migração de correção de dados: reverter automaticamente removeria também
        // atribuições feitas manualmente depois do up() rodar. Se precisar desfazer,
        // conferir o estado atual antes de remover roles/atribuições manualmente.
    }
};
