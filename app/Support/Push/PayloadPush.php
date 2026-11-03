<?php

namespace App\Support\Push;

/**
 * Corpo (cifrado de ponta a ponta pelo Web Push) lido pelo Service Worker.
 * Sem dados sensíveis: só texto do aviso, URL interna do painel e a chave do
 * evento.
 */
final class PayloadPush
{
    /**
     * @return array{titulo: string, corpo: string, url: string, tag: string, chave: string, criado_em: int, icone: string, badge: string}
     */
    public static function montar(string $titulo, string $corpo, string $url, string $tag, string $chave): array
    {
        return [
            'titulo' => $titulo,
            'corpo' => $corpo,
            'url' => $url,
            'tag' => $tag,
            'chave' => $chave,
            'criado_em' => now()->getTimestampMs(),
            'icone' => asset('img/razelfood/icon-192.png'),
            'badge' => asset('img/razelfood/badge-96.png'),
        ];
    }
}
