<?php

namespace App\Services\Push;

use App\Models\DispositivoPush;
use App\Support\Push\ResultadoEnvioPush;

/**
 * Fronteira com a biblioteca de Web Push: o resto do sistema só conhece esta
 * interface (nos testes, FakeEnviadorPush).
 */
interface EnviadorPush
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  int  $ttl  segundos que o serviço de push retém a mensagem
     * @param  string|null  $topic  mensagem nova com o mesmo topic substitui a pendente
     */
    public function enviar(DispositivoPush $dispositivo, array $payload, int $ttl, string $urgencia = 'high', ?string $topic = null): ResultadoEnvioPush;
}
