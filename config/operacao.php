<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Virada do dia operacional
    |--------------------------------------------------------------------------
    |
    | Antes deste horário ainda vale o dia anterior: o turno de sábado que
    | termina 01:30 de domingo pertence a sábado. Compartilhado pelos módulos
    | de ponto e de checklists (ver App\Support\DiaOperacional). Enquanto o
    | checklists não migrar para cá, cai no mesmo env dele.
    |
    */

    'virada_dia' => env('OPERACAO_VIRADA_DIA', env('CHECKLISTS_VIRADA_DIA', '05:00')),

];
