<?php

namespace App\Services\Ponto;

use App\Enums\Ponto\PeriodicidadeRepasse;
use App\Enums\Ponto\StatusPeriodo;
use App\Exceptions\PontoException;
use App\Models\Colaborador;
use App\Models\PontoPeriodo;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Períodos de apuração: qual período contém um dia de um colaborador, se ele
 * ainda aceita mudanças e a abertura automática dos próximos.
 *
 * Limites: semana de segunda a domingo; quinzena 1–15 e 16–fim do mês; mês
 * civil. Dia sem período cadastrado conta como aberto.
 */
class PeriodosPonto
{
    public function periodoDe(Colaborador $colaborador, CarbonInterface $dia): ?PontoPeriodo
    {
        return PontoPeriodo::query()
            ->daPeriodicidade($colaborador->periodicidade_repasse)
            ->contendo($dia)
            ->first();
    }

    /** @throws PontoException período em conferência, fechado ou pago */
    public function garantirAberto(Colaborador $colaborador, CarbonInterface $dia): void
    {
        $periodo = $this->periodoDe($colaborador, $dia);

        if ($periodo !== null && $periodo->estaTravado()) {
            throw new PontoException(
                "O período {$periodo->rotulo()} está {$this->descricaoStatus($periodo->status)}. Reabra o período para alterar o ponto de {$dia->format('d/m/Y')}."
            );
        }
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} início e fim (inclusivos) do período que contém o dia */
    public function limites(PeriodicidadeRepasse $periodicidade, CarbonInterface $dia): array
    {
        $dia = CarbonImmutable::instance($dia)->startOfDay();

        return match ($periodicidade) {
            PeriodicidadeRepasse::Semanal => [$dia->startOfWeek(CarbonInterface::MONDAY), $dia->endOfWeek(CarbonInterface::SUNDAY)->startOfDay()],
            PeriodicidadeRepasse::Quinzenal => $dia->day <= 15
                ? [$dia->startOfMonth(), $dia->setDay(15)]
                : [$dia->setDay(16), $dia->endOfMonth()->startOfDay()],
            PeriodicidadeRepasse::Mensal => [$dia->startOfMonth(), $dia->endOfMonth()->startOfDay()],
        };
    }

    /**
     * Garante o período atual e o seguinte de cada periodicidade em uso pelos
     * colaboradores ativos. Idempotente.
     *
     * @return Collection<int, PontoPeriodo> períodos criados
     */
    public function abrirPeriodos(CarbonInterface $hoje): Collection
    {
        $criados = collect();

        $periodicidades = Colaborador::ativos($hoje)
            ->distinct()
            ->pluck('periodicidade_repasse')
            ->map(fn ($valor) => $valor instanceof PeriodicidadeRepasse ? $valor : PeriodicidadeRepasse::from($valor))
            ->unique(fn (PeriodicidadeRepasse $p): string => $p->value);

        foreach ($periodicidades as $periodicidade) {
            [, $fimAtual] = $this->limites($periodicidade, $hoje);

            foreach ([$hoje, $fimAtual->addDay()] as $dia) {
                $periodo = $this->abrir($periodicidade, $dia);

                if ($periodo?->wasRecentlyCreated) {
                    $criados->push($periodo);
                }
            }
        }

        return $criados;
    }

    /** Cria (se não existir) o período da periodicidade que contém o dia. Null se sobrepuser outro já cadastrado. */
    public function abrir(PeriodicidadeRepasse $periodicidade, CarbonInterface $dia): ?PontoPeriodo
    {
        [$inicio, $fim] = $this->limites($periodicidade, $dia);

        return DB::transaction(function () use ($periodicidade, $inicio, $fim): ?PontoPeriodo {
            $existente = PontoPeriodo::withTrashed()
                ->daPeriodicidade($periodicidade)
                ->whereDate('data_inicio', $inicio->toDateString())
                ->lockForUpdate()
                ->first();

            if ($existente !== null) {
                return $existente;
            }

            $sobrepoe = PontoPeriodo::query()
                ->daPeriodicidade($periodicidade)
                ->whereDate('data_inicio', '<=', $fim->toDateString())
                ->whereDate('data_fim', '>=', $inicio->toDateString())
                ->exists();

            if ($sobrepoe) {
                return null;
            }

            return PontoPeriodo::create([
                'periodicidade' => $periodicidade,
                'data_inicio' => $inicio->toDateString(),
                'data_fim' => $fim->toDateString(),
                'data_pagamento' => $fim->addDays((int) config('ponto.dias_para_pagamento', 5))->toDateString(),
                'status' => StatusPeriodo::Aberto,
            ]);
        });
    }

    private function descricaoStatus(StatusPeriodo $status): string
    {
        return match ($status) {
            StatusPeriodo::Aberto => 'aberto',
            StatusPeriodo::EmConferencia => 'em conferência',
            StatusPeriodo::Fechado => 'fechado',
            StatusPeriodo::Pago => 'pago',
        };
    }
}
