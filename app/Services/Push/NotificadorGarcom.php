<?php

namespace App\Services\Push;

use App\Jobs\EnviarPushGarcomJob;
use App\Models\DispositivoPush;
use App\Models\PushEnvio;
use App\Support\Push\EventoGarcom;
use App\Support\Push\PayloadPush;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Porta de entrada do push do salão. Os gatilhos chamam notificar() de dentro
 * da transação de negócio: o trabalho só roda depois do commit e qualquer
 * falha vira log — o push nunca derruba o fluxo que o originou.
 */
class NotificadorGarcom
{
    public function __construct(private readonly DestinatariosGarcom $destinatarios) {}

    public function notificar(EventoGarcom $evento): void
    {
        if (! DispositivoPushService::ativo()) {
            return;
        }

        try {
            DB::afterCommit(function () use ($evento): void {
                try {
                    $this->enfileirar($evento);
                } catch (Throwable $e) {
                    Log::warning('Push: falha ao enfileirar o aviso.', ['chave' => $evento->chave, 'erro' => $e->getMessage()]);
                }
            });
        } catch (Throwable $e) {
            Log::warning('Push: falha ao agendar o aviso.', ['chave' => $evento->chave, 'erro' => $e->getMessage()]);
        }
    }

    /**
     * Grava um envio por aparelho (único por chave + aparelho: ocorrência
     * repetida não duplica) e despacha o job.
     *
     * Aviso novo respeita o cooldown por mesa e tipo (rajada de chamados); lembrete não.
     *
     * @param  bool  $imediato  envia na hora (comandos agendados) em vez de depois da resposta HTTP
     * @return list<int> ids dos envios criados agora
     */
    public function enfileirar(EventoGarcom $evento, bool $imediato = false): array
    {
        $cooldown = (int) config('pizzaria.push.cooldown_segundos', 60);
        $chaveCooldown = "push:cooldown:{$evento->evento->value}:{$evento->tag}";

        if (! $evento->lembrete && $cooldown > 0 && Cache::has($chaveCooldown)) {
            Log::info('Push: aviso contido pelo cooldown.', ['chave' => $evento->chave]);

            return [];
        }

        $usuarios = $this->destinatarios->para($evento);

        if ($usuarios->isEmpty()) {
            Log::info('Push: nenhum destinatário para o aviso.', ['chave' => $evento->chave]);

            return [];
        }

        $dispositivos = DispositivoPush::query()->recebendo()->whereIn('dp_user_id', $usuarios->modelKeys())->get();
        $payload = PayloadPush::montar($evento->titulo, $evento->corpo, $evento->urlComAviso(), $evento->tag, $evento->chave);
        $expiraEm = now()->addSeconds($evento->evento->ttl());
        $agora = now();
        $ids = [];

        foreach ($dispositivos as $dispositivo) {
            $criado = PushEnvio::query()->insertOrIgnore([
                'pe_chave' => $evento->chave,
                'pe_evento' => $evento->evento->value,
                'pe_user_id' => $dispositivo->dp_user_id,
                'pe_dispositivo_id' => $dispositivo->id,
                'pe_status' => PushEnvio::PENDENTE,
                'pe_tentativas' => 0,
                'pe_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'pe_expira_em' => $expiraEm,
                'created_at' => $agora,
                'updated_at' => $agora,
            ]);

            if ($criado > 0) {
                $ids[] = (int) PushEnvio::query()
                    ->where('pe_chave', $evento->chave)
                    ->where('pe_dispositivo_id', $dispositivo->id)
                    ->value('id');
            }
        }

        if ($ids !== []) {
            if (! $evento->lembrete && $cooldown > 0) {
                Cache::put($chaveCooldown, true, $cooldown);
            }

            $this->despachar($ids, $imediato);
        }

        return $ids;
    }

    /**
     * @param  list<int>  $ids
     */
    private function despachar(array $ids, bool $imediato): void
    {
        if ($imediato || config('pizzaria.push.envio') === 'sync') {
            EnviarPushGarcomJob::dispatchSync($ids);

            return;
        }

        EnviarPushGarcomJob::dispatchAfterResponse($ids);
    }
}
