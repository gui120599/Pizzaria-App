<?php

namespace Tests\Support;

use App\Models\DispositivoPush;
use App\Services\Push\EnviadorPush;
use App\Support\Push\ResultadoEnvioPush;

/** Enviador de teste: registra o que seria enviado e devolve o resultado programado. Nunca toca a rede. */
class FakeEnviadorPush implements EnviadorPush
{
    /** @var list<array{dispositivo_id: int, payload: array<string, mixed>, ttl: int, urgencia: string, topic: ?string}> */
    public array $enviados = [];

    public ResultadoEnvioPush $resultado;

    public function __construct()
    {
        $this->resultado = ResultadoEnvioPush::enviado();
    }

    public function enviar(DispositivoPush $dispositivo, array $payload, int $ttl, string $urgencia = 'high', ?string $topic = null): ResultadoEnvioPush
    {
        $this->enviados[] = [
            'dispositivo_id' => $dispositivo->id,
            'payload' => $payload,
            'ttl' => $ttl,
            'urgencia' => $urgencia,
            'topic' => $topic,
        ];

        return $this->resultado;
    }
}
