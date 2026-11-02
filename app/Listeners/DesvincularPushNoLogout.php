<?php

namespace App\Listeners;

use App\Services\Push\DispositivoPushService;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Trocar garçom / Sair": o aparelho (compartilhado) deixa de receber os
 * avisos de quem saiu até a próxima sincronização no login.
 */
class DesvincularPushNoLogout
{
    public function __construct(private readonly DispositivoPushService $dispositivos) {}

    public function handle(Logout $evento): void
    {
        try {
            $this->dispositivos->desvincularDaSessao();
        } catch (Throwable $e) {
            Log::warning('Push: falha ao desvincular o aparelho no logout.', ['erro' => $e->getMessage()]);
        }
    }
}
