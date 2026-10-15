<?php

namespace App\Services\Ponto;

use App\Enums\Ponto\OrigemAjuste;
use App\Enums\Ponto\SituacaoDia;
use App\Enums\Ponto\StatusAjuste;
use App\Models\Colaborador;
use App\Models\EscalaDia;
use App\Models\PontoAjuste;
use App\Models\PontoDia;
use App\Models\PontoMarcacao;
use App\Models\PontoPeriodo;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Dados do espelho de ponto: grade colaborador × dia do período com os
 * totais, e o detalhe de um dia (marcações efetivas, originais
 * desconsideradas e correções). Usado pela página do admin, pela
 * impressão/CSV e pelo "Meu espelho" do colaborador.
 */
class EspelhoPontoService
{
    public function __construct(private readonly MarcacoesDoDia $marcacoes) {}

    /**
     * @param  Collection<int, Colaborador>  $colaboradores
     * @return array{dias: list<CarbonImmutable>, linhas: list<array{colaborador: Colaborador, celulas: array<string, PontoDia|null>, corrigidos: array<string, true>, totais: array<string, int>}>}
     */
    public function grade(PontoPeriodo $periodo, Collection $colaboradores): array
    {
        $dias = [];

        for ($dia = CarbonImmutable::instance($periodo->data_inicio); $dia->lte($periodo->data_fim); $dia = $dia->addDay()) {
            $dias[] = $dia;
        }

        $ids = $colaboradores->modelKeys();
        $apurados = PontoDia::query()
            ->whereIn('colaborador_id', $ids)
            ->whereDate('data_referencia', '>=', $periodo->data_inicio->toDateString())
            ->whereDate('data_referencia', '<=', $periodo->data_fim->toDateString())
            ->get()
            ->groupBy('colaborador_id');
        $corrigidos = PontoAjuste::query()
            ->whereIn('colaborador_id', $ids)
            ->whereIn('status', [StatusAjuste::Aprovado, StatusAjuste::Desfeito])
            ->whereDate('data_referencia', '>=', $periodo->data_inicio->toDateString())
            ->whereDate('data_referencia', '<=', $periodo->data_fim->toDateString())
            ->get(['colaborador_id', 'data_referencia'])
            ->groupBy('colaborador_id');

        $linhas = $colaboradores->map(function (Colaborador $colaborador) use ($dias, $apurados, $corrigidos): array {
            $doColaborador = ($apurados[$colaborador->id] ?? collect())->keyBy(fn (PontoDia $dia): string => $dia->data_referencia->toDateString());

            return [
                'colaborador' => $colaborador,
                'celulas' => collect($dias)->mapWithKeys(fn (CarbonImmutable $dia): array => [$dia->toDateString() => $doColaborador[$dia->toDateString()] ?? null])->all(),
                'corrigidos' => ($corrigidos[$colaborador->id] ?? collect())
                    ->mapWithKeys(fn (PontoAjuste $ajuste): array => [$ajuste->data_referencia->toDateString() => true])
                    ->all(),
                'totais' => $this->totais($doColaborador->values()),
            ];
        })->values()->all();

        return ['dias' => $dias, 'linhas' => $linhas];
    }

    /**
     * @param  Collection<int, PontoDia>  $dias
     * @return array{trabalhado: int, extra_50: int, extra_100: int, noturno: int, atraso: int, abonado: int, faltas: int, inconsistentes: int}
     */
    public function totais(Collection $dias): array
    {
        return [
            'trabalhado' => (int) $dias->sum('minutos_trabalhados'),
            'extra_50' => (int) $dias->sum('minutos_extra_50'),
            'extra_100' => (int) $dias->sum('minutos_extra_100'),
            'noturno' => (int) $dias->sum('minutos_noturnos'),
            'atraso' => (int) $dias->sum('minutos_atraso'),
            'abonado' => (int) $dias->sum('minutos_abonados'),
            'faltas' => $dias->where('situacao', SituacaoDia::Falta)->count(),
            'inconsistentes' => $dias->whereIn('situacao', [SituacaoDia::Incompleto, SituacaoDia::Inconsistente])->count(),
        ];
    }

    /**
     * @return array{apurado: PontoDia|null, escala: EscalaDia|null, efetivas: Collection<int, MarcacaoEfetiva>, desconsideradas: Collection<int, PontoMarcacao>, correcoes: Collection<string, Collection<int, PontoAjuste>>}
     */
    public function detalheDoDia(Colaborador $colaborador, CarbonInterface $dia): array
    {
        $dia = CarbonImmutable::instance($dia)->startOfDay();

        return [
            'apurado' => PontoDia::query()->where('colaborador_id', $colaborador->id)->whereDate('data_referencia', $dia->toDateString())->first(),
            'escala' => $colaborador->escalaVigente($dia)?->diaDe($dia),
            'efetivas' => $this->marcacoes->efetivas($colaborador, $dia),
            'desconsideradas' => $this->marcacoes->desconsideradas($colaborador, $dia),
            'correcoes' => PontoAjuste::query()
                ->with(['aprovadoPor', 'desfeitoPor', 'marcacao'])
                ->where('colaborador_id', $colaborador->id)
                ->whereDate('data_referencia', $dia->toDateString())
                ->whereIn('status', [StatusAjuste::Aprovado, StatusAjuste::Desfeito])
                ->orderBy('id')
                ->get()
                ->groupBy(fn (PontoAjuste $ajuste): string => $ajuste->lote_uuid ?? "ajuste-{$ajuste->id}"),
        ];
    }

    /** Rótulo curto da célula: horas trabalhadas, ou a sigla da situação. */
    public static function rotuloCelula(?PontoDia $dia): string
    {
        if ($dia === null) {
            return '';
        }

        return match ($dia->situacao) {
            SituacaoDia::Folga => 'Folga',
            SituacaoDia::Feriado => $dia->minutos_trabalhados > 0 ? self::horas($dia->minutos_trabalhados) : 'Feriado',
            SituacaoDia::Falta => 'Falta',
            SituacaoDia::Abonado => 'Abono',
            default => self::horas($dia->minutos_trabalhados),
        };
    }

    public static function horas(int $minutos): string
    {
        return sprintf('%s%d:%02d', $minutos < 0 ? '-' : '', intdiv(abs($minutos), 60), abs($minutos) % 60);
    }

    /** Correção do gestor (não pedido do colaborador). */
    public static function ehCorrecaoDoGestor(PontoAjuste $ajuste): bool
    {
        return $ajuste->origem === OrigemAjuste::Gestor;
    }
}
