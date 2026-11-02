<?php

namespace App\Console\Commands;

use App\Models\DispositivoPush;
use App\Models\PushEnvio;
use Illuminate\Console\Command;

class LimparPush extends Command
{
    protected $signature = 'push:limpar';

    protected $description = 'Apaga envios de push com mais de 30 dias e aparelhos desativados há mais de 30 dias; encerra pausas vencidas';

    public function handle(): int
    {
        $envios = PushEnvio::query()->where('created_at', '<', now()->subDays(30))->delete();

        $aparelhos = DispositivoPush::query()
            ->where('dp_ativo', false)
            ->where('dp_desativado_em', '<', now()->subDays(30))
            ->delete();

        DispositivoPush::query()->where('dp_pausado_ate', '<=', now())->update(['dp_pausado_ate' => null]);

        $this->info("Envios apagados: {$envios}. Aparelhos apagados: {$aparelhos}.");

        return self::SUCCESS;
    }
}
