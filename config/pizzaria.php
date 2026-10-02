<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Janela operacional (turno)
    |--------------------------------------------------------------------------
    |
    | Recorte de tempo que o Painel de Pedidos considera "o turno de hoje". A
    | fonte de verdade é o model HorarioFuncionamento (App\Support\JanelaOperacional
    | resolve a abertura mais recente entre os horários ativos, suportando
    | vários turnos por dia). `abertura` aqui é só o FALLBACK usado quando não
    | há nenhum horário de funcionamento cadastrado/ativo — não existe mais um
    | horário de fechamento fixo: o turno dura 24h a partir da abertura
    | resolvida, e o próximo turno substitui a janela naturalmente.
    |
    */

    'janela_operacional' => [
        'abertura' => env('PIZZARIA_TURNO_ABERTURA', '07:00'),
    ],

    'pedidos' => [

        /*
        |----------------------------------------------------------------------
        | Estágio de transporte
        |----------------------------------------------------------------------
        |
        | Quando false, o pedido de entrega avança de PRONTO direto para
        | ENTREGUE e a coluna "Em Transporte" desaparece do Painel de Pedidos.
        | Pedido que não exige endereço (mesa/balcão) nunca passa por esse
        | estágio, independente desta chave.
        |
        */

        'usa_estagio_transporte' => env('PIZZARIA_USA_ESTAGIO_TRANSPORTE', true),

        /*
        |----------------------------------------------------------------------
        | Atribuição de entregador
        |----------------------------------------------------------------------
        |
        | Quando false, despachar um pedido não pede a seleção de entregador.
        |
        */

        'atribui_entregador' => env('PIZZARIA_ATRIBUI_ENTREGADOR', true),

        /*
        |----------------------------------------------------------------------
        | Limites de SLA por status, em minutos
        |----------------------------------------------------------------------
        |
        | Tempo que um pedido pode ficar em cada status antes de o card ganhar
        | o aviso de atenção (primeiro valor) e de atraso (segundo valor). O
        | relógio conta a partir da data/hora em que o pedido entrou no status
        | atual, não da abertura. Status ausente aqui nunca acusa urgência.
        |
        */

        'sla' => [
            'INICIADO' => ['atencao' => 3, 'atrasado' => 8],
            'ABERTO' => ['atencao' => 5, 'atrasado' => 10],
            'PREPARANDO' => ['atencao' => 20, 'atrasado' => 35],
            'PRONTO' => ['atencao' => 10, 'atrasado' => 20],
            'EM TRANSPORTE' => ['atencao' => 30, 'atrasado' => 45],
        ],

        /*
        |----------------------------------------------------------------------
        | Coluna "Entregue" do painel
        |----------------------------------------------------------------------
        */

        'limite_entregue' => 50,
        'limite_entregue_expandido' => 200,
    ],

    'salao' => [

        /*
        |----------------------------------------------------------------------
        | Taxa de serviço
        |----------------------------------------------------------------------
        |
        | Percentual sugerido em toda conta de mesa/comanda. É copiado para a
        | sessão na abertura (sessao_mesa_taxa_servico_percentual); mudar aqui
        | não altera contas já abertas. Remover a taxa de uma conta exige a
        | permissão remover_taxa:sessao_mesa ou o PIN de um gerente.
        |
        */

        'taxa_servico_percentual' => (float) env('PIZZARIA_TAXA_SERVICO_PERCENTUAL', 10),
        'taxa_servico_padrao_ligada' => (bool) env('PIZZARIA_TAXA_SERVICO_PADRAO_LIGADA', true),

        /*
        |----------------------------------------------------------------------
        | Alerta de mesa parada
        |----------------------------------------------------------------------
        |
        | Minutos sem nenhuma rodada nova (ou desde a abertura) para o card da
        | mesa no mapa ganhar o alerta de "mesa parada".
        |
        */

        'alerta_mesa_parada_minutos' => (int) env('PIZZARIA_ALERTA_MESA_PARADA_MINUTOS', 40),

        /*
        |----------------------------------------------------------------------
        | Intervalos de atualização do Painel do Garçom, em segundos
        |----------------------------------------------------------------------
        */

        'polling_mapa_segundos' => 10,
        'polling_mesa_segundos' => 5,

        /*
        |----------------------------------------------------------------------
        | PIN
        |----------------------------------------------------------------------
        |
        | Tentativas erradas por usuário antes do bloqueio temporário.
        |
        */

        /*
        |----------------------------------------------------------------------
        | Retirada pelo garçom
        |----------------------------------------------------------------------
        |
        | Opção de entrega gravada no pedido de retirada lançado pelo garçom.
        | Null = a primeira opção que não exige endereço.
        |
        */

        'opcao_entrega_retirada_id' => env('PIZZARIA_OPCAO_ENTREGA_RETIRADA_ID'),

        'pin_tentativas' => 5,
        'pin_bloqueio_segundos' => 300,
    ],

];
