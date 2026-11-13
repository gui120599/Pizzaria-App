<?php

/*
|--------------------------------------------------------------------------
| Integrações bancárias
|--------------------------------------------------------------------------
|
| Banco do Brasil — API Extratos (conta corrente PJ), usada para conciliar
| boletos pagos (App\Services\Financeiro\BancoBrasil). As credenciais vêm do
| Portal Developers BB (cadastro feito pelo responsável pela conta PJ).
|
| ⚠ URLs, escopo, nomes de parâmetros e formato de data abaixo seguem a
| documentação conhecida da API Extratos v1 e PRECISAM ser conferidos no
| portal antes de ativar (homologação primeiro). Tudo é configurável pelo
| .env para não exigir mudança de código se o BB mudar algo.
|
*/

return [

    'banco_brasil' => [
        'client_id' => env('BB_CLIENT_ID'),
        'client_secret' => env('BB_CLIENT_SECRET'),
        'developer_app_key' => env('BB_DEVELOPER_APP_KEY'),

        // homologacao | producao
        'ambiente' => env('BB_AMBIENTE', 'homologacao'),

        // Conta padrão (a conta financeira com integracao = banco_brasil
        // usa a agência/conta do próprio cadastro; estes são o fallback).
        'agencia' => env('BB_AGENCIA'),
        'conta' => env('BB_CONTA'),

        'urls' => [
            'homologacao' => [
                'token' => env('BB_URL_TOKEN_HM', 'https://oauth.hm.bb.com.br/oauth/token'),
                'api' => env('BB_URL_API_HM', 'https://api.hm.bb.com.br/extratos/v1'),
            ],
            'producao' => [
                'token' => env('BB_URL_TOKEN', 'https://oauth.bb.com.br/oauth/token'),
                'api' => env('BB_URL_API', 'https://api-extratos.bb.com.br/extratos/v1'),
            ],
        ],

        'escopo' => env('BB_ESCOPO', 'extrato-info'),

        // Nome do parâmetro de query da chave do app (gw-dev-app-key em homologação).
        'parametro_app_key' => env('BB_PARAMETRO_APP_KEY', 'gw-dev-app-key'),

        // Certificado mTLS (se o BB exigir em produção): caminhos dentro de storage/app.
        'mtls' => [
            'certificado' => env('BB_MTLS_CERT'),
            'chave' => env('BB_MTLS_KEY'),
            'senha' => env('BB_MTLS_SENHA'),
        ],

        // Datas da requisição: DDMMAAAA, sem zero à esquerda no dia.
        'data_sem_zero_a_esquerda' => (bool) env('BB_DATA_SEM_ZERO', true),

        'registros_por_pagina' => (int) env('BB_REGISTROS_POR_PAGINA', 200),
        'timeout' => (int) env('BB_TIMEOUT', 30),

        // O job importa sempre de hoje − N dias até hoje (lançamentos publicados com atraso).
        'dias_retroativos' => 3,

        // Linhas que não são lançamento (saldos) e devem ser ignoradas.
        'historicos_ignorados' => ['Saldo Anterior', 'S A L D O', 'Saldo do dia', 'SALDO'],
    ],

    /*
    | Conciliação de boletos (regra 17): débito com valor exato e vencimento
    | da parcela entre data do débito − antes e + depois.
    */
    'conciliacao' => [
        'dias_antes' => 5,
        'dias_depois' => 3,
    ],

];
