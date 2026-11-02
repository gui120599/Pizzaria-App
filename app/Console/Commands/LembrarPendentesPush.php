<?php

namespace App\Console\Commands;

use App\Services\Push\LembretePendentesService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class LembrarPendentesPush extends Command
{
    /** Marca de que o agendador está rodando; a tela de parâmetros avisa quando some. */
    public const CHAVE_BATIMENTO = 'push:agendador:ultimo_batimento';

    protected $signature = 'push:lembrar-pendentes';

    protected $description = 'Repete o aviso de chamados e pedidos para aprovar ainda pendentes (parâmetros do Admin)';

    public function handle(LembretePendentesService $lembretes): int
    {
        Cache::put(self::CHAVE_BATIMENTO, now()->timestamp, now()->addHours(2));

        if (! config('pizzaria.push.ativo')) {
            return self::SUCCESS;
        }

        $this->info('Lembretes enviados: '.$lembretes->executar().'.');

        return self::SUCCESS;
    }

    /** O cron schedule:run rodou nos últimos 5 minutos? */
    public static function agendadorAtivo(): bool
    {
        $ultimo = Cache::get(self::CHAVE_BATIMENTO);

        return $ultimo !== null && now()->timestamp - (int) $ultimo <= 300;
    }
}
