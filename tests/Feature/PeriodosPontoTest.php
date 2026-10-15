<?php

namespace Tests\Feature;

use App\Enums\Ponto\PeriodicidadeRepasse;
use App\Models\Colaborador;
use App\Models\PontoPeriodo;
use App\Services\Ponto\PeriodosPonto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PeriodosPontoTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{PeriodicidadeRepasse, string, string, string}> */
    public static function limites(): array
    {
        return [
            'semana de segunda a domingo' => [PeriodicidadeRepasse::Semanal, '2026-10-08', '2026-10-05', '2026-10-11'],
            'primeira quinzena' => [PeriodicidadeRepasse::Quinzenal, '2026-10-15', '2026-10-01', '2026-10-15'],
            'segunda quinzena até o fim do mês' => [PeriodicidadeRepasse::Quinzenal, '2026-02-16', '2026-02-16', '2026-02-28'],
            'mês civil' => [PeriodicidadeRepasse::Mensal, '2026-10-31', '2026-10-01', '2026-10-31'],
        ];
    }

    #[DataProvider('limites')]
    public function test_limites_do_periodo_que_contem_o_dia(PeriodicidadeRepasse $periodicidade, string $dia, string $inicio, string $fim): void
    {
        [$de, $ate] = app(PeriodosPonto::class)->limites($periodicidade, Carbon::parse($dia));

        $this->assertSame([$inicio, $fim], [$de->toDateString(), $ate->toDateString()]);
    }

    public function test_abre_o_periodo_atual_e_o_proximo_das_periodicidades_em_uso_sem_duplicar(): void
    {
        $this->travelTo('2026-10-08 00:10:00');
        Colaborador::factory()->create(['periodicidade_repasse' => PeriodicidadeRepasse::Semanal]);
        Colaborador::factory()->desligado()->create(['periodicidade_repasse' => PeriodicidadeRepasse::Mensal]);

        app(PeriodosPonto::class)->abrirPeriodos(now());
        $segundaVez = app(PeriodosPonto::class)->abrirPeriodos(now());

        $periodos = PontoPeriodo::orderBy('data_inicio')->get();
        $this->assertSame(
            [['2026-10-05', '2026-10-11', '2026-10-16'], ['2026-10-12', '2026-10-18', '2026-10-23']],
            $periodos->map(fn (PontoPeriodo $p): array => [$p->data_inicio->toDateString(), $p->data_fim->toDateString(), $p->data_pagamento->toDateString()])->all(),
        );
        $this->assertTrue($periodos->every(fn (PontoPeriodo $p): bool => $p->periodicidade === PeriodicidadeRepasse::Semanal));
        $this->assertCount(0, $segundaVez);
    }

    public function test_nao_abre_periodo_que_sobrepoe_outro_da_mesma_periodicidade(): void
    {
        PontoPeriodo::factory()->entre('2026-10-03', '2026-10-09')->create();

        $periodo = app(PeriodosPonto::class)->abrir(PeriodicidadeRepasse::Semanal, Carbon::parse('2026-10-08'));

        $this->assertNull($periodo);
        $this->assertSame(1, PontoPeriodo::count());
    }
}
