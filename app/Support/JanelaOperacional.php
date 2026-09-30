<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Janela de tempo do turno operacional.
 *
 * O expediente atravessa a meia-noite (abre às 07:00, fecha às 03:00 do dia
 * seguinte), então "hoje" não é o dia do calendário: um pedido entregue à 01:30
 * pertence ao turno que começou na manhã anterior. Esta regra estava hardcoded
 * em PedidoController::PedidosEntregueLista, com um comentário que falava em
 * 17:00 enquanto o código usava 07:00.
 *
 * Os horários vêm de config('pizzaria.janela_operacional').
 */
class JanelaOperacional
{
    /**
     * Janela do turno que contém o instante informado (padrão: agora).
     *
     * @return array{0: Carbon, 1: Carbon} [início, fim]
     */
    public static function atual(?Carbon $referencia = null): array
    {
        return self::paraData(($referencia ?? Carbon::now())->copy());
    }

    /**
     * Janela do turno que ABRE na data informada — aceita 'Y-m-d' ou Carbon.
     *
     * Diferente de atual(): aqui a data é o dia de abertura do turno, sem
     * inferência. É o que o filtro de data da coluna "Entregue" precisa.
     *
     * @return array{0: Carbon, 1: Carbon} [início, fim]
     */
    public static function paraDiaDeAbertura(Carbon|string $data): array
    {
        $dia = $data instanceof Carbon ? $data->copy() : Carbon::parse($data);

        $inicio = $dia->copy()->setTimeFromTimeString(self::abertura());
        $fim = $inicio->copy()->addDay()->setTimeFromTimeString(self::fechamento());

        return [$inicio, $fim];
    }

    /**
     * Resolve a qual turno o instante pertence: antes do horário de abertura,
     * ainda estamos na madrugada do turno que abriu no dia anterior.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private static function paraData(Carbon $instante): array
    {
        $aberturaDeHoje = $instante->copy()->setTimeFromTimeString(self::abertura());

        $diaDeAbertura = $instante->lessThan($aberturaDeHoje)
            ? $instante->copy()->subDay()
            : $instante;

        return self::paraDiaDeAbertura($diaDeAbertura);
    }

    private static function abertura(): string
    {
        return (string) config('pizzaria.janela_operacional.abertura', '07:00');
    }

    private static function fechamento(): string
    {
        return (string) config('pizzaria.janela_operacional.fechamento', '03:00');
    }
}
