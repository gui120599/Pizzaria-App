<?php

namespace App\Services\Ponto;

use App\Enums\Ponto\SituacaoDia;
use App\Enums\Ponto\TipoAjuste;
use App\Enums\Ponto\TipoEscala;
use App\Models\Colaborador;
use App\Models\Escala;
use App\Models\EscalaDia;
use App\Models\Feriado;
use App\Models\Pedido;
use App\Models\PontoDia;
use App\Models\PontoPeriodo;
use App\Support\DiaOperacional;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Apuração diária (spec §3.6/§5.3): grava ponto_dias a partir das marcações
 * efetivas, da escala, dos feriados e dos ajustes aprovados. Tempo sempre
 * em minutos inteiros. Período travado não é reapurado.
 *
 * Hora noturna reduzida: o acréscimo (52'30" → 60') entra no trabalhado e
 * também no previsto da parte noturna da escala — senão todo turno noturno
 * cumprido à risca geraria hora extra.
 */
class ApuracaoService
{
    public const MARCACAO_FALTANTE = 'marcacao_faltante';

    public const INTERVALO_INSUFICIENTE = 'intervalo_insuficiente';

    public const JORNADA_EXCEDIDA = 'jornada_excedida_10h';

    public const FORA_DA_CERCA = 'fora_da_cerca';

    public function __construct(
        private readonly MarcacoesDoDia $marcacoes,
        private readonly PeriodosPonto $periodos,
    ) {}

    public function apurarDia(Colaborador $colaborador, CarbonInterface $dia): ?PontoDia
    {
        $dia = CarbonImmutable::instance($dia)->startOfDay();
        $existente = PontoDia::query()
            ->where('colaborador_id', $colaborador->id)
            ->whereDate('data_referencia', $dia->toDateString())
            ->first();
        $periodo = $this->periodos->periodoDe($colaborador, $dia);

        if ($periodo?->estaTravado() || ! $colaborador->estaAtivoEm($dia)) {
            return $existente;
        }

        $escala = $colaborador->escalaVigente($dia);
        $efetivas = $this->marcacoes->efetivas($colaborador, $dia);

        if ($escala === null && $efetivas->isEmpty()) {
            return $existente;
        }

        $reduzida = $colaborador->remuneracaoVigente($dia)?->considera_hora_noturna_reduzida ?? true;

        return PontoDia::updateOrCreate(
            ['colaborador_id' => $colaborador->id, 'data_referencia' => $dia->toDateString()],
            [
                ...$this->calcular($colaborador, $dia, $escala, $efetivas, $reduzida),
                'ponto_periodo_id' => $periodo?->id,
                'entregas_qtd' => $this->contarEntregas($colaborador, $dia),
                'apurado_em' => now(),
            ],
        );
    }

    /** @return Collection<int, PontoDia> */
    public function apurarPeriodo(PontoPeriodo $periodo): Collection
    {
        $colaboradores = Colaborador::query()->doPeriodo($periodo)->get();

        $dias = collect();

        foreach ($colaboradores as $colaborador) {
            for ($dia = CarbonImmutable::instance($periodo->data_inicio); $dia->lte($periodo->data_fim); $dia = $dia->addDay()) {
                $apurado = $this->apurarDia($colaborador, $dia);

                if ($apurado !== null) {
                    $dias->push($apurado);
                }
            }
        }

        return $dias;
    }

    /** Minutos de relógio do intervalo dentro da janela noturna (22h–05h), inclusive turnos que cruzam a meia-noite. */
    public function calcularNoturno(CarbonInterface $inicio, CarbonInterface $fim): int
    {
        $inicio = CarbonImmutable::instance($inicio);
        $fim = CarbonImmutable::instance($fim);

        if ($fim->lessThanOrEqualTo($inicio)) {
            return 0;
        }

        $segundos = 0;

        for ($dia = $inicio->subDay()->startOfDay(); $dia->lessThanOrEqualTo($fim); $dia = $dia->addDay()) {
            $janelaInicio = $dia->setTimeFromTimeString((string) config('ponto.noturno_inicio', '22:00'));
            $janelaFim = $dia->addDay()->setTimeFromTimeString((string) config('ponto.noturno_fim', '05:00'));

            $de = $inicio->max($janelaInicio);
            $ate = $fim->min($janelaFim);

            if ($ate->greaterThan($de)) {
                $segundos += $ate->getTimestamp() - $de->getTimestamp();
            }
        }

        return intdiv($segundos, 60);
    }

