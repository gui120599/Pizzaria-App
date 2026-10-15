<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Módulo ligado
    |--------------------------------------------------------------------------
    |
    | false (padrão): a loja trabalha como antes do módulo — pedido não gera
    | item de produção, o Painel de Pedidos não pergunta por itens na cozinha
    | nem mostra a faixa de produção, o garçom não vê os chips e o /cozinha
    | fica fechado. Cadastros (setores, rotas, fichas) continuam no admin para
    | preparar a virada. Itens que já existiam só são encerrados/cancelados
    | quando o pedido anda (nada novo é gerado).
    |
    | Ligar com COZINHA_ATIVO=true quando os tablets estiverem pareados.
    |
    */

    'ativo' => env('COZINHA_ATIVO', false),

    /*
    |--------------------------------------------------------------------------
    | Mapeamento para o status real do pedido
    |--------------------------------------------------------------------------
    |
    | Só o App\Services\Cozinha\PedidoStatusGateway lê este bloco: ele é o
    | único ponto do módulo que conhece `pedidos.pedido_status` (valores de
    | App\Enums\StatusPedidoEnum). As transições passam pelo
    | PedidoStatusService — entrar em PREPARANDO é o que baixa o estoque.
    |
    | ao_expedir: por tipo de atendimento (App\Enums\TipoAtendimentoEnum).
    | null = o pedido fica onde está (PRONTO): o delivery sai pelo fluxo do
    | entregador e a retirada quando o cliente busca.
    |
    */

    'pedido_status' => [
        'libera_producao' => ['ABERTO', 'PREPARANDO'],
        'cancelado' => ['CANCELADO'],
        'ao_iniciar' => 'PREPARANDO',
        'ao_concluir' => 'PRONTO',
        'ao_expedir' => [
            'mesa' => 'ENTREGUE',
            'delivery' => null,
            'retirada' => null,
        ],
        'nao_retroceder' => ['EM TRANSPORTE', 'ENTREGUE', 'FINALIZADO', 'CANCELADO'],
    ],

    // false: todos os itens prontos já aplicam ao_concluir, sem tela de Expedição.
    'usa_expedicao' => env('COZINHA_USA_EXPEDICAO', true),

    // Slug de setores_producao para item sem rota; null = não vai à cozinha.
    'setor_padrao' => env('COZINHA_SETOR_PADRAO'),

    'janela_prontos_min' => 10,

    'desfazer_segundos' => 10,

    // Item rápido espera o mais demorado do pedido para ficarem prontos juntos.
    'disparo_por_tempo_preparo' => env('COZINHA_DISPARO_POR_TEMPO', false),

    'operador' => [
        // 'usuarios' (users com papel de cozinha) | 'ponto' (os mesmos, filtrados por ponto aberto)
        'fonte' => env('COZINHA_OPERADOR_FONTE', 'usuarios'),
        // PIN do próprio User (o mesmo do painel do garçom).
        'exigir_pin' => env('COZINHA_OPERADOR_PIN', false),
        'expira_min' => 240,
        // Papéis que aparecem como operador no tablet.
        'papeis' => ['Cozinha', 'Expedicao', 'Gerente'],
    ],

    // Quem tem o papel Cozinha também pode despachar na Expedição.
    'cozinha_despacha' => env('COZINHA_COZINHA_DESPACHA', false),

    'tempo_real' => [
        // 'polling' | 'reverb' (o projeto ainda não tem broadcasting)
        'driver' => env('COZINHA_TEMPO_REAL', 'polling'),
        'polling_segundos' => 4,
    ],

    // Notifica o gerente quando a idade do item passa de N × tempo_alvo do setor.
    'alerta_atraso_multiplicador' => 2,

    'disco_fotos' => env('COZINHA_DISCO', 'public'),

    // Validade do QR de pareamento do tablet.
    'pareamento_minutos' => 10,

    'checklists' => [
        // Valores de App\Enums\Checklist\ChecklistSetor executados nos tablets.
        'setores_checklist' => ['cozinha'],
        // A spec quer um lugar só (false). Fica true até os tablets estarem
        // pareados: senão os checklists da cozinha somem do /garcom antes de
        // terem onde ser executados. Desligar com COZINHA_CHECKLISTS_NO_GARCOM=false.
        'exibir_tambem_no_garcom' => env('COZINHA_CHECKLISTS_NO_GARCOM', true),
        'bloquear_producao_sem_abertura' => env('COZINHA_BLOQUEAR_SEM_ABERTURA', false),
        'aviso_minutos_antes_fim' => 30,
    ],

];
