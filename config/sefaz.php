<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Timeouts (segundos)
    |--------------------------------------------------------------------------
    */
    'timeout' => env('SEFAZ_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Retry da manifestação/busca síncrona (buscarPorChave)
    |--------------------------------------------------------------------------
    |
    | Quantidade de tentativas e o intervalo (segundos) entre cada uma, para
    | aguardar o XML completo propagar depois da manifestação de ciência.
    |
    */
    'tentativas_pos_manifestacao' => 3,
    'intervalo_pos_manifestacao' => [2, 5, 10],

    /*
    |--------------------------------------------------------------------------
    | Throttle do polling agendado (executarPolling)
    |--------------------------------------------------------------------------
    |
    | Limita quantas chamadas consChNFe/manifestação o polling faz por
    | execução, para não estourar limite de rate da SEFAZ quando há um
    | backlog grande de pendências (ex.: primeira ativação do recurso).
    |
    */
    'max_documentos_por_execucao' => env('SEFAZ_MAX_DOCUMENTOS_POR_EXECUCAO', 50),

];