    /** Diferença entre a hora noturna reduzida (52'30") e a de relógio: 210 min noturnos valem 240. */
    public function acrescimoNoturno(int $minutosNoturnos): int
    {
        return (int) round($minutosNoturnos * 60 / 52.5) - $minutosNoturnos;
    }

    /**
     * @param  Collection<int, MarcacaoEfetiva>  $efetivas
     * @return array<string, mixed>
     */
    private function calcular(Colaborador $colaborador, CarbonImmutable $dia, ?Escala $escala, Collection $efetivas, bool $reduzida): array
    {
        $ajustes = $this->marcacoes->ajustesAprovados($colaborador, $dia);
        $feriado = Feriado::ehFeriado($dia);
        $livre = $escala === null || $escala->tipo->semPrevisto();
        $escalaDia = $escala?->diaDe($dia);
        $folgaCompensatoria = $ajustes->contains('tipo', TipoAjuste::FolgaCompensatoria);
        $trabalhaNaEscala = ! $livre && ! $feriado && ! $folgaCompensatoria && $escalaDia?->trabalha();

        $previsto = $trabalhaNaEscala
            ? $escalaDia->minutosPrevistos() + ($reduzida ? $this->acrescimoNoturno($this->calcularNoturno($escalaDia->entradaEm($dia), $escalaDia->saidaEm($dia))) : 0)
            : 0;

        $abonoDiaInteiro = $ajustes->contains(fn ($ajuste): bool => $ajuste->tipo === TipoAjuste::FaltaJustificada
            || ($ajuste->tipo === TipoAjuste::Abono && $ajuste->minutos === null));
        $abonoMinutos = (int) $ajustes->where('tipo', TipoAjuste::Abono)->whereNotNull('minutos')->sum('minutos');

        $resultado = [
            'situacao' => SituacaoDia::Normal,
            'minutos_previstos' => $previsto,
            'minutos_trabalhados' => 0,
            'minutos_intervalo' => 0,
            'minutos_noturnos' => 0,
            'minutos_acrescimo_noturno' => 0,
            'minutos_extra_50' => 0,
            'minutos_extra_100' => 0,
            'minutos_atraso' => 0,
            'minutos_falta' => 0,
            'minutos_abonados' => 0,
            'inconsistencias' => [],
        ];

        if ($efetivas->isEmpty()) {
            return [...$resultado, ...$this->diaSemMarcacao($escala, $previsto, $feriado, $abonoDiaInteiro, $abonoMinutos)];
        }

        $inconsistencias = $efetivas->contains(fn (MarcacaoEfetiva $efetiva): bool => $efetiva->foraDaCerca())
            ? [self::FORA_DA_CERCA]
            : [];

        $momentos = $efetivas->pluck('momento')->values();
        $pares = $momentos->chunk(2)->filter(fn (Collection $par): bool => $par->count() === 2)->map->values();

        $relogio = 0;
        $noturno = 0;

        foreach ($pares as $par) {
            $relogio += self::minutos($par[0], $par[1]);
            $noturno += $this->calcularNoturno($par[0], $par[1]);
        }

        $intervalo = 0;

        for ($i = 1; $i < $pares->count(); $i++) {
            $intervalo += self::minutos($pares[$i - 1][1], $pares[$i][0]);
        }

        $acrescimo = $reduzida ? $this->acrescimoNoturno($noturno) : 0;
        $trabalhado = $relogio + $acrescimo;

        $resultado = [
            ...$resultado,
            'minutos_trabalhados' => $trabalhado,
            'minutos_intervalo' => $intervalo,
            'minutos_noturnos' => $noturno,
            'minutos_acrescimo_noturno' => $acrescimo,
        ];

        if ($momentos->count() % 2 === 1) {
            return [
                ...$resultado,
                'situacao' => SituacaoDia::Incompleto,
                'inconsistencias' => [self::MARCACAO_FALTANTE, ...$inconsistencias],
            ];
        }

        if ($intervalo < $this->intervaloExigido($relogio, $escala)) {
            $inconsistencias[] = self::INTERVALO_INSUFICIENTE;
        }

        if ($trabalhado > (int) config('ponto.jornada_maxima_minutos', 600)) {
            $inconsistencias[] = self::JORNADA_EXCEDIDA;
        }

        if (! $livre) {
            if ($previsto === 0) {
                // Feriado, folga (inclusive domingo de folga) ou folga compensatória trabalhados.
                $resultado['minutos_extra_100'] = $trabalhado;
            } else {
                $diferenca = $this->dentroDaTolerancia($escala, $escalaDia, $dia, $momentos, $trabalhado - $previsto)
                    ? 0
                    : $trabalhado - $previsto;

                if ($diferenca > 0) {
                    $resultado['minutos_extra_50'] = $diferenca;
                } elseif ($diferenca < 0) {
                    $faltante = -$diferenca;
                    $abonado = $abonoDiaInteiro ? $faltante : min($abonoMinutos, $faltante);

                    $resultado['minutos_abonados'] = $abonado;
                    $resultado['minutos_atraso'] = $faltante - $abonado;
                }
            }
        }

        return [
            ...$resultado,
            'situacao' => $inconsistencias === [] ? SituacaoDia::Normal : SituacaoDia::Inconsistente,
            'inconsistencias' => $inconsistencias,
        ];
    }

