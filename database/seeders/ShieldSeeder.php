<?php

namespace Database\Seeders;

use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

class ShieldSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $tenants = '[]';
        $users = '[]';
        $userTenantPivot = '[]';
        $rolesWithPermissions = '[{"name":"Entregador","guard_name":"web","permissions":["deliver:pedido"]},{"name":"Cliente","guard_name":"web","permissions":[]},{"name":"Admin","guard_name":"web","permissions":[]},{"name":"Gerente","guard_name":"web","permissions":["cancel:pedido","accept:pedido","reject:pedido","advance:pedido","deliver:pedido","estornar:pagamento","approve:lancamento","confirm:compra","abrir:sessao_caixa","fechar:sessao_caixa","view:relatorio_financeiro"]},{"name":"Atendente","guard_name":"web","permissions":["cancel:pedido","accept:pedido","reject:pedido","advance:pedido"]},{"name":"Caixa","guard_name":"web","permissions":["abrir:sessao_caixa","fechar:sessao_caixa"]}]';
        $directPermissions = '{"0":{"name":"Cliente","guard_name":"web"},"1":{"name":"Admin","guard_name":"web"},"2":{"name":"Gerente","guard_name":"web"},"3":{"name":"Atendente","guard_name":"web"},"4":{"name":"view_any:avaliacao_link","guard_name":"web"},"5":{"name":"view:avaliacao_link","guard_name":"web"},"6":{"name":"create:avaliacao_link","guard_name":"web"},"7":{"name":"update:avaliacao_link","guard_name":"web"},"8":{"name":"delete:avaliacao_link","guard_name":"web"},"9":{"name":"delete_any:avaliacao_link","guard_name":"web"},"10":{"name":"restore:avaliacao_link","guard_name":"web"},"11":{"name":"force_delete:avaliacao_link","guard_name":"web"},"12":{"name":"force_delete_any:avaliacao_link","guard_name":"web"},"13":{"name":"restore_any:avaliacao_link","guard_name":"web"},"14":{"name":"replicate:avaliacao_link","guard_name":"web"},"15":{"name":"reorder:avaliacao_link","guard_name":"web"},"16":{"name":"view_any:movimentacao_balanco","guard_name":"web"},"17":{"name":"view:movimentacao_balanco","guard_name":"web"},"18":{"name":"create:movimentacao_balanco","guard_name":"web"},"19":{"name":"update:movimentacao_balanco","guard_name":"web"},"20":{"name":"delete:movimentacao_balanco","guard_name":"web"},"21":{"name":"delete_any:movimentacao_balanco","guard_name":"web"},"22":{"name":"restore:movimentacao_balanco","guard_name":"web"},"23":{"name":"force_delete:movimentacao_balanco","guard_name":"web"},"24":{"name":"force_delete_any:movimentacao_balanco","guard_name":"web"},"25":{"name":"restore_any:movimentacao_balanco","guard_name":"web"},"26":{"name":"replicate:movimentacao_balanco","guard_name":"web"},"27":{"name":"reorder:movimentacao_balanco","guard_name":"web"},"28":{"name":"view_any:categoria","guard_name":"web"},"29":{"name":"view:categoria","guard_name":"web"},"30":{"name":"create:categoria","guard_name":"web"},"31":{"name":"update:categoria","guard_name":"web"},"32":{"name":"delete:categoria","guard_name":"web"},"33":{"name":"delete_any:categoria","guard_name":"web"},"34":{"name":"restore:categoria","guard_name":"web"},"35":{"name":"force_delete:categoria","guard_name":"web"},"36":{"name":"force_delete_any:categoria","guard_name":"web"},"37":{"name":"restore_any:categoria","guard_name":"web"},"38":{"name":"replicate:categoria","guard_name":"web"},"39":{"name":"reorder:categoria","guard_name":"web"},"40":{"name":"view_any:centro_custo","guard_name":"web"},"41":{"name":"view:centro_custo","guard_name":"web"},"42":{"name":"create:centro_custo","guard_name":"web"},"43":{"name":"update:centro_custo","guard_name":"web"},"44":{"name":"delete:centro_custo","guard_name":"web"},"45":{"name":"delete_any:centro_custo","guard_name":"web"},"46":{"name":"restore:centro_custo","guard_name":"web"},"47":{"name":"force_delete:centro_custo","guard_name":"web"},"48":{"name":"force_delete_any:centro_custo","guard_name":"web"},"49":{"name":"restore_any:centro_custo","guard_name":"web"},"50":{"name":"replicate:centro_custo","guard_name":"web"},"51":{"name":"reorder:centro_custo","guard_name":"web"},"52":{"name":"view_any:cliente","guard_name":"web"},"53":{"name":"view:cliente","guard_name":"web"},"54":{"name":"create:cliente","guard_name":"web"},"55":{"name":"update:cliente","guard_name":"web"},"56":{"name":"delete:cliente","guard_name":"web"},"57":{"name":"delete_any:cliente","guard_name":"web"},"58":{"name":"restore:cliente","guard_name":"web"},"59":{"name":"force_delete:cliente","guard_name":"web"},"60":{"name":"force_delete_any:cliente","guard_name":"web"},"61":{"name":"restore_any:cliente","guard_name":"web"},"62":{"name":"replicate:cliente","guard_name":"web"},"63":{"name":"reorder:cliente","guard_name":"web"},"64":{"name":"view_any:compra","guard_name":"web"},"65":{"name":"view:compra","guard_name":"web"},"66":{"name":"create:compra","guard_name":"web"},"67":{"name":"update:compra","guard_name":"web"},"68":{"name":"delete:compra","guard_name":"web"},"69":{"name":"delete_any:compra","guard_name":"web"},"70":{"name":"restore:compra","guard_name":"web"},"71":{"name":"force_delete:compra","guard_name":"web"},"72":{"name":"force_delete_any:compra","guard_name":"web"},"73":{"name":"restore_any:compra","guard_name":"web"},"74":{"name":"replicate:compra","guard_name":"web"},"75":{"name":"reorder:compra","guard_name":"web"},"76":{"name":"view_any:prestador","guard_name":"web"},"77":{"name":"view:prestador","guard_name":"web"},"78":{"name":"create:prestador","guard_name":"web"},"79":{"name":"update:prestador","guard_name":"web"},"80":{"name":"delete:prestador","guard_name":"web"},"81":{"name":"delete_any:prestador","guard_name":"web"},"82":{"name":"restore:prestador","guard_name":"web"},"83":{"name":"force_delete:prestador","guard_name":"web"},"84":{"name":"force_delete_any:prestador","guard_name":"web"},"85":{"name":"restore_any:prestador","guard_name":"web"},"86":{"name":"replicate:prestador","guard_name":"web"},"87":{"name":"reorder:prestador","guard_name":"web"},"88":{"name":"view_any:horario_funcionamento","guard_name":"web"},"89":{"name":"view:horario_funcionamento","guard_name":"web"},"90":{"name":"create:horario_funcionamento","guard_name":"web"},"91":{"name":"update:horario_funcionamento","guard_name":"web"},"92":{"name":"delete:horario_funcionamento","guard_name":"web"},"93":{"name":"delete_any:horario_funcionamento","guard_name":"web"},"94":{"name":"restore:horario_funcionamento","guard_name":"web"},"95":{"name":"force_delete:horario_funcionamento","guard_name":"web"},"96":{"name":"force_delete_any:horario_funcionamento","guard_name":"web"},"97":{"name":"restore_any:horario_funcionamento","guard_name":"web"},"98":{"name":"replicate:horario_funcionamento","guard_name":"web"},"99":{"name":"reorder:horario_funcionamento","guard_name":"web"},"100":{"name":"view_any:lancamento","guard_name":"web"},"101":{"name":"view:lancamento","guard_name":"web"},"102":{"name":"create:lancamento","guard_name":"web"},"103":{"name":"update:lancamento","guard_name":"web"},"104":{"name":"delete:lancamento","guard_name":"web"},"105":{"name":"delete_any:lancamento","guard_name":"web"},"106":{"name":"restore:lancamento","guard_name":"web"},"107":{"name":"force_delete:lancamento","guard_name":"web"},"108":{"name":"force_delete_any:lancamento","guard_name":"web"},"109":{"name":"restore_any:lancamento","guard_name":"web"},"110":{"name":"replicate:lancamento","guard_name":"web"},"111":{"name":"reorder:lancamento","guard_name":"web"},"112":{"name":"view_any:marca","guard_name":"web"},"113":{"name":"view:marca","guard_name":"web"},"114":{"name":"create:marca","guard_name":"web"},"115":{"name":"update:marca","guard_name":"web"},"116":{"name":"delete:marca","guard_name":"web"},"117":{"name":"delete_any:marca","guard_name":"web"},"118":{"name":"restore:marca","guard_name":"web"},"119":{"name":"force_delete:marca","guard_name":"web"},"120":{"name":"force_delete_any:marca","guard_name":"web"},"121":{"name":"restore_any:marca","guard_name":"web"},"122":{"name":"replicate:marca","guard_name":"web"},"123":{"name":"reorder:marca","guard_name":"web"},"124":{"name":"view_any:movimentacao_produto","guard_name":"web"},"125":{"name":"view:movimentacao_produto","guard_name":"web"},"126":{"name":"create:movimentacao_produto","guard_name":"web"},"127":{"name":"update:movimentacao_produto","guard_name":"web"},"128":{"name":"delete:movimentacao_produto","guard_name":"web"},"129":{"name":"delete_any:movimentacao_produto","guard_name":"web"},"130":{"name":"restore:movimentacao_produto","guard_name":"web"},"131":{"name":"force_delete:movimentacao_produto","guard_name":"web"},"132":{"name":"force_delete_any:movimentacao_produto","guard_name":"web"},"133":{"name":"restore_any:movimentacao_produto","guard_name":"web"},"134":{"name":"replicate:movimentacao_produto","guard_name":"web"},"135":{"name":"reorder:movimentacao_produto","guard_name":"web"},"136":{"name":"view_any:opcoes_entregas","guard_name":"web"},"137":{"name":"view:opcoes_entregas","guard_name":"web"},"138":{"name":"create:opcoes_entregas","guard_name":"web"},"139":{"name":"update:opcoes_entregas","guard_name":"web"},"140":{"name":"delete:opcoes_entregas","guard_name":"web"},"141":{"name":"delete_any:opcoes_entregas","guard_name":"web"},"142":{"name":"restore:opcoes_entregas","guard_name":"web"},"143":{"name":"force_delete:opcoes_entregas","guard_name":"web"},"144":{"name":"force_delete_any:opcoes_entregas","guard_name":"web"},"145":{"name":"restore_any:opcoes_entregas","guard_name":"web"},"146":{"name":"replicate:opcoes_entregas","guard_name":"web"},"147":{"name":"reorder:opcoes_entregas","guard_name":"web"},"148":{"name":"view_any:opcoes_pagamento","guard_name":"web"},"149":{"name":"view:opcoes_pagamento","guard_name":"web"},"150":{"name":"create:opcoes_pagamento","guard_name":"web"},"151":{"name":"update:opcoes_pagamento","guard_name":"web"},"152":{"name":"delete:opcoes_pagamento","guard_name":"web"},"153":{"name":"delete_any:opcoes_pagamento","guard_name":"web"},"154":{"name":"restore:opcoes_pagamento","guard_name":"web"},"155":{"name":"force_delete:opcoes_pagamento","guard_name":"web"},"156":{"name":"force_delete_any:opcoes_pagamento","guard_name":"web"},"157":{"name":"restore_any:opcoes_pagamento","guard_name":"web"},"158":{"name":"replicate:opcoes_pagamento","guard_name":"web"},"159":{"name":"reorder:opcoes_pagamento","guard_name":"web"},"160":{"name":"view_any:pedido","guard_name":"web"},"161":{"name":"view:pedido","guard_name":"web"},"162":{"name":"create:pedido","guard_name":"web"},"163":{"name":"update:pedido","guard_name":"web"},"164":{"name":"delete:pedido","guard_name":"web"},"165":{"name":"delete_any:pedido","guard_name":"web"},"166":{"name":"restore:pedido","guard_name":"web"},"167":{"name":"force_delete:pedido","guard_name":"web"},"168":{"name":"force_delete_any:pedido","guard_name":"web"},"169":{"name":"restore_any:pedido","guard_name":"web"},"170":{"name":"replicate:pedido","guard_name":"web"},"171":{"name":"reorder:pedido","guard_name":"web"},"172":{"name":"view_any:plano_despesa","guard_name":"web"},"173":{"name":"view:plano_despesa","guard_name":"web"},"174":{"name":"create:plano_despesa","guard_name":"web"},"175":{"name":"update:plano_despesa","guard_name":"web"},"176":{"name":"delete:plano_despesa","guard_name":"web"},"177":{"name":"delete_any:plano_despesa","guard_name":"web"},"178":{"name":"restore:plano_despesa","guard_name":"web"},"179":{"name":"force_delete:plano_despesa","guard_name":"web"},"180":{"name":"force_delete_any:plano_despesa","guard_name":"web"},"181":{"name":"restore_any:plano_despesa","guard_name":"web"},"182":{"name":"replicate:plano_despesa","guard_name":"web"},"183":{"name":"reorder:plano_despesa","guard_name":"web"},"184":{"name":"view_any:plano_receita","guard_name":"web"},"185":{"name":"view:plano_receita","guard_name":"web"},"186":{"name":"create:plano_receita","guard_name":"web"},"187":{"name":"update:plano_receita","guard_name":"web"},"188":{"name":"delete:plano_receita","guard_name":"web"},"189":{"name":"delete_any:plano_receita","guard_name":"web"},"190":{"name":"restore:plano_receita","guard_name":"web"},"191":{"name":"force_delete:plano_receita","guard_name":"web"},"192":{"name":"force_delete_any:plano_receita","guard_name":"web"},"193":{"name":"restore_any:plano_receita","guard_name":"web"},"194":{"name":"replicate:plano_receita","guard_name":"web"},"195":{"name":"reorder:plano_receita","guard_name":"web"},"196":{"name":"view_any:produto","guard_name":"web"},"197":{"name":"view:produto","guard_name":"web"},"198":{"name":"create:produto","guard_name":"web"},"199":{"name":"update:produto","guard_name":"web"},"200":{"name":"delete:produto","guard_name":"web"},"201":{"name":"delete_any:produto","guard_name":"web"},"202":{"name":"restore:produto","guard_name":"web"},"203":{"name":"force_delete:produto","guard_name":"web"},"204":{"name":"force_delete_any:produto","guard_name":"web"},"205":{"name":"restore_any:produto","guard_name":"web"},"206":{"name":"replicate:produto","guard_name":"web"},"207":{"name":"reorder:produto","guard_name":"web"},"208":{"name":"view_any:promocao_relampago","guard_name":"web"},"209":{"name":"view:promocao_relampago","guard_name":"web"},"210":{"name":"create:promocao_relampago","guard_name":"web"},"211":{"name":"update:promocao_relampago","guard_name":"web"},"212":{"name":"delete:promocao_relampago","guard_name":"web"},"213":{"name":"delete_any:promocao_relampago","guard_name":"web"},"214":{"name":"restore:promocao_relampago","guard_name":"web"},"215":{"name":"force_delete:promocao_relampago","guard_name":"web"},"216":{"name":"force_delete_any:promocao_relampago","guard_name":"web"},"217":{"name":"restore_any:promocao_relampago","guard_name":"web"},"218":{"name":"replicate:promocao_relampago","guard_name":"web"},"219":{"name":"reorder:promocao_relampago","guard_name":"web"},"220":{"name":"view_any:user","guard_name":"web"},"221":{"name":"view:user","guard_name":"web"},"222":{"name":"create:user","guard_name":"web"},"223":{"name":"update:user","guard_name":"web"},"224":{"name":"delete:user","guard_name":"web"},"225":{"name":"delete_any:user","guard_name":"web"},"226":{"name":"restore:user","guard_name":"web"},"227":{"name":"force_delete:user","guard_name":"web"},"228":{"name":"force_delete_any:user","guard_name":"web"},"229":{"name":"restore_any:user","guard_name":"web"},"230":{"name":"replicate:user","guard_name":"web"},"231":{"name":"reorder:user","guard_name":"web"},"232":{"name":"view_any:venda","guard_name":"web"},"233":{"name":"view:venda","guard_name":"web"},"234":{"name":"create:venda","guard_name":"web"},"235":{"name":"update:venda","guard_name":"web"},"236":{"name":"delete:venda","guard_name":"web"},"237":{"name":"delete_any:venda","guard_name":"web"},"238":{"name":"restore:venda","guard_name":"web"},"239":{"name":"force_delete:venda","guard_name":"web"},"240":{"name":"force_delete_any:venda","guard_name":"web"},"241":{"name":"restore_any:venda","guard_name":"web"},"242":{"name":"replicate:venda","guard_name":"web"},"243":{"name":"reorder:venda","guard_name":"web"},"244":{"name":"view:dashboard","guard_name":"web"},"245":{"name":"view:financeiro_dashboard","guard_name":"web"},"246":{"name":"view:pedidos_dashboard","guard_name":"web"},"247":{"name":"view:acesso_rapido_widget","guard_name":"web"},"248":{"name":"view:faturamento_por_dia_chart","guard_name":"web"},"249":{"name":"view:faturamento_por_tipo_entrega_chart","guard_name":"web"},"250":{"name":"view:financeiro_stats_overview","guard_name":"web"},"251":{"name":"view:formas_pagamento_chart","guard_name":"web"},"252":{"name":"view:motivos_cancelamento_chart","guard_name":"web"},"253":{"name":"view:pedidos_por_dia_semana_chart","guard_name":"web"},"254":{"name":"view:pedidos_por_hora_chart","guard_name":"web"},"255":{"name":"view:pedidos_por_origem_chart","guard_name":"web"},"256":{"name":"view:pedidos_stats_overview","guard_name":"web"},"257":{"name":"view:produtos_vendidos_por_periodo_chart","guard_name":"web"},"258":{"name":"view:resumo_operacional_widget","guard_name":"web"},"259":{"name":"view:top_produtos_vendidos","guard_name":"web"},"271":{"name":"view_any:role","guard_name":"web"},"272":{"name":"view:role","guard_name":"web"},"273":{"name":"create:role","guard_name":"web"},"274":{"name":"update:role","guard_name":"web"},"275":{"name":"delete:role","guard_name":"web"},"276":{"name":"delete_any:role","guard_name":"web"},"277":{"name":"restore:role","guard_name":"web"},"278":{"name":"force_delete:role","guard_name":"web"},"279":{"name":"force_delete_any:role","guard_name":"web"},"280":{"name":"restore_any:role","guard_name":"web"},"281":{"name":"replicate:role","guard_name":"web"},"282":{"name":"reorder:role","guard_name":"web"}}';

        // 1. Seed tenants first (if present)
        if (! blank($tenants) && $tenants !== '[]') {
            static::seedTenants($tenants);
        }

        // 2. Seed roles with permissions
        static::makeRolesWithPermissions($rolesWithPermissions);

        // 3. Seed direct permissions
        static::makeDirectPermissions($directPermissions);

        // 4. Seed users with their roles/permissions (if present)
        if (! blank($users) && $users !== '[]') {
            static::seedUsers($users);
        }

        // 5. Seed user-tenant pivot (if present)
        if (! blank($userTenantPivot) && $userTenantPivot !== '[]') {
            static::seedUserTenantPivot($userTenantPivot);
        }

        $this->command->info('Shield Seeding Completed.');
    }

    protected static function seedTenants(string $tenants): void
    {
        if (blank($tenantData = json_decode($tenants, true))) {
            return;
        }

        $tenantModel = '';
        if (blank($tenantModel)) {
            return;
        }

        foreach ($tenantData as $tenant) {
            $tenantModel::firstOrCreate(
                ['id' => $tenant['id']],
                $tenant
            );
        }
    }

    protected static function seedUsers(string $users): void
    {
        if (blank($userData = json_decode($users, true))) {
            return;
        }

        $userModel = 'App\Models\User';
        $tenancyEnabled = false;

        foreach ($userData as $data) {
            // Extract role/permission data before creating user
            $roles = $data['roles'] ?? [];
            $permissions = $data['permissions'] ?? [];
            $tenantRoles = $data['tenant_roles'] ?? [];
            $tenantPermissions = $data['tenant_permissions'] ?? [];
            unset($data['roles'], $data['permissions'], $data['tenant_roles'], $data['tenant_permissions']);

            $user = $userModel::firstOrCreate(
                ['email' => $data['email']],
                $data
            );

            // Handle tenancy mode - sync roles/permissions per tenant
            if ($tenancyEnabled && (! empty($tenantRoles) || ! empty($tenantPermissions))) {
                foreach ($tenantRoles as $tenantId => $roleNames) {
                    $contextId = $tenantId === '_global' ? null : $tenantId;
                    setPermissionsTeamId($contextId);
                    $user->syncRoles($roleNames);
                }

                foreach ($tenantPermissions as $tenantId => $permissionNames) {
                    $contextId = $tenantId === '_global' ? null : $tenantId;
                    setPermissionsTeamId($contextId);
                    $user->syncPermissions($permissionNames);
                }
            } else {
                // Non-tenancy mode
                if (! empty($roles)) {
                    $user->syncRoles($roles);
                }

                if (! empty($permissions)) {
                    $user->syncPermissions($permissions);
                }
            }
        }
    }

    protected static function seedUserTenantPivot(string $pivot): void
    {
        if (blank($pivotData = json_decode($pivot, true))) {
            return;
        }

        $pivotTable = '';
        if (blank($pivotTable)) {
            return;
        }

        foreach ($pivotData as $row) {
            $uniqueKeys = [];

            if (isset($row['user_id'])) {
                $uniqueKeys['user_id'] = $row['user_id'];
            }

            $tenantForeignKey = 'team_id';
            if (! blank($tenantForeignKey) && isset($row[$tenantForeignKey])) {
                $uniqueKeys[$tenantForeignKey] = $row[$tenantForeignKey];
            }

            if (! empty($uniqueKeys)) {
                DB::table($pivotTable)->updateOrInsert($uniqueKeys, $row);
            }
        }
    }

    protected static function makeRolesWithPermissions(string $rolesWithPermissions): void
    {
        if (blank($rolePlusPermissions = json_decode($rolesWithPermissions, true))) {
            return;
        }

        /** @var \Illuminate\Database\Eloquent\Model $roleModel */
        $roleModel = Utils::getRoleModel();
        /** @var \Illuminate\Database\Eloquent\Model $permissionModel */
        $permissionModel = Utils::getPermissionModel();

        $tenancyEnabled = false;
        $teamForeignKey = 'team_id';

        foreach ($rolePlusPermissions as $rolePlusPermission) {
            $tenantId = $rolePlusPermission[$teamForeignKey] ?? null;

            // Set tenant context for role creation and permission sync
            if ($tenancyEnabled) {
                setPermissionsTeamId($tenantId);
            }

            $roleData = [
                'name' => $rolePlusPermission['name'],
                'guard_name' => $rolePlusPermission['guard_name'],
            ];

            // Include tenant ID in role data (can be null for global roles)
            if ($tenancyEnabled && ! blank($teamForeignKey)) {
                $roleData[$teamForeignKey] = $tenantId;
            }

            $role = $roleModel::firstOrCreate($roleData);

            if (! blank($rolePlusPermission['permissions'])) {
                $permissionModels = collect($rolePlusPermission['permissions'])
                    ->map(fn ($permission) => $permissionModel::firstOrCreate([
                        'name' => $permission,
                        'guard_name' => $rolePlusPermission['guard_name'],
                    ]))
                    ->all();

                $role->syncPermissions($permissionModels);
            }
        }
    }

    public static function makeDirectPermissions(string $directPermissions): void
    {
        if (blank($permissions = json_decode($directPermissions, true))) {
            return;
        }

        /** @var \Illuminate\Database\Eloquent\Model $permissionModel */
        $permissionModel = Utils::getPermissionModel();

        foreach ($permissions as $permission) {
            if ($permissionModel::whereName($permission['name'])->doesntExist()) {
                $permissionModel::create([
                    'name' => $permission['name'],
                    'guard_name' => $permission['guard_name'],
                ]);
            }
        }
    }
}
