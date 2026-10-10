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
    | Uma só: cada tentativa é uma consulta que conta pro limite de uso da
    | SEFAZ (NT 2014.002) — se o XML não vier, o usuário tenta de novo depois.
    |
    */
    'tentativas_pos_manifestacao' => 1,
    'intervalo_pos_manifestacao' => [5],

    /*
    |--------------------------------------------------------------------------
    | Limites de uso do NFeDistribuicaoDFe (ver SefazControleConsumo)
    |--------------------------------------------------------------------------
    |
    | Espera após Consumo Indevido (cStat 656) ou "sem novidades" por NSU
    | (1 hora da regra + 1 minuto de margem), e quantas consultas da mesma
    | chave fazemos por hora antes de parar por conta própria (abaixo do
    | limite da SEFAZ, que bloqueia o CNPJ inteiro quando estoura).
    |
    */
    'minutos_espera_consumo' => 61,
    'limite_consultas_por_chave_hora' => 15,

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
