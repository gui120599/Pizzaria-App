<?php

namespace App\Support;

use App\Models\HorarioFuncionamento;
use Illuminate\Support\Carbon;

/**
 * Janela de tempo do turno operacional.
 *
 * O expediente atravessa a meia-noite (ex.: abre às 07:00, fecha de madrugada
 * no dia seguinte), então "hoje" não é o dia do calendário: um pedido
 * entregue à 01:30 pertence ao turno que começou na manhã anterior. Esta
 * regra estava hardcoded em PedidoController::PedidosEntregueLista (07:00
 * fixo), com um comentário que nem batia com o código (falava em 17:00).
 *
 * A fonte agora é HorarioFuncionamento — que já existe no projeto e suporta
 * vários turnos por dia (ex. almoço + jantar). Sem nenhum horário cadastrado
 * (ou nenhum ativo), cai no horário fixo de
 * config('pizzaria.janela_operacional.abertura') — o mesmo padrão da v1.
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
        $inicio = self::inicioTurnoAtivo($referencia);

        return [$inicio, $inicio->copy()->addDay()];
    }

    /**
     * Janela do turno que ABRE na data informada — aceita 'Y-m-d' ou Carbon.
     *
     * Diferente de atual(): aqui a data é o dia de abertura do turno, sem
     * inferência de "a que turno pertence agora". É o que o filtro de data da
     * coluna final (Entregue/Finalizados) do Painel de Pedidos precisa.
     *
     * @return array{0: Carbon, 1: Carbon} [início, fim]
     */
    public static function paraDiaDeAbertura(Carbon|string $data): array
    {
        $dia = $data instanceof Carbon ? $data->copy() : Carbon::parse($data);

        $inicio = $dia->copy()->setTimeFromTimeString(self::horarioAberturaDoDia($dia->dayOfWeek));

        return [$inicio, $inicio->copy()->addDay()];
    }

    /**
     * A abertura mais recente que já começou (<= o instante informado),
     * dentre os horários de funcionamento ativos — pode haver mais de um por
     * dia (ex.: almoço + jantar), e a busca olha até 7 dias pra trás porque o
     * turno pode ter começado na madrugada de um dia anterior.
     *
     * O "início" de um turno é sempre dia-da-semana + horario_abertura, mesmo
     * quando o turno cruza a meia-noite (ex. 19h-02h) — não precisa do
     * tratamento especial de HorarioFuncionamento::estaAberto(), que resolve
     * "está aberto agora", um problema diferente de "quando começou o turno
     * mais recente".
     */
    public static function inicioTurnoAtivo(?Carbon $agora = null): Carbon
    {
        $agora = ($agora ?? Carbon::now())->copy();

        $horarios = HorarioFuncionamento::where('horario_ativo', true)->get();

        if ($horarios->isEmpty()) {
            return self::aberturaConfigNoDia($agora);
        }

        $maisRecente = null;

        foreach ($horarios as $horario) {
            for ($diasAtras = 0; $diasAtras <= 7; $diasAtras++) {
                $dia = $agora->copy()->subDays($diasAtras);

                if ($dia->dayOfWeek !== (int) $horario->horario_dia_semana) {
                    continue;
                }

                $inicio = $dia->copy()->setTimeFromTimeString($horario->horario_abertura);

                if ($inicio->greaterThan($agora)) {
                    continue;
                }

                if ($maisRecente === null || $inicio->greaterThan($maisRecente)) {
                    $maisRecente = $inicio;
                }

                break;
            }
        }

        return $maisRecente ?? self::aberturaConfigNoDia($agora);
    }

    /** Abertura mais cedo cadastrada pra este dia da semana (0-6, Carbon::dayOfWeek). */
    private static function horarioAberturaDoDia(int $diaDaSemana): string
    {
        $horario = HorarioFuncionamento::where('horario_dia_semana', $diaDaSemana)
            ->where('horario_ativo', true)
            ->orderBy('horario_abertura')
            ->first();

        return $horario->horario_abertura ?? self::aberturaConfig();
    }

    private static function aberturaConfigNoDia(Carbon $dia): Carbon
    {
        return $dia->copy()->setTimeFromTimeString(self::aberturaConfig());
    }

    private static function aberturaConfig(): string
    {
        return (string) config('pizzaria.janela_operacional.abertura', '07:00');
    }
}
