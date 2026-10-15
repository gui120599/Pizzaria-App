<?php

namespace App\Console\Commands;

use App\Services\Ponto\AlertasPonto;
use Illuminate\Console\Command;

/** Avisa entrada não marcada (gestores) e marcação sem saída após o turno (colaborador). */
class PontoAlertarFaltantes extends Command
{
    protected $signature = 'ponto:alertar-faltantes';

    protected $description = 'Envia avisos de marcação de ponto faltante';

    public function handle(AlertasPonto $alertas): int
    {
        $enviados = $alertas->alertarFaltantes(now());

        $this->info("{$enviados} aviso(s) enviado(s).");

        return self::SUCCESS;
    }
}
