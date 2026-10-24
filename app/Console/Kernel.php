<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Carbon;

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

        // Reconcilia com a NFe.io as NFC-e que ficaram paradas por webhook perdido
        // (ver App\Console\Commands\NfeSincronizarStatus): só vendas dos últimos 7
        // dias, pendentes há mais de 10 min, no máximo 100 consultas por rodada.
        $schedule->command('nfe:sincronizar-status --dias=7 --minutos=10 --limite=100')
            ->hourly()
            ->withoutOverlapping()
            ->onOneServer();

        // Avisa o gerente sobre venda reaberta para alteração há mais de N horas
        // (ver App\Console\Commands\VendasAlertarAlteracoesAbertas e config/vendas.php).
        $schedule->command('vendas:alertar-alteracoes-abertas')
            ->everyFifteenMinutes()
            ->withoutOverlapping()
            ->onOneServer();

        // Exportação de pedidos (Filament): consome só a fila dela e sai quando esvazia. Sem este
        // worker o arquivo nunca é gerado nem a notificação de download chega. A fila padrão
        // (NFC-e automática) não é tocada; ver config pizzaria.pedidos.exportacao. "sync" (testes
        // e dev) executa na hora e não tem worker.
        if (config('pizzaria.pedidos.exportacao.conexao') !== 'sync') {
            $schedule->command(sprintf(
                'queue:work %s --queue=%s --stop-when-empty --max-time=55',
                config('pizzaria.pedidos.exportacao.conexao'),
                config('pizzaria.pedidos.exportacao.fila'),
            ))
                ->everyMinute()
                ->withoutOverlapping(10)
                ->onOneServer();
        }

        // Rede de segurança do recebimento em maquininha Stone: fecha pedidos
        // pagos que não fecharam no handler do webhook e recupera charge.paid
        // perdidos (ver App\Console\Commands\StoneConciliarPedidos).
        $schedule->command('stone:conciliar-pedidos')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->onOneServer();

        // Cartão/Pix de maquininha entra no extrato da conta só na data de repasse:
        // liquida os recebíveis vencidos e lança a taxa (ver LiquidacaoRecebiveisService).
        $schedule->command('financeiro:liquidar-recebiveis')
            ->dailyAt('06:00')
            ->withoutOverlapping()
            ->onOneServer();

        // Execuções pendentes de checklist do dia operacional — logo após a
        // virada do dia (config checklists.virada_dia), não à meia-noite: o
        // fechamento feito de madrugada ainda pertence ao dia anterior.
        $schedule->command('checklists:gerar-pendentes')
            ->dailyAt(Carbon::parse((string) config('checklists.virada_dia'))->addMinutes(5)->format('H:i'))
            ->withoutOverlapping()
            ->onOneServer();

        // Checklists que passaram do fim da janela + tolerância viram "não realizado".
        $schedule->command('checklists:expirar')
            ->everyFifteenMinutes()
            ->withoutOverlapping()
            ->onOneServer();

        // Ponto: reapura o dia operacional que acabou de virar (config operacao.virada_dia).
        $schedule->command('ponto:apurar')
            ->dailyAt(Carbon::parse((string) config('operacao.virada_dia'))->addMinutes(15)->format('H:i'))
            ->withoutOverlapping()
            ->onOneServer();

        // Ponto: entrada não marcada (gestores) e marcação sem saída após o turno (colaborador).
        $schedule->command('ponto:alertar-faltantes')
            ->everyThirtyMinutes()
            ->withoutOverlapping()
            ->onOneServer();

        // Ponto: garante o período atual e o próximo de cada periodicidade em uso.
        $schedule->command('ponto:abrir-periodos')
            ->dailyAt('00:10')
            ->withoutOverlapping()
            ->onOneServer();

        // Ponto: apaga selfies além da retenção (LGPD); a marcação continua.
        $schedule->command('ponto:limpar-selfies')
            ->dailyAt('03:30')
            ->withoutOverlapping()
            ->onOneServer();

        // Cozinha: libera os itens segurados pelo disparo por tempo de preparo (no-op se desligado).
        $schedule->command('cozinha:liberar-itens')
            ->everyMinute()
            ->withoutOverlapping()
            ->onOneServer();

        // Cozinha: avisa o gerente sobre itens muito atrasados (uma vez por item).
        $schedule->command('cozinha:alertar-atrasos')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->onOneServer();

        // Ponto: confere a cadeia de hash das marcações.
        $schedule->command('ponto:verificar-integridade')
            ->weeklyOn(1, '04:00')
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
