<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // $schedule->command('inspire')->hourly();

        // Zera o saldo das promoções relâmpago recorrentes a cada nova
        // ocorrência (ver App\Console\Commands\ResetarPromocoesRecorrentes).
        $schedule->command('promocoes:resetar-recorrentes')->everyMinute();

        // Busca novas NF-e na SEFAZ por NSU (ver App\Console\Commands\SefazImportarNovasNotas).
        // Intervalo horário respeita a recomendação da SEFAZ de não martelar o
        // distNSU; withoutOverlapping por ser a primeira chamada de rede lenta
        // agendada no projeto (SOAP pode demorar/travar).
        $schedule->command('sefaz:importar-novas-notas')
            ->hourly()
            ->withoutOverlapping()
            ->onOneServer();

        // Gera o lançamento mensal de cada contrato ativo/vigente (ver
        // App\Console\Commands\ContratosGerarLancamentosMensais e ContratoService).
        // Roda diariamente (não só dia 1) para ser resiliente a falha pontual do
        // schedule — a idempotência por competência garante que só gera 1x/mês.
        $schedule->command('contratos:gerar-lancamentos')
            ->dailyAt('02:00')
            ->withoutOverlapping()
            ->onOneServer();

        // Rede de segurança para vendas INICIADA vazias que o beforeunload/
        // sendBeacon do PDV não conseguiu cancelar (ver OperarVenda e
        // App\Console\Commands\CancelarVendasIniciadasVazias).
        $schedule->command('vendas:cancelar-iniciadas-vazias')
            ->hourly()
            ->withoutOverlapping()
            ->onOneServer();

        // Rede de segurança do recebimento em maquininha Stone: fecha pedidos
        // pagos que não fecharam no handler do webhook e recupera charge.paid
        // perdidos (ver App\Console\Commands\StoneConciliarPedidos).
        $schedule->command('stone:conciliar-pedidos')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->onOneServer();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
