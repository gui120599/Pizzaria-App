<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionSeeder extends Seeder
{
    /**
     * Permissions de negócio (fora do CRUD padrão que o Filament Shield gera para
     * os Resources) consumidas pelas Policies da Fase 2. Convenção {verbo}:{recurso}
     * em snake_case — mesma gramática e separador que o Shield usa pra CRUD
     * (view:produto, create:produto), pra não misturar duas convenções na tabela
     * `permissions`. Admin não precisa de nenhuma listada aqui: config/filament-shield.php
     * registra Admin como super_admin via Gate::before, então ele já passa em
     * qualquer Gate::check/can() independente do que estiver atribuído a ele.
     */
    private array $permissionsPorRole = [
        'Gerente' => [
            'cancel:pedido', 'accept:pedido', 'reject:pedido', 'advance:pedido', 'deliver:pedido',
            'create:pedido', 'update:pedido', 'view:sessao_mesa',
            'estornar:pagamento', 'approve:lancamento', 'confirm:compra', 'registrar:devolucao_compra',
            'corrigir:movimentacao_estoque',
            'abrir:sessao_caixa', 'fechar:sessao_caixa', 'sangrar:sessao_caixa', 'view:relatorio_financeiro',
            'importar_caixa:lancamento',
            'operar:venda', 'cancel:venda', 'emitir:nfe',
            // Cadastros/config (bloco amarelo): Produto/Categoria/OpcoesEntregas/
            // OpcoesPagamento usam os nomes gerados pelo Shield (já existem na
            // tabela via ShieldSeeder). Mesa/Empresa/Caixa não têm Resource no
            // Filament — permissions criadas aqui mesmo, seguindo a mesma
            // gramática, prontas se algum dia ganharem um Resource. Adicional
            // ganhou AdicionalResource: view_any/view/restore liberam a tela.
            // Nota: create:caixa/update:caixa/delete:caixa se referem ao MODEL
            // Caixa (cadastro de caixas físicos), não à role "Caixa" — tabelas
            // diferentes (permissions x roles), sem colisão real.
            'create:produto', 'update:produto', 'delete:produto',
            'create:categoria', 'update:categoria', 'delete:categoria',
            'create:opcoes_entregas', 'update:opcoes_entregas', 'delete:opcoes_entregas',
            'create:opcoes_pagamento', 'update:opcoes_pagamento', 'delete:opcoes_pagamento',
            'create:mesa', 'update:mesa', 'delete:mesa',
            'create:empresa', 'update:empresa', 'delete:empresa',
            'create:caixa', 'update:caixa', 'delete:caixa',
            'view_any:adicional', 'view:adicional', 'create:adicional', 'update:adicional', 'delete:adicional', 'restore:adicional',
            // Bloco verde: create:cliente/update:cliente também já existem via
            // Shield. view_any:nota_fiscal/view:nota_fiscal são novas (sem
            // Resource no Filament) — dado fiscal, mesmo racional de
            // view:relatorio_financeiro (só quem fecha o financeiro vê).
            'create:cliente', 'update:cliente', 'delete:cliente',
            'view_any:nota_fiscal', 'view:nota_fiscal',
            // Ações sensíveis do salão: quem tem dispensa o PIN de gerente
            // (ver AcaoAutorizadaEnum::permissao()).
            'cancelar_item:pedido', 'remover_taxa:sessao_mesa', 'transferir:sessao_mesa',
            // Relatório de Taxa de Serviço (página + widgets do Shield).
            'view:relatorio_taxa_servico', 'view:taxa_servico_stats_overview',
            'view:taxa_servico_por_garcom_widget', 'view:taxa_servico_detalhamento_widget',
            // Relatório de Fechamento de Caixa (página + widgets do Shield).
            'view:relatorio_fechamento_caixa', 'view:fechamento_caixa_stats_overview',
            'view:fechamento_caixa_dre_widget', 'view:fechamento_caixa_fluxo_caixa_widget',
            'view:fechamento_caixa_formas_pagamento_widget',
            'view:fechamento_caixa_por_maquininha_widget', 'view:fechamento_caixa_maquininhas_widget',
            'view:fechamento_caixa_bandeiras_widget', 'view:fechamento_caixa_previsao_recebimento_widget',
            'view:fechamento_caixa_movimentacoes_widget', 'view:fechamento_caixa_conferencia_widget',
            'view:fechamento_caixa_conferencia_maquininhas_widget',
        ],
        'Atendente' => [
            'cancel:pedido', 'accept:pedido', 'reject:pedido', 'advance:pedido',
            'create:pedido', 'update:pedido', 'view:sessao_mesa',
            'operar:venda',
            // Cadastrar/atualizar cliente é rotina do atendimento (ex: durante
            // o pedido); excluir cliente fica só com Gerente.
            'create:cliente', 'update:cliente',
        ],
        'Caixa' => [
            'abrir:sessao_caixa', 'fechar:sessao_caixa', 'sangrar:sessao_caixa', 'view:sessao_mesa',
            'operar:venda', 'cancel:venda', 'emitir:nfe',
        ],
        'Entregador' => [
            'deliver:pedido',
        ],
        'Garcom' => [
            'view:sessao_mesa', 'view_any:sessao_mesa', 'create:sessao_mesa',
            'view:pedido', 'view_any:pedido', 'create:pedido', 'update:pedido',
            'cancel:pedido', 'advance:pedido',
            'create:cliente', 'update:cliente',
        ],
    ];

    public function run(): void
    {
        $todasPermissions = collect($this->permissionsPorRole)->flatten()->unique();

        foreach ($todasPermissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach ($this->permissionsPorRole as $role => $permissions) {
            // givePermissionTo (aditivo) e não syncPermissions: o Shield já atribui as
            // permissions de CRUD dos Resources à role super_admin (Admin) separadamente
            // — sync aqui apagaria essa atribuição.
            Role::findByName($role, 'web')->givePermissionTo($permissions);
        }
    }
}
