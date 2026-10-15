<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cerca virtual da loja
    |--------------------------------------------------------------------------
    |
    | Marcação fora do raio é gravada com dentro_da_cerca = false e sinalizada
    | no espelho. Só bloqueia se "bloquear_fora" estiver ligado. Sem lat/lng
    | configurados, a cerca não é avaliada (dentro_da_cerca fica nulo).
    |
    */

    'cerca' => [
        'lat' => env('PONTO_CERCA_LAT'),
        'lng' => env('PONTO_CERCA_LNG'),
        'raio_m' => (int) env('PONTO_CERCA_RAIO_M', 150),
        'bloquear_fora' => (bool) env('PONTO_CERCA_BLOQUEAR_FORA', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Selfie
    |--------------------------------------------------------------------------
    */

    'exigir_selfie' => (bool) env('PONTO_EXIGIR_SELFIE', false),
    'retencao_selfie_dias' => (int) env('PONTO_RETENCAO_SELFIE_DIAS', 90),
    'disco' => env('PONTO_DISCO', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Marcação
    |--------------------------------------------------------------------------
    |
    | Intervalo mínimo entre duas marcações do mesmo colaborador (duplicada).
    |
    */

    'intervalo_minimo_duplicada_s' => (int) env('PONTO_INTERVALO_DUPLICADA_S', 60),

    /*
    |--------------------------------------------------------------------------
    | Jornada noturna (CLT art. 73)
    |--------------------------------------------------------------------------
    */

    'noturno_inicio' => env('PONTO_NOTURNO_INICIO', '22:00'),
    'noturno_fim' => env('PONTO_NOTURNO_FIM', '05:00'),

    /*
    |--------------------------------------------------------------------------
    | Limites de jornada usados nas inconsistências
    |--------------------------------------------------------------------------
    */

    'jornada_maxima_minutos' => (int) env('PONTO_JORNADA_MAXIMA_MINUTOS', 600),

    /*
    |--------------------------------------------------------------------------
    | Plano de despesas do repasse (por vínculo)
    |--------------------------------------------------------------------------
    |
    | IDs de planos_despesas. Ficam no .env porque os IDs de produção podem
    | diferir do dev. "entregador" vale para colaborador marcado como
    | entregador, qualquer que seja o vínculo. Sem plano configurado, a
    | aprovação do repasse é bloqueada com mensagem.
    |
    */

    'plano_contas' => [
        'clt' => env('PONTO_PLANO_CLT'),
        'estagio' => env('PONTO_PLANO_ESTAGIO', env('PONTO_PLANO_CLT')),
        'diarista' => env('PONTO_PLANO_DIARISTA'),
        'freelancer' => env('PONTO_PLANO_FREELANCER', env('PONTO_PLANO_DIARISTA')),
        'mei_pj' => env('PONTO_PLANO_MEI_PJ', env('PONTO_PLANO_DIARISTA')),
        'entregador' => env('PONTO_PLANO_ENTREGADOR'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagamento
    |--------------------------------------------------------------------------
    |
    | Dias após o fim do período para a data de pagamento sugerida.
    |
    */

    'dias_para_pagamento' => (int) env('PONTO_DIAS_PARA_PAGAMENTO', 5),

    /*
    |--------------------------------------------------------------------------
    | Correção de ponto
    |--------------------------------------------------------------------------
    |
    | Ninguém corrige o próprio ponto. Admin só pode se isto estiver ligado.
    | A regra mora no Service: o Admin passa em qualquer Policy (Gate::before).
    |
    */

    'admin_corrige_proprio' => (bool) env('PONTO_ADMIN_CORRIGE_PROPRIO', false),

    /*
    |--------------------------------------------------------------------------
    | Trigger MySQL append-only em ponto_marcacoes
    |--------------------------------------------------------------------------
    |
    | Exige o privilégio TRIGGER (e SUPER ou log_bin_trust_function_creators
    | com binlog ligado). Desligado, a migration do trigger não faz nada e a
    | proteção fica no model + `ponto:verificar-integridade`.
    |
    */

    'trigger_append_only' => (bool) env('PONTO_TRIGGER_APPEND_ONLY', false),

    /*
    |--------------------------------------------------------------------------
    | Quiosque
    |--------------------------------------------------------------------------
    */

    /*
    | Tablets autorizados: tokens longos e aleatórios separados por vírgula.
    | O tablet é pareado uma vez abrindo /ponto/quiosque/parear/{token}, que
    | grava um cookie (criptografado) de longa duração. Para descredenciar,
    | tire o token daqui. Errar o PIN N vezes bloqueia o tablet por M minutos.
    */

    'quiosque' => [
        'tokens' => array_values(array_filter(array_map('trim', explode(',', (string) env('PONTO_QUIOSQUE_TOKENS', ''))))),
        'tentativas_pin' => (int) env('PONTO_QUIOSQUE_TENTATIVAS', 5),
        'bloqueio_minutos' => (int) env('PONTO_QUIOSQUE_BLOQUEIO_MIN', 5),
        // Sem confirmar em tantos segundos, a tela volta ao teclado.
        'expira_identificacao_s' => (int) env('PONTO_QUIOSQUE_EXPIRA_S', 30),
    ],

];
