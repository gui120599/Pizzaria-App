<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Perfis de usuário do sistema. Idempotente (firstOrCreate) para poder
     * rodar em qualquer ambiente sem duplicar o que a migration de dados
     * 2026_07_23_000001_reclassify_permissions_to_roles.php já criou.
     */
    private array $roles = [
        'Admin',
        'Gerente',
        'Atendente',
        'Caixa',
        'Entregador',
        'Cliente',
    ];

    public function run(): void
    {
        foreach ($this->roles as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }
}
