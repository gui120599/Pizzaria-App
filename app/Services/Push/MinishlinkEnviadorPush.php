<?php

namespace App\Services\Push;

use App\Models\DispositivoPush;
use App\Support\Push\ResultadoEnvioPush;
use GuzzleHttp\Client;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/** Envio real via minishlink/web-push (VAPID + cifragem RFC 8291). */
class MinishlinkEnviadorPush implements EnviadorPush
{
    private ?WebPush $webPush = null;

    public function enviar(DispositivoPush $dispositivo, array $payload, int $ttl, string $urgencia = 'high', ?string $topic = null): ResultadoEnvioPush
    {
        try {
            $opcoes = ['TTL' => max(0, $ttl), 'urgency' => $urgencia];

            if ($topic !== null) {
                $opcoes['topic'] = $topic;
            }

            $relatorio = $this->cliente()->sendOneNotification(
                Subscription::create([
                    'endpoint' => $dispositivo->dp_endpoint,
                    'publicKey' => $dispositivo->dp_chave_p256dh,
                    'authToken' => $dispositivo->dp_chave_auth,
                    'contentEncoding' => $dispositivo->dp_content_encoding,
                ]),
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $opcoes,
            );

            $status = $relatorio->getResponse()?->getStatusCode();

            if ($relatorio->isSuccess()) {
                return ResultadoEnvioPush::enviado($status ?? 201);
            }

            return ResultadoEnvioPush::falha($status, mb_substr($relatorio->getReason(), 0, 255));
        } catch (Throwable $e) {
            return ResultadoEnvioPush::falha(null, mb_substr($e->getMessage(), 0, 255));
        }
    }

    private function cliente(): WebPush
    {
        return $this->webPush ??= new WebPush([
            'VAPID' => [
                'subject' => (string) config('pizzaria.push.vapid.subject'),
                'publicKey' => (string) config('pizzaria.push.vapid.publica'),
                'privateKey' => (string) config('pizzaria.push.vapid.privada'),
            ],
        ], client: new Client(['timeout' => 15, 'connect_timeout' => 5]));
    }
}
