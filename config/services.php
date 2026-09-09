<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'recaptcha' => [
        'site_key' => env('RECAPTCHA_SITE_KEY', ''),
        'secret' => env('RECAPTCHA_SECRET_KEY', ''),
    ],

    'nfeio' => [
        'webhook_secret' => env('NFE_IO_SECRET'),
    ],

    'stone' => [
        // API Connect Stone (roda sobre o Pagar.me v5 — api.pagar.me/core/v5)
        'base_url' => env('STONE_CONNECT_BASE_URL', 'https://api.pagar.me/core/v5'),
        'secret_key' => env('STONE_CONNECT_SECRET_KEY'),
        'service_referer_name' => env('STONE_CONNECT_SERVICE_REFERER_NAME'),
        // Pedido Direto (payment_setup no POST /orders) exige a conta credenciada
        // para o modelo Direto no Stone Partner Hub. Se ainda não estiver, deixar
        // false: o PDV cai para o modelo Listado sem precisar de deploy.
        'pedido_direto' => env('STONE_CONNECT_PEDIDO_DIRETO', true),
        // HTTP Basic Auth da URL de webhook configurada na Dashboard do Pagar.me
        'webhook_user' => env('STONE_WEBHOOK_USER'),
        'webhook_password' => env('STONE_WEBHOOK_PASSWORD'),
    ],

];
