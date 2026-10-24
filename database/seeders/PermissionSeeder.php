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
            // Exclusão de pedido é soft delete com motivo (PedidoExclusaoService); nunca física.
            'delete:pedido', 'delete_any:pedido', 'restore:pedido', 'restore_any:pedido',
            // A exportação da lista de pedidos leva nome e celular do cliente.
            'export:pedido',
            'estornar:pagamento', 'approve:lancamento', 'confirm:compra', 'registrar:devolucao_compra', 'corrigir:data_entrada_compra',
            'corrigir:movimentacao_estoque',
            'abrir:sessao_caixa', 'fechar:sessao_caixa', 'sangrar:sessao_caixa', 'view:relatorio_financeiro',
            'importar_caixa:lancamento', 'liquidar_recebivel:lancamento', 'conciliar:lancamento', 'view:recebiveis_adquirentes_widget', 'view:fechamento_caixa_contabilizacao_widget',
            'operar:venda', 'cancel:venda', 'emitir:nfe', 'cancelar_finalizada:venda', 'alterar_finalizada:venda',
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
            'cancelar_item:pedido', 'remover_taxa:sessao_mesa', 'transferir:sessao_mesa', 'conceder_cortesia:pedido',
            // Relatório de Exceções e Perdas dos pedidos (página + widgets do Shield).
            // Traz contagens por usuário: só Admin (via Gate) e Gerente.
            'view:relatorio_excecoes_pedidos', 'view:excecoes_pedidos_stats_overview',
            'view:excecoes_cancelamentos_por_motivo_widget', 'view:excecoes_cancelamentos_por_usuario_widget',
            'view:excecoes_itens_removidos_widget', 'view:excecoes_pedidos_excluidos_widget',
            'view:excecoes_entregues_nao_pagos_widget', 'view:excecoes_lancamentos_por_usuario_widget',
            // Relatório de Taxa de Serviço (página + widgets do Shield).
            'view:relatorio_taxa_servico', 'view:taxa_servico_stats_overview',
            'view:taxa_servico_por_garcom_widget', 'view:taxa_servico_detalhamento_widget',
            // Relatório de Fechamento de Caixa (página + widgets do Shield).
            'view:relatorio_fechamento_caixa', 'view:fechamento_caixa_stats_overview',
            'view:fechamento_caixa_dre_widget', 'view:fechamento_caixa_fluxo_caixa_widget',
            'view:fechamento_caixa_formas_pagamento_widget',
            'view:fechamento_caixa_por_operadora_widget', 'view:fechamento_caixa_por_maquininha_widget', 'view:fechamento_caixa_maquininhas_widget',
            'view:fechamento_caixa_bandeiras_widget', 'view:fechamento_caixa_previsao_recebimento_widget',
            'view:fechamento_caixa_movimentacoes_widget', 'view:fechamento_caixa_conferencia_widget',
            'view:fechamento_caixa_conferencia_maquininhas_widget',
            // Relatório de Sessão de Caixa (página + widgets do Shield) e correção de lançamentos.
            'view:relatorio_sessao_caixa', 'view:sessao_caixa_stats_overview',
            'view:sessao_caixa_vendas_widget', 'view:sessao_caixa_fiado_widget', 'view:sessao_caixa_cortesias_widget',
            'corrigir:pagamento_venda',
            // Módulo de checklists (executar depende do papel atribuído ao modelo, não de permission).
            'view_any:checklist_modelo', 'view:checklist_modelo', 'create:checklist_modelo',
            'update:checklist_modelo', 'delete:checklist_modelo', 'delete_any:checklist_modelo',
            'restore:checklist_modelo', 'restore_any:checklist_modelo',
            'view_any:checklist_execucao', 'view:checklist_execucao', 'aprovar:checklist_execucao',
            'view_any:checklist_nao_conformidade', 'view:checklist_nao_conformidade', 'update:checklist_nao_conformidade',
            'view:checklists_dashboard', 'view:checklists_hoje_widget',
            'view:checklist_conformidade_stats_overview', 'view:checklist_itens_mais_falham_widget',
            'view:checklist_itens_mais_dispensados_widget',
            // Módulo de cozinha (mesmo conjunto de 2026_10_14_100013_seed_cozinha_roles_and_permissions).
            'view_any:setor_producao', 'view:setor_producao', 'create:setor_producao', 'update:setor_producao',
            'delete:setor_producao', 'delete_any:setor_producao', 'restore:setor_producao', 'restore_any:setor_producao', 'reorder:setor_producao',
            'view_any:ficha_tecnica', 'view:ficha_tecnica', 'create:ficha_tecnica', 'update:ficha_tecnica',
            'delete:ficha_tecnica', 'delete_any:ficha_tecnica', 'restore:ficha_tecnica', 'restore_any:ficha_tecnica',
            'view_any:dispositivo_cozinha', 'view:dispositivo_cozinha', 'create:dispositivo_cozinha', 'update:dispositivo_cozinha',
            'delete:dispositivo_cozinha', 'delete_any:dispositivo_cozinha', 'parear:dispositivo_cozinha',
            'view_any:producao_item', 'produzir:producao_item', 'despachar:producao_item', 'refazer:producao_item',
            'priorizar:producao_item', 'forcar:producao_item', 'liberar_sem_abertura:producao_item',
            'view:relatorio_producao',
            // Programa de influenciadores. Resgate é registro financeiro: só ler e criar.
            'view_any:influenciador', 'view:influenciador', 'create:influenciador', 'update:influenciador', 'delete:influenciador',
            'view_any:cupom', 'view:cupom', 'create:cupom', 'update:cupom', 'delete:cupom',
            'view_any:comissao', 'view:comissao', 'create:comissao', 'update:comissao', 'delete:comissao',
            'view_any:material_divulgacao', 'view:material_divulgacao', 'create:material_divulgacao', 'update:material_divulgacao', 'delete:material_divulgacao',
            'view_any:resgate_carteira', 'create:resgate_carteira', 'ajustar:carteira_influenciador',
        ],
        'Cozinha' => ['view_any:ficha_tecnica', 'view:ficha_tecnica', 'produzir:producao_item'],
        'Expedicao' => ['view_any:ficha_tecnica', 'view:ficha_tecnica', 'produzir:producao_item', 'despachar:producao_item', 'refazer:producao_item'],
        // view:checklist_execucao: sem operador, o tablet abre o checklist só para leitura.
        'EstacaoCozinha' => ['view_any:ficha_tecnica', 'view:ficha_tecnica', 'produzir:producao_item', 'view:checklist_execucao'],
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

    /**
     * Permissions sem role: só o Admin as exerce, via Gate::before (super_admin).
     * Existem na tabela para a página aparecer no Shield e poderem ser atribuídas depois.
     */
    private array $permissionsSomenteAdmin = ['view:configuracao_pedidos', 'view:configuracao_influenciadores'];

    /**
     * Pedido nunca é excluído fisicamente. O Admin passa em qualquer Gate, então
     * isto é só higiene; a garantia de verdade é nenhuma tela oferecer ForceDelete.
     */
    private array $permissionsRevogadasDoAdmin = ['force_delete:pedido', 'force_delete_any:pedido'];

    public function run(): void
    {
        $todasPermissions = collect($this->permissionsPorRole)->flatten()->merge($this->permissionsSomenteAdmin)->unique();

        foreach ($todasPermissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach ($this->permissionsPorRole as $role => $permissions) {
            // givePermissionTo (aditivo) e não syncPermissions: o Shield já atribui as
            // permissions de CRUD dos Resources à role super_admin (Admin) separadamente
            // — sync aqui apagaria essa atribuição.
            Role::findByName($role, 'web')->givePermissionTo($permissions);
        }

        $admin = Role::where('name', 'Admin')->where('guard_name', 'web')->first();
        $revogadas = Permission::whereIn('name', $this->permissionsRevogadasDoAdmin)->get();

        if ($admin && $revogadas->isNotEmpty()) {
            $admin->revokePermissionTo($revogadas);
        }
    }
}
