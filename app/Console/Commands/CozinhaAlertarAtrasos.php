<?php

namespace App\Console\Commands;

use App\Enums\Cozinha\StatusItemProducao;
use App\Models\ProducaoItem;
use App\Notifications\Cozinha\ProducaoAtrasadaNotification;
use App\Services\Cozinha\ProducaoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/** Avisa o gerente (uma vez por item) quando a idade passa de N × o tempo-alvo do setor. */
class CozinhaAlertarAtrasos extends Command
{
    protected $signature = 'cozinha:alertar-atrasos';

    protected $description = 'Notifica os gestores sobre itens de produção muito atrasados';

    public function handle(): int
    {
        $multiplicador = (int) config('cozinha.alerta_atraso_multiplicador', 2);
        $gestores = ProducaoService::gestores();
        $alertados = 0;

        $itens = ProducaoItem::with('setor')
            ->whereIn('status', StatusItemProducao::valoresAtivosNaEstacao())
            ->whereNotNull('liberado_em')
            ->get();

        foreach ($itens as $item) {
            $limite = (int) ($item->setor?->tempo_alvo_min ?? 15) * $multiplicador * 60;

            if ($item->idadeSegundos() < $limite || ! Cache::add("cozinha:atraso-notificado:{$item->id}", true, now()->addDay())) {
                continue;
            }

            Notification::send($gestores, new ProducaoAtrasadaNotification($item));
            $alertados++;
        }

        $this->info("{$alertados} item(ns) atrasado(s) notificado(s).");

        return self::SUCCESS;
    }
}
