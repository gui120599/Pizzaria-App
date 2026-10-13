<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Disco das fotos dos checklists
    |--------------------------------------------------------------------------
    |
    | Disco (config/filesystems.php) onde ficam as fotos anexadas aos itens.
    | Com "public" é preciso o `php artisan storage:link`.
    |
    */

    'disco' => env('CHECKLISTS_DISCO', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Virada do dia operacional
    |--------------------------------------------------------------------------
    |
    | Antes deste horário ainda vale o dia anterior: o fechamento de sábado
    | feito à 00:40 de domingo pertence a sábado. A geração diária das
    | execuções pendentes roda logo depois deste horário.
    |
    */

    'virada_dia' => env('CHECKLISTS_VIRADA_DIA', '05:00'),

    /*
    |--------------------------------------------------------------------------
    | Tolerância padrão (minutos)
    |--------------------------------------------------------------------------
    |
    | Valor inicial de "tolerância" ao criar um modelo: depois do fim da
    | janela, quanto tempo a execução ainda pode ser finalizada antes de
    | virar "não realizado".
    |
    */

    'tolerancia_padrao' => (int) env('CHECKLISTS_TOLERANCIA_PADRAO', 30),

    /*
    |--------------------------------------------------------------------------
    | Tamanho máximo da foto (KB)
    |--------------------------------------------------------------------------
    |
    | Limite do upload já comprimido no navegador (máx. ~1600px).
    |
    */

    'max_foto_kb' => (int) env('CHECKLISTS_MAX_FOTO_KB', 4096),

];
