<?php

namespace App\Jobs;

use App\Models\PushEnvio;
use App\Services\Push\DispositivoPushService;
use App\Services\Push\EnviadorPush;
use App\Support\Push\PendenciaPush;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envia os pushes já registrados em push_envios. Roda depois da resposta HTTP
 * (dispatchAfterResponse), então não atrasa quem originou o aviso. Antes de
 * enviar revalida: aparelho ainda apto, usuário ainda do salão, prazo não
 * vencido e assunto ainda pendente.
 */
class EnviarPushGarcomJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  list<int>  $envioIds
     */
    public function __construct(public array $envioIds) {}

    public function handle(EnviadorPush $enviador, DispositivoPushService $dispositivos): void
    {
        PushEnvio::query()
            ->with('dispositivo.usuario')
            ->whereKey($this->envioIds)
            ->where(fn ($q) => $q
                ->where('pe_status', PushEnvio::PENDENTE)
                ->orWhere(fn ($falhou) => $falhou->where('pe_status', PushEnvio::FALHOU)->where('pe_tentativas', '<', PushEnvio::MAX_TENTATIVAS)))
            ->get()
            ->each(function (PushEnvio $envio) use ($enviador, $dispositivos): void {
                try {
                    $this->processar($envio, $enviador, $dispositivos);
                } catch (Throwable $e) {
                    Log::warning('Push: falha ao enviar.', ['envio_id' => $envio->id, 'erro' => $e->getMessage()]);
                    $envio->forceFill(['pe_status' => PushEnvio::FALHOU, 'pe_erro' => mb_substr($e->getMessage(), 0, 255)])->save();
                }
            });
    }

    private function processar(PushEnvio $envio, EnviadorPush $enviador, DispositivoPushService $dispositivos): void
    {
        $dispositivo = $envio->dispositivo;

        if ($dispositivo === null || ! $dispositivo->dp_ativo || $dispositivo->estaPausado()) {
            $this->encerrar($envio, PushEnvio::DESCARTADO, 'Aparelho indisponível.');

            return;
        }

        if (! $dispositivo->usuario?->atendeSalao()) {
            $dispositivos->desativar($dispositivo, 'sem_permissao');
            $this->encerrar($envio, PushEnvio::DESCARTADO, 'Usuário sem acesso ao salão.');

            return;
        }

        if ($envio->pe_expira_em->isPast()) {
            $this->encerrar($envio, PushEnvio::EXPIRADO, 'Prazo vencido.');

            return;
        }

        if (! PendenciaPush::aindaVale($envio->pe_chave)) {
            $this->encerrar($envio, PushEnvio::DESCARTADO, 'Assunto já resolvido.');

            return;
        }

        $resultado = $enviador->enviar(
            $dispositivo,
            $envio->pe_payload,
            max(1, (int) now()->diffInSeconds($envio->pe_expira_em, false)),
            'high',
            $envio->pe_payload['tag'] ?? null,
        );

        $dispositivos->aplicarResultado($envio, $dispositivo, $resultado);
    }

    private function encerrar(PushEnvio $envio, string $status, string $motivo): void
    {
        $envio->forceFill(['pe_status' => $status, 'pe_erro' => $motivo])->save();
    }
}
