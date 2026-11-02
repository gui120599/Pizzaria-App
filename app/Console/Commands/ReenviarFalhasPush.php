<?php

namespace App\Console\Commands;

use App\Jobs\EnviarPushGarcomJob;
use App\Models\PushEnvio;
use Illuminate\Console\Command;

class ReenviarFalhasPush extends Command
{
    protected $signature = 'push:reenviar-falhas';

    protected $description = 'Reenvia pushes que falharam por motivo transitório (até 3 tentativas, dentro do prazo) e fecha os vencidos';

    public function handle(): int
    {
        if (! config('pizzaria.push.ativo')) {
            return self::SUCCESS;
        }

        $vencidos = PushEnvio::query()
            ->whereIn('pe_status', [PushEnvio::PENDENTE, PushEnvio::FALHOU])
            ->where('pe_expira_em', '<=', now())
            ->update(['pe_status' => PushEnvio::EXPIRADO, 'pe_erro' => 'Prazo vencido.']);

        $reenviados = 0;

        PushEnvio::query()
            ->where('pe_expira_em', '>', now())
            ->where(fn ($q) => $q
                ->where(fn ($falhou) => $falhou
                    ->where('pe_status', PushEnvio::FALHOU)
                    ->where('pe_tentativas', '<', PushEnvio::MAX_TENTATIVAS)
                    ->where(fn ($transitoria) => $transitoria
                        ->whereNull('pe_http_status')
                        ->orWhere('pe_http_status', 429)
                        ->orWhere('pe_http_status', '>=', 500)))
                // Despacho que não chegou a rodar (o processo caiu depois do commit).
                ->orWhere(fn ($preso) => $preso
                    ->where('pe_status', PushEnvio::PENDENTE)
                    ->where('created_at', '<', now()->subMinute())))
            ->pluck('id')
            ->chunk(50)
            ->each(function ($ids) use (&$reenviados): void {
                EnviarPushGarcomJob::dispatchSync($ids->all());
                $reenviados += $ids->count();
            });

        $this->info("Reenviados: {$reenviados}. Vencidos: {$vencidos}.");

        return self::SUCCESS;
    }
}
