<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Permissions de CRUD dos Resources/Pages/Widgets que o Filament Shield gera
 * (view_any, view, create, update, delete, view:<page/widget>) — o
 * `PermissionSeeder` só cobre as permissions de negócio (cancel:pedido,
 * operar:venda etc.), nunca essas. Sem este seeder, nenhuma Role além de
 * Admin (que bypassa via Gate::before) enxerga nada no painel Filament.
 *
 * Admin e Entregador não aparecem aqui: Admin não precisa (bypass) e
 * Entregador usa o painel próprio (/Entregador, fora do Filament).
 * User/Role (RoleResource) não são concedidas a ninguém — gestão de
 * usuário/role fica território exclusivo do Admin. `delete` fica de fora de
 * Compra/Lancamento/Venda/Pedido pra todo mundo — são registros com trilha
 * de auditoria financeira. force_delete, restore, replicate e reorder não
 * são concedidas a ninguém (só Admin, via bypass).
 */
class ResourcePermissionSeeder extends Seeder
{
    private const CRUD_COMPLETO = ['view_any', 'view', 'create', 'update', 'delete'];

    private const CRUD_SEM_DELETE = ['view_any', 'view', 'create', 'update'];

    private const SOMENTE_LEITURA = ['view_any', 'view'];

    /** Widgets do Painel Operacional — menos sensíveis, liberados também pra Atendente/Caixa. */
    private const WIDGETS_OPERACIONAIS = [
        'view:motivos_cancelamento_chart',
        'view:pedidos_por_dia_semana_chart',
        'view:pedidos_por_hora_chart',
        'view:pedidos_por_origem_chart',
        'view:pedidos_stats_overview',
    ];

    /** Widgets do Painel Financeiro — só Gerente. */
    private const WIDGETS_FINANCEIROS = [
        'view:faturamento_por_dia_chart',
        'view:faturamento_por_tipo_entrega_chart',
        'view:financeiro_stats_overview',
        'view:formas_pagamento_chart',
        'view:produtos_vendidos_por_periodo_chart',
        'view:top_produtos_vendidos',
    ];

    public function run(): void
    {
        $leituraOperacional = $this->permissoes(self::SOMENTE_LEITURA, ['pedido', 'venda', 'cliente']);

        $permissionsPorRole = [
            'Gerente' => [
                ...$this->permissoes(self::CRUD_COMPLETO, [
                    'avaliacao_link', 'categoria', 'centro_custo', 'cliente', 'prestador',
                    'horario_funcionamento', 'marca', 'opcoes_entregas', 'opcoes_pagamento',
                    'produto', 'promocao_relampago', 'movimentacao_balanco', 'plano_despesa', 'plano_receita',
                ]),
                ...$this->permissoes(self::CRUD_SEM_DELETE, ['compra', 'lancamento', 'venda', 'pedido']),
                ...$this->permissoes(self::SOMENTE_LEITURA, ['movimentacao_produto']),
                'view:financeiro_dashboard', 'view:pedidos_dashboard',
                ...self::WIDGETS_FINANCEIROS,
                ...self::WIDGETS_OPERACIONAIS,
            ],
            'Atendente' => [
                ...$leituraOperacional,
                ...$this->permissoes(self::SOMENTE_LEITURA, ['categoria', 'produto']),
                'view:pedidos_dashboard',
                ...self::WIDGETS_OPERACIONAIS,
            ],
            'Caixa' => [
                ...$leituraOperacional,
                'view:pedidos_dashboard',
                ...self::WIDGETS_OPERACIONAIS,
            ],
        ];

        $todasPermissions = collect($permissionsPorRole)->flatten()->unique();

        foreach ($todasPermissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach ($permissionsPorRole as $role => $permissions) {
            // givePermissionTo (aditivo) e não syncPermissions: não apaga ajustes
            // manuais feitos em /admin/shield/roles nem o que o Shield atribui
            // à role super_admin (Admin) separadamente.
            Role::findByName($role, 'web')->givePermissionTo($permissions);
        }
    }

    /**
     * @param  array<int, string>  $acoes
     * @param  array<int, string>  $recursos
     * @return array<int, string>
     */
    private function permissoes(array $acoes, array $recursos): array
    {
        return collect($acoes)
            ->crossJoin($recursos)
            ->map(fn (array $par): string => "{$par[0]}:{$par[1]}")
            ->all();
    }
}
