<?php

namespace App\Livewire;

use App\Exceptions\PushException;
use App\Services\Push\DispositivoPushService;
use App\Support\Push\PendenciaPush;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Sincronização silenciosa da inscrição de push a cada abertura do painel do
 * garçom: com a permissão já concedida, regrava a inscrição no servidor e, em
 * aparelho compartilhado, passa o aparelho para quem entrou. Nunca pede
 * permissão (ver resources/js/garcom-push.js).
 */
class PushSincronizador extends Component
{
    /**
     * @param  array<string, mixed>  $inscricao  PushSubscription.toJSON()
     */
    public function sincronizar(array $inscricao, bool $instalado, DispositivoPushService $dispositivos): void
    {
        $usuario = Auth::user();

        if ($usuario === null) {
            return;
        }

        try {
            $dispositivos->registrar($usuario, $inscricao, request()->userAgent(), $instalado);
        } catch (PushException) {
            // Sem acesso ao salão ou endpoint recusado (já registrado em log): nada a mostrar ao garçom.
        }
    }

    /**
     * Assuntos ainda pendentes: o navegador fecha as notificações dos que já
     * foram resolvidos (atendidos em outro aparelho, pedido já servido).
     */
    public function pendencias(): void
    {
        $this->dispatch('garcom-push-pendencias', chaves: PendenciaPush::chavesAbertas());
    }

    public function render(): string
    {
        return <<<'HTML'
        <div wire:poll.15s="pendencias" x-data x-init="window.GarcomPush ? window.GarcomPush.sincronizar($wire) : window.addEventListener('garcom-push:pronto', () => window.GarcomPush.sincronizar($wire), { once: true })" class="hidden"></div>
        HTML;
    }
}
