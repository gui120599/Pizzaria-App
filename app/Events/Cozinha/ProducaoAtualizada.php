<?php

namespace App\Events\Cozinha;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Driver "reverb" do CozinhaTempoReal: avisa os tablets dos setores e o
 * Painel de Pedidos. Só é disparado com config cozinha.tempo_real.driver =
 * reverb (o projeto ainda não tem broadcasting configurado).
 */
class ProducaoAtualizada implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /** @param  list<int>  $setorIds */
    public function __construct(public array $setorIds, public ?int $pedidoId) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            ...array_map(fn (int $id) => new PrivateChannel("cozinha.setor.{$id}"), $this->setorIds),
            new PrivateChannel('pedidos'),
        ];
    }
}
