<?php

namespace App\Http\Controllers\Garcom;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

/**
 * PWA do Painel do Garçom: manifest e Service Worker servidos por rota (nunca
 * por public/garcom/, que o .htaccess serviria no lugar da rota /garcom).
 * Sem sessão: o navegador busca os dois sem login garantido. Desligado
 * (PIZZARIA_PUSH_ATIVO=false), respondem 404.
 */
class PwaGarcomController extends Controller
{
    public function manifest(): JsonResponse
    {
        abort_unless(config('pizzaria.push.ativo'), 404);

        $icones = [['src' => asset('img/marca/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png']];

        if (file_exists(public_path('img/marca/icon-512.png'))) {
            $icones[] = ['src' => asset('img/marca/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png'];
        } else {
            $icones[] = ['src' => asset('favicon.png'), 'sizes' => '1080x1080', 'type' => 'image/png'];
        }

        if (file_exists(public_path('img/marca/icon-maskable-512.png'))) {
            $icones[] = ['src' => asset('img/marca/icon-maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'];
        }

        return response()->json([
            'id' => '/garcom/',
            'name' => config('app.name').' — Salão',
            'short_name' => 'Salão',
            'start_url' => '/garcom',
            'scope' => '/garcom/',
            'display' => 'standalone',
            'orientation' => 'portrait',
            'background_color' => '#ffffff',
            'theme_color' => '#c2410c',
            'icons' => $icones,
        ], headers: ['Content-Type' => 'application/manifest+json']);
    }

    public function serviceWorker(): Response
    {
        abort_unless(config('pizzaria.push.ativo'), 404);

        return response(File::get(resource_path('js/garcom-sw.js')), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Service-Worker-Allowed' => '/garcom/',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }
}
