<?php

namespace Database\Seeders;

use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Database\Eloquent\Model;
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
        $rolesWithPermissions = '[{"name":"Entregador","guard_name":"web","permissions":["deliver:pedido"]},{"name":"Cliente","guard_name":"web","permissions":[]},{"name":"Admin","guard_name":"web","permissions":[]},{"name":"Gerente","guard_name":"web","permissions":["view_any:avaliacao_link","view:avaliacao_link","create:avaliacao_link","update:avaliacao_link","delete:avaliacao_link","view_any:movimentacao_balanco","view:movimentacao_balanco","create:movimentacao_balanco","update:movimentacao_balanco","delete:movimentacao_balanco","view_any:categoria","view:categoria","create:categoria","update:categoria","delete:categoria","view_any:centro_custo","view:centro_custo","create:centro_custo","update:centro_custo","delete:centro_custo","view_any:cliente","view:cliente","create:cliente","update:cliente","delete:cliente","view_any:compra","view:compra","create:compra","update:compra","view_any:prestador","view:prestador","create:prestador","update:prestador","delete:prestador","view_any:horario_funcionamento","view:horario_funcionamento","create:horario_funcionamento","update:horario_funcionamento","delete:horario_funcionamento","view_any:lancamento","view:lancamento","create:lancamento","update:lancamento","view_any:marca","view:marca","create:marca","update:marca","delete:marca","view_any:movimentacao_produto","view:movimentacao_produto","view_any:opcoes_entregas","view:opcoes_entregas","create:opcoes_entregas","update:opcoes_entregas","delete:opcoes_entregas","view_any:opcoes_pagamento","view:opcoes_pagamento","create:opcoes_pagamento","update:opcoes_pagamento","delete:opcoes_pagamento","view_any:pedido","view:pedido","create:pedido","update:pedido","view_any:plano_despesa","view:plano_despesa","create:plano_despesa","update:plano_despesa","delete:plano_despesa","view_any:plano_receita","view:plano_receita","create:plano_receita","update:plano_receita","delete:plano_receita","view_any:produto","view:produto","create:produto","update:produto","delete:produto","view_any:promocao_relampago","view:promocao_relampago","create:promocao_relampago","update:promocao_relampago","delete:promocao_relampago","view_any:venda","view:venda","create:venda","update:venda","view:financeiro_dashboard","view:pedidos_dashboard","view:faturamento_por_dia_chart","view:faturamento_por_tipo_entrega_chart","view:financeiro_stats_overview","view:formas_pagamento_chart","view:motivos_cancelamento_chart","view:pedidos_por_dia_semana_chart","view:pedidos_por_hora_chart","view:pedidos_por_origem_chart","view:pedidos_stats_overview","view:produtos_vendidos_por_periodo_chart","view:top_produtos_vendidos","deliver:pedido","cancel:pedido","accept:pedido","reject:pedido","advance:pedido","estornar:pagamento","approve:lancamento","confirm:compra","abrir:sessao_caixa","fechar:sessao_caixa","view:relatorio_financeiro","view:sessao_mesa","operar:venda","cancel:venda","emitir:nfe","create:mesa","update:mesa","delete:mesa","create:empresa","update:empresa","delete:empresa","create:caixa","update:caixa","delete:caixa","create:adicional","update:adicional","delete:adicional","view_any:nota_fiscal","view:nota_fiscal","corrigir:movimentacao_estoque","view_any:sessao_caixa","view:sessao_caixa","create:sessao_caixa","update:sessao_caixa","view_any:mesa","view:mesa","restore:mesa","view_any:sessao_mesa","create:sessao_mesa"]},{"name":"Atendente","guard_name":"web","permissions":["view_any:categoria","view:categoria","view_any:cliente","view:cliente","create:cliente","update:cliente","view_any:pedido","view:pedido","create:pedido","update:pedido","view_any:produto","view:produto","view_any:venda","view:venda","view:pedidos_dashboard","view:motivos_cancelamento_chart","view:pedidos_por_dia_semana_chart","view:pedidos_por_hora_chart","view:pedidos_por_origem_chart","view:pedidos_stats_overview","cancel:pedido","accept:pedido","reject:pedido","advance:pedido","view:sessao_mesa","operar:venda","view_any:mesa","view:mesa","view_any:sessao_mesa","create:sessao_mesa"]},{"name":"Caixa","guard_name":"web","permissions":["view_any:cliente","view:cliente","view_any:pedido","view:pedido","view_any:venda","view:venda","view:pedidos_dashboard","view:motivos_cancelamento_chart","view:pedidos_por_dia_semana_chart","view:pedidos_por_hora_chart","view:pedidos_por_origem_chart","view:pedidos_stats_overview","abrir:sessao_caixa","fechar:sessao_caixa","view:sessao_mesa","operar:venda","cancel:venda","emitir:nfe","view_any:sessao_caixa","view:sessao_caixa","create:sessao_caixa","update:sessao_caixa","view_any:mesa","view:mesa","view_any:sessao_mesa","create:sessao_mesa"]}]';
        $directPermissions = '{"0":{"name":"Cliente","guard_name":"web"},"1":{"name":"Admin","guard_name":"web"},"2":{"name":"Gerente","guard_name":"web"},"3":{"name":"Atendente","guard_name":"web"},"9":{"name":"delete_any:avaliacao_link","guard_name":"web"},"10":{"name":"restore:avaliacao_link","guard_name":"web"},"11":{"name":"force_delete:avaliacao_link","guard_name":"web"},"12":{"name":"force_delete_any:avaliacao_link","guard_name":"web"},"13":{"name":"restore_any:avaliacao_link","guard_name":"web"},"14":{"name":"replicate:avaliacao_link","guard_name":"web"},"15":{"name":"reorder:avaliacao_link","guard_name":"web"},"21":{"name":"delete_any:movimentacao_balanco","guard_name":"web"},"22":{"name":"restore:movimentacao_balanco","guard_name":"web"},"23":{"name":"force_delete:movimentacao_balanco","guard_name":"web"},"24":{"name":"force_delete_any:movimentacao_balanco","guard_name":"web"},"25":{"name":"restore_any:movimentacao_balanco","guard_name":"web"},"26":{"name":"replicate:movimentacao_balanco","guard_name":"web"},"27":{"name":"reorder:movimentacao_balanco","guard_name":"web"},"33":{"name":"delete_any:categoria","guard_name":"web"},"34":{"name":"restore:categoria","guard_name":"web"},"35":{"name":"force_delete:categoria","guard_name":"web"},"36":{"name":"force_delete_any:categoria","guard_name":"web"},"37":{"name":"restore_any:categoria","guard_name":"web"},"38":{"name":"replicate:categoria","guard_name":"web"},"39":{"name":"reorder:categoria","guard_name":"web"},"45":{"name":"delete_any:centro_custo","guard_name":"web"},"46":{"name":"restore:centro_custo","guard_name":"web"},"47":{"name":"force_delete:centro_custo","guard_name":"web"},"48":{"name":"force_delete_any:centro_custo","guard_name":"web"},"49":{"name":"restore_any:centro_custo","guard_name":"web"},"50":{"name":"replicate:centro_custo","guard_name":"web"},"51":{"name":"reorder:centro_custo","guard_name":"web"},"57":{"name":"delete_any:cliente","guard_name":"web"},"58":{"name":"restore:cliente","guard_name":"web"},"59":{"name":"force_delete:cliente","guard_name":"web"},"60":{"name":"force_delete_any:cliente","guard_name":"web"},"61":{"name":"restore_any:cliente","guard_name":"web"},"62":{"name":"replicate:cliente","guard_name":"web"},"63":{"name":"reorder:cliente","guard_name":"web"},"68":{"name":"delete:compra","guard_name":"web"},"69":{"name":"delete_any:compra","guard_name":"web"},"70":{"name":"restore:compra","guard_name":"web"},"71":{"name":"force_delete:compra","guard_name":"web"},"72":{"name":"force_delete_any:compra","guard_name":"web"},"73":{"name":"restore_any:compra","guard_name":"web"},"74":{"name":"replicate:compra","guard_name":"web"},"75":{"name":"reorder:compra","guard_name":"web"},"76":{"name":"view_any:contrato","guard_name":"web"},"77":{"name":"view:contrato","guard_name":"web"},"78":{"name":"create:contrato","guard_name":"web"},"79":{"name":"update:contrato","guard_name":"web"},"80":{"name":"delete:contrato","guard_name":"web"},"81":{"name":"delete_any:contrato","guard_name":"web"},"82":{"name":"restore:contrato","guard_name":"web"},"83":{"name":"force_delete:contrato","guard_name":"web"},"84":{"name":"force_delete_any:contrato","guard_name":"web"},"85":{"name":"restore_any:contrato","guard_name":"web"},"86":{"name":"replicate:contrato","guard_name":"web"},"87":{"name":"reorder:contrato","guard_name":"web"},"88":{"name":"view_any:fechamento_caixa","guard_name":"web"},"89":{"name":"view:fechamento_caixa","guard_name":"web"},"90":{"name":"create:fechamento_caixa","guard_name":"web"},"91":{"name":"update:fechamento_caixa","guard_name":"web"},"92":{"name":"delete:fechamento_caixa","guard_name":"web"},"93":{"name":"delete_any:fechamento_caixa","guard_name":"web"},"94":{"name":"restore:fechamento_caixa","guard_name":"web"},"95":{"name":"force_delete:fechamento_caixa","guard_name":"web"},"96":{"name":"force_delete_any:fechamento_caixa","guard_name":"web"},"97":{"name":"restore_any:fechamento_caixa","guard_name":"web"},"98":{"name":"replicate:fechamento_caixa","guard_name":"web"},"99":{"name":"reorder:fechamento_caixa","guard_name":"web"},"105":{"name":"delete_any:prestador","guard_name":"web"},"106":{"name":"restore:prestador","guard_name":"web"},"107":{"name":"force_delete:prestador","guard_name":"web"},"108":{"name":"force_delete_any:prestador","guard_name":"web"},"109":{"name":"restore_any:prestador","guard_name":"web"},"110":{"name":"replicate:prestador","guard_name":"web"},"111":{"name":"reorder:prestador","guard_name":"web"},"117":{"name":"delete_any:horario_funcionamento","guard_name":"web"},"118":{"name":"restore:horario_funcionamento","guard_name":"web"},"119":{"name":"force_delete:horario_funcionamento","guard_name":"web"},"120":{"name":"force_delete_any:horario_funcionamento","guard_name":"web"},"121":{"name":"restore_any:horario_funcionamento","guard_name":"web"},"122":{"name":"replicate:horario_funcionamento","guard_name":"web"},"123":{"name":"reorder:horario_funcionamento","guard_name":"web"},"128":{"name":"delete:lancamento","guard_name":"web"},"129":{"name":"delete_any:lancamento","guard_name":"web"},"130":{"name":"restore:lancamento","guard_name":"web"},"131":{"name":"force_delete:lancamento","guard_name":"web"},"132":{"name":"force_delete_any:lancamento","guard_name":"web"},"133":{"name":"restore_any:lancamento","guard_name":"web"},"134":{"name":"replicate:lancamento","guard_name":"web"},"135":{"name":"reorder:lancamento","guard_name":"web"},"141":{"name":"delete_any:marca","guard_name":"web"},"142":{"name":"restore:marca","guard_name":"web"},"143":{"name":"force_delete:marca","guard_name":"web"},"144":{"name":"force_delete_any:marca","guard_name":"web"},"145":{"name":"restore_any:marca","guard_name":"web"},"146":{"name":"replicate:marca","guard_name":"web"},"147":{"name":"reorder:marca","guard_name":"web"},"150":{"name":"create:movimentacao_produto","guard_name":"web"},"151":{"name":"update:movimentacao_produto","guard_name":"web"},"152":{"name":"delete:movimentacao_produto","guard_name":"web"},"153":{"name":"delete_any:movimentacao_produto","guard_name":"web"},"154":{"name":"restore:movimentacao_produto","guard_name":"web"},"155":{"name":"force_delete:movimentacao_produto","guard_name":"web"},"156":{"name":"force_delete_any:movimentacao_produto","guard_name":"web"},"157":{"name":"restore_any:movimentacao_produto","guard_name":"web"},"158":{"name":"replicate:movimentacao_produto","guard_name":"web"},"159":{"name":"reorder:movimentacao_produto","guard_name":"web"},"165":{"name":"delete_any:opcoes_entregas","guard_name":"web"},"166":{"name":"restore:opcoes_entregas","guard_name":"web"},"167":{"name":"force_delete:opcoes_entregas","guard_name":"web"},"168":{"name":"force_delete_any:opcoes_entregas","guard_name":"web"},"169":{"name":"restore_any:opcoes_entregas","guard_name":"web"},"170":{"name":"replicate:opcoes_entregas","guard_name":"web"},"171":{"name":"reorder:opcoes_entregas","guard_name":"web"},"177":{"name":"delete_any:opcoes_pagamento","guard_name":"web"},"178":{"name":"restore:opcoes_pagamento","guard_name":"web"},"179":{"name":"force_delete:opcoes_pagamento","guard_name":"web"},"180":{"name":"force_delete_any:opcoes_pagamento","guard_name":"web"},"181":{"name":"restore_any:opcoes_pagamento","guard_name":"web"},"182":{"name":"replicate:opcoes_pagamento","guard_name":"web"},"183":{"name":"reorder:opcoes_pagamento","guard_name":"web"},"188":{"name":"delete:pedido","guard_name":"web"},"189":{"name":"delete_any:pedido","guard_name":"web"},"190":{"name":"restore:pedido","guard_name":"web"},"191":{"name":"force_delete:pedido","guard_name":"web"},"192":{"name":"force_delete_any:pedido","guard_name":"web"},"193":{"name":"restore_any:pedido","guard_name":"web"},"194":{"name":"replicate:pedido","guard_name":"web"},"195":{"name":"reorder:pedido","guard_name":"web"},"201":{"name":"delete_any:plano_despesa","guard_name":"web"},"202":{"name":"restore:plano_despesa","guard_name":"web"},"203":{"name":"force_delete:plano_despesa","guard_name":"web"},"204":{"name":"force_delete_any:plano_despesa","guard_name":"web"},"205":{"name":"restore_any:plano_despesa","guard_name":"web"},"206":{"name":"replicate:plano_despesa","guard_name":"web"},"207":{"name":"reorder:plano_despesa","guard_name":"web"},"213":{"name":"delete_any:plano_receita","guard_name":"web"},"214":{"name":"restore:plano_receita","guard_name":"web"},"215":{"name":"force_delete:plano_receita","guard_name":"web"},"216":{"name":"force_delete_any:plano_receita","guard_name":"web"},"217":{"name":"restore_any:plano_receita","guard_name":"web"},"218":{"name":"replicate:plano_receita","guard_name":"web"},"219":{"name":"reorder:plano_receita","guard_name":"web"},"225":{"name":"delete_any:produto","guard_name":"web"},"226":{"name":"restore:produto","guard_name":"web"},"227":{"name":"force_delete:produto","guard_name":"web"},"228":{"name":"force_delete_any:produto","guard_name":"web"},"229":{"name":"restore_any:produto","guard_name":"web"},"230":{"name":"replicate:produto","guard_name":"web"},"231":{"name":"reorder:produto","guard_name":"web"},"237":{"name":"delete_any:promocao_relampago","guard_name":"web"},"238":{"name":"restore:promocao_relampago","guard_name":"web"},"239":{"name":"force_delete:promocao_relampago","guard_name":"web"},"240":{"name":"force_delete_any:promocao_relampago","guard_name":"web"},"241":{"name":"restore_any:promocao_relampago","guard_name":"web"},"242":{"name":"replicate:promocao_relampago","guard_name":"web"},"243":{"name":"reorder:promocao_relampago","guard_name":"web"},"244":{"name":"view_any:role","guard_name":"web"},"245":{"name":"view:role","guard_name":"web"},"246":{"name":"create:role","guard_name":"web"},"247":{"name":"update:role","guard_name":"web"},"248":{"name":"delete:role","guard_name":"web"},"249":{"name":"view_any:user","guard_name":"web"},"250":{"name":"view:user","guard_name":"web"},"251":{"name":"create:user","guard_name":"web"},"252":{"name":"update:user","guard_name":"web"},"253":{"name":"delete:user","guard_name":"web"},"254":{"name":"delete_any:user","guard_name":"web"},"255":{"name":"restore:user","guard_name":"web"},"256":{"name":"force_delete:user","guard_name":"web"},"257":{"name":"force_delete_any:user","guard_name":"web"},"258":{"name":"restore_any:user","guard_name":"web"},"259":{"name":"replicate:user","guard_name":"web"},"260":{"name":"reorder:user","guard_name":"web"},"265":{"name":"delete:venda","guard_name":"web"},"266":{"name":"delete_any:venda","guard_name":"web"},"267":{"name":"restore:venda","guard_name":"web"},"268":{"name":"force_delete:venda","guard_name":"web"},"269":{"name":"force_delete_any:venda","guard_name":"web"},"270":{"name":"restore_any:venda","guard_name":"web"},"271":{"name":"replicate:venda","guard_name":"web"},"272":{"name":"reorder:venda","guard_name":"web"},"273":{"name":"view:configuracao_sefaz","guard_name":"web"},"298":{"name":"view:dashboard","guard_name":"web"},"299":{"name":"view:acesso_rapido_widget","guard_name":"web"},"300":{"name":"view:resumo_operacional_widget","guard_name":"web"},"301":{"name":"delete_any:role","guard_name":"web"},"302":{"name":"restore:role","guard_name":"web"},"303":{"name":"force_delete:role","guard_name":"web"},"304":{"name":"force_delete_any:role","guard_name":"web"},"305":{"name":"restore_any:role","guard_name":"web"},"306":{"name":"replicate:role","guard_name":"web"},"307":{"name":"reorder:role","guard_name":"web"},"331":{"name":"delete:sessao_caixa","guard_name":"web"},"332":{"name":"delete_any:sessao_caixa","guard_name":"web"},"333":{"name":"restore:sessao_caixa","guard_name":"web"},"334":{"name":"force_delete:sessao_caixa","guard_name":"web"},"335":{"name":"force_delete_any:sessao_caixa","guard_name":"web"},"336":{"name":"restore_any:sessao_caixa","guard_name":"web"},"337":{"name":"replicate:sessao_caixa","guard_name":"web"},"338":{"name":"reorder:sessao_caixa","guard_name":"web"},"341":{"name":"delete_any:mesa","guard_name":"web"},"343":{"name":"force_delete:mesa","guard_name":"web"},"344":{"name":"force_delete_any:mesa","guard_name":"web"},"345":{"name":"restore_any:mesa","guard_name":"web"},"346":{"name":"replicate:mesa","guard_name":"web"},"347":{"name":"reorder:mesa","guard_name":"web"},"350":{"name":"update:sessao_mesa","guard_name":"web"},"351":{"name":"delete:sessao_mesa","guard_name":"web"},"352":{"name":"delete_any:sessao_mesa","guard_name":"web"},"353":{"name":"restore:sessao_mesa","guard_name":"web"},"354":{"name":"force_delete:sessao_mesa","guard_name":"web"},"355":{"name":"force_delete_any:sessao_mesa","guard_name":"web"},"356":{"name":"restore_any:sessao_mesa","guard_name":"web"},"357":{"name":"replicate:sessao_mesa","guard_name":"web"},"358":{"name":"reorder:sessao_mesa","guard_name":"web"},"359":{"name":"view_any:maquininha","guard_name":"web"},"360":{"name":"view:maquininha","guard_name":"web"},"361":{"name":"create:maquininha","guard_name":"web"},"362":{"name":"update:maquininha","guard_name":"web"},"363":{"name":"delete:maquininha","guard_name":"web"},"364":{"name":"delete_any:maquininha","guard_name":"web"},"365":{"name":"restore:maquininha","guard_name":"web"},"366":{"name":"force_delete:maquininha","guard_name":"web"},"367":{"name":"force_delete_any:maquininha","guard_name":"web"},"368":{"name":"restore_any:maquininha","guard_name":"web"},"369":{"name":"replicate:maquininha","guard_name":"web"},"370":{"name":"reorder:maquininha","guard_name":"web"}}';

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

        /** @var Model $roleModel */
        $roleModel = Utils::getRoleModel();
        /** @var Model $permissionModel */
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

        /** @var Model $permissionModel */
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
