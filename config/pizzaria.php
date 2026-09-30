<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Janela operacional (turno)
    |--------------------------------------------------------------------------
    |
    | Recorte de tempo que o Painel de Pedidos considera "o turno de hoje". A
    | operação abre pela manhã e fecha na madrugada do dia seguinte, então a
    | janela atravessa a meia-noite: das 07:00 de hoje às 03:00 de amanhã.
    | Regra extraída de PedidoController::PedidosEntregueLista, onde os
    | horários estavam hardcoded.
    |
    */

    'janela_operacional' => [
        'abertura' => env('PIZZARIA_TURNO_ABERTURA', '07:00'),
        'fechamento' => env('PIZZARIA_TURNO_FECHAMENTO', '03:00'),
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

];