    /** @return array<string, mixed> */
    private function diaSemMarcacao(?Escala $escala, int $previsto, bool $feriado, bool $abonoDiaInteiro, int $abonoMinutos): array
    {
        if ($previsto === 0) {
            return ['situacao' => $feriado ? SituacaoDia::Feriado : SituacaoDia::Folga];
        }

        // Ponto por exceção: sem ocorrência registrada, vale a jornada da escala.
        if ($escala->tipo === TipoEscala::PorExcecao) {
            return ['minutos_trabalhados' => $previsto];
        }

        if ($abonoDiaInteiro) {
            return ['situacao' => SituacaoDia::Abonado, 'minutos_abonados' => $previsto];
        }

        $abonado = min($abonoMinutos, $previsto);
        $falta = $previsto - $abonado;

        return [
            'situacao' => $falta > 0 ? SituacaoDia::Falta : SituacaoDia::Abonado,
            'minutos_abonados' => $abonado,
            'minutos_falta' => $falta,
        ];
    }

    /**
     * CLT art. 58 §1º: variação de até N min em cada marcação de entrada/saída
     * e até M min no dia não conta. Passou do limite, conta a diferença inteira.
     *
     * @param  Collection<int, CarbonImmutable>  $momentos
     */
    private function dentroDaTolerancia(Escala $escala, EscalaDia $escalaDia, CarbonImmutable $dia, Collection $momentos, int $diferenca): bool
    {
        $desvioEntrada = self::minutos($momentos->first(), $escalaDia->entradaEm($dia));
        $desvioSaida = self::minutos($momentos->last(), $escalaDia->saidaEm($dia));

        return $desvioEntrada <= $escala->tolerancia_marcacao_min
            && $desvioSaida <= $escala->tolerancia_marcacao_min
            && $desvioEntrada + $desvioSaida <= $escala->tolerancia_diaria_min
            && abs($diferenca) <= $escala->tolerancia_diaria_min;
    }

    /** CLT art. 71: acima de 6h exige o intervalo da escala (padrão 1h); de 4h a 6h, 15 min. */
    private function intervaloExigido(int $minutosRelogio, ?Escala $escala): int
    {
        return match (true) {
            $minutosRelogio > 360 => $escala?->intervalo_minimo_min ?? 60,
            $minutosRelogio > 240 => 15,
            default => 0,
        };
    }

    /** Entregas feitas pelo colaborador entregador no dia operacional (pedidos.pedido_usuario_entrega_id). */
    private function contarEntregas(Colaborador $colaborador, CarbonImmutable $dia): int
    {
        if (! $colaborador->entregador || $colaborador->user_id === null) {
            return 0;
        }

        return Pedido::query()
            ->where('pedido_usuario_entrega_id', $colaborador->user_id)
            ->whereIn('pedido_status', ['ENTREGUE', 'FINALIZADO'])
            ->where('pedido_datahora_entrega', '>=', DiaOperacional::inicio($dia))
            ->where('pedido_datahora_entrega', '<', DiaOperacional::fim($dia))
            ->count();
    }

    /** Minutos inteiros (para baixo) entre dois instantes, sem sinal. */
    private static function minutos(CarbonInterface $a, CarbonInterface $b): int
    {
        return intdiv(abs($b->getTimestamp() - $a->getTimestamp()), 60);
    }
}
