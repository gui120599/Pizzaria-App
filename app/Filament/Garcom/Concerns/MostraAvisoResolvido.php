<?php

namespace App\Filament\Garcom\Concerns;

use App\Services\Push\AvisoResolvidoService;
use App\Services\Push\DispositivoPushService;
use Filament\Notifications\Notification;

/**
 * Página aberta pelo toque numa notificação push (`?aviso=tipo:id`): se o
 * assunto já foi resolvido, diz quem resolveu em vez de deixar o garçom
 * procurando o chamado.
 */
trait MostraAvisoResolvido
{
    protected function avisarSeJaResolvido(): void
    {
        if (! DispositivoPushService::ativo()) {
            return;
        }

        $mensagem = app(AvisoResolvidoService::class)->mensagem(request()->query('aviso'));

        if ($mensagem !== null) {
            Notification::make()->info()->title($mensagem)->send();
        }
    }
}
