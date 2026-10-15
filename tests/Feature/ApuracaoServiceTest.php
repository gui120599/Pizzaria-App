<?php

namespace Tests\Feature;

use App\Enums\Ponto\SituacaoDia;
use App\Enums\Ponto\StatusPeriodo;
use App\Models\Colaborador;
use App\Models\Escala;
use App\Models\Feriado;
use App\Models\PontoAjuste;
use App\Models\PontoDia;
use App\Models\PontoMarcacao;
use App\Models\PontoPeriodo;
use App\Services\Ponto\ApuracaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApuracaoServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_noturno_de_22h_a_01h30_vale_210_minutos_de_relogio_e_240_equivalentes(): void
    {
        $this->travelTo('2026-10-12 12:00:00');
        $colaborador = $this->colaborador(Escala::factory()->livre()->create());
        $this->marcar($colaborador, ['2026-10-10 22:00', '2026-10-11 01:30']);

        $dia = $this->apurar($colaborador, '2026-10-10');

        $this->assertSame(210, $dia->minutos_noturnos);
        $this->assertSame(30, $dia->minutos_acrescimo_noturno);
        $this->assertSame(240, $dia->minutos_trabalhados);
        $this->assertSame('2026-10-10', $dia->data_referencia->toDateString());
    }

    public function test_turno_noturno_cumprido_a_risca_nao_gera_extra_nem_atraso(): void
    {
        $this->travelTo('2026-10-12 12:00:00');
        $colaborador = $this->colaborador(Escala::factory()->noitePizzaria()->create());
        $this->marcar($colaborador, ['2026-10-07 17:30', '2026-10-07 20:00', '2026-10-07 21:00', '2026-10-08 00:30']);

        $dia = $this->apurar($colaborador, '2026-10-07');

        $this->assertSame(SituacaoDia::Normal, $dia->situacao);
        $this->assertSame(381, $dia->minutos_previstos);
        $this->assertSame(381, $dia->minutos_trabalhados);
        $this->assertSame(60, $dia->minutos_intervalo);
        $this->assertSame(0, $dia->minutos_extra_50);
        $this->assertSame(0, $dia->minutos_atraso);
    }

    /** @return array<string, array{array<int, string>, int, int}> */
    public static function variacoesDeHorario(): array
    {
        return [
            '4 min de atraso e 3 a menos na saída (7 no dia)' => [['2026-10-07 11:04', '2026-10-07 14:57'], 0, 0],
            '5 min antes e 5 depois (10 no dia)' => [['2026-10-07 10:55', '2026-10-07 15:05'], 0, 0],
            '6 min de atraso na entrada conta inteiro' => [['2026-10-07 11:06', '2026-10-07 15:00'], 0, 6],
            '4 min antes e 4 depois (8 no dia)' => [['2026-10-07 10:56', '2026-10-07 15:04'], 0, 0],
            '6 min a mais na saída conta inteiro' => [['2026-10-07 11:00', '2026-10-07 15:06'], 6, 0],
        ];
    }

    /** @param  array<int, string>  $marcacoes */
    #[DataProvider('variacoesDeHorario')]
    public function test_tolerancia_de_5_minutos_por_marcacao_e_10_no_dia(array $marcacoes, int $extraEsperado, int $atrasoEsperado): void
    {
        $this->travelTo('2026-10-12 12:00:00');
        $colaborador = $this->colaborador($this->escalaDiurna());
        $this->marcar($colaborador, $marcacoes);

        $dia = $this->apurar($colaborador, '2026-10-07');

        $this->assertSame($extraEsperado, $dia->minutos_extra_50);
        $this->assertSame($atrasoEsperado, $dia->minutos_atraso);
    }

    public function test_trabalho_em_feriado_e_todo_extra_100(): void
    {
        $this->travelTo('2026-10-14 12:00:00');
        Feriado::factory()->recorrente()->create(['data' => '2026-10-12', 'nome' => 'Nossa Senhora Aparecida']);
        $colaborador = $this->colaborador($this->escalaDiurna());
        $this->marcar($colaborador, ['2026-10-12 11:00', '2026-10-12 15:00']);

        $dia = $this->apurar($colaborador, '2026-10-12');

        $this->assertSame(0, $dia->minutos_previstos);
        $this->assertSame(240, $dia->minutos_extra_100);
        $this->assertSame(0, $dia->minutos_extra_50);
    }

    public function test_folga_trabalhada_e_toda_extra_100(): void
    {
        $this->travelTo('2026-10-12 12:00:00');
        $colaborador = $this->colaborador($this->escalaDiurna());
        $this->marcar($colaborador, ['2026-10-11 11:00', '2026-10-11 14:00']);

        $dia = $this->apurar($colaborador, '2026-10-11');

        $this->assertSame(180, $dia->minutos_extra_100);
        $this->assertSame(SituacaoDia::Normal, $dia->situacao);
    }

    public function test_dia_previsto_sem_marcacao_e_falta_do_previsto(): void
    {
        $this->travelTo('2026-10-12 12:00:00');
        $colaborador = $this->colaborador($this->escalaDiurna());

        $dia = $this->apurar($colaborador, '2026-10-07');

        $this->assertSame(SituacaoDia::Falta, $dia->situacao);
        $this->assertSame(240, $dia->minutos_falta);
    }

    public function test_abono_do_dia_inteiro_tira_a_falta(): void
    {
        $this->travelTo('2026-10-12 12:00:00');
        $colaborador = $this->colaborador($this->escalaDiurna());
        PontoAjuste::factory()->for($colaborador)->abono()->aprovado()->create(['data_referencia' => '2026-10-07']);

        $dia = $this->apurar($colaborador, '2026-10-07');

        $this->assertSame(SituacaoDia::Abonado, $dia->situacao);
        $this->assertSame(0, $dia->minutos_falta);
        $this->assertSame(240, $dia->minutos_abonados);
    }

    public function test_numero_impar_de_marcacoes_deixa_o_dia_incompleto(): void
    {
        $this->travelTo('2026-10-12 12:00:00');
        $colaborador = $this->colaborador($this->escalaDiurna());
        $this->marcar($colaborador, ['2026-10-07 11:00']);

        $dia = $this->apurar($colaborador, '2026-10-07');

        $this->assertSame(SituacaoDia::Incompleto, $dia->situacao);
        $this->assertSame([ApuracaoService::MARCACAO_FALTANTE], $dia->inconsistencias);
    }

    public function test_desconsideracao_e_inclusao_aprovadas_corrigem_o_dia_incompleto(): void
    {
        $this->travelTo('2026-10-12 12:00:00');
        $colaborador = $this->colaborador($this->escalaDiurna());
        [$entrada, $duplicada] = $this->marcar($colaborador, ['2026-10-07 11:00', '2026-10-07 11:01', '2026-10-07 15:00']);
        PontoAjuste::factory()->desconsideracao($duplicada)->aprovado()->create();
        PontoAjuste::factory()->for($colaborador)->inclusao('2026-10-07 13:00')->aprovado()->create(['data_referencia' => '2026-10-07']);
        PontoAjuste::factory()->for($colaborador)->inclusao('2026-10-07 13:15')->aprovado()->create(['data_referencia' => '2026-10-07']);

        $dia = $this->apurar($colaborador, '2026-10-07');

        $this->assertSame(SituacaoDia::Normal, $dia->situacao);
        $this->assertSame(225, $dia->minutos_trabalhados);
        $this->assertSame(15, $dia->minutos_intervalo);
        $this->assertSame(15, $dia->minutos_atraso);
        $this->assertModelExists($entrada);
    }

    public function test_ajuste_pendente_nao_entra_na_apuracao(): void
    {
        $this->travelTo('2026-10-12 12:00:00');
        $colaborador = $this->colaborador($this->escalaDiurna());
        $this->marcar($colaborador, ['2026-10-07 11:00']);
        PontoAjuste::factory()->for($colaborador)->inclusao('2026-10-07 15:00')->create(['data_referencia' => '2026-10-07']);

        $dia = $this->apurar($colaborador, '2026-10-07');

        $this->assertSame(SituacaoDia::Incompleto, $dia->situacao);
    }

    public function test_jornada_acima_de_6h_sem_1h_de_intervalo_e_inconsistente(): void
    {
        $this->travelTo('2026-10-12 12:00:00');
        $colaborador = $this->colaborador(Escala::factory()->comDias([1, 2, 3, 4, 5], '10:00', '18:00', 60)->create());
        $this->marcar($colaborador, ['2026-10-07 10:00', '2026-10-07 13:00', '2026-10-07 13:30', '2026-10-07 18:00']);

        $dia = $this->apurar($colaborador, '2026-10-07');

        $this->assertSame(SituacaoDia::Inconsistente, $dia->situacao);
        $this->assertSame([ApuracaoService::INTERVALO_INSUFICIENTE], $dia->inconsistencias);
    }

    public function test_escala_livre_nao_gera_extra_nem_falta(): void
    {
        $this->travelTo('2026-10-12 12:00:00');
        $colaborador = $this->colaborador(Escala::factory()->livre()->create());
        $this->marcar($colaborador, ['2026-10-07 19:00', '2026-10-07 23:00']);

        $dia = $this->apurar($colaborador, '2026-10-07');

        $this->assertSame(0, $dia->minutos_extra_50);
        $this->assertSame(0, $dia->minutos_extra_100);
        $this->assertSame(0, $dia->minutos_previstos);
        $this->assertSame(SituacaoDia::Normal, $dia->situacao);
    }

    public function test_marcacao_fora_da_cerca_sinaliza_o_dia(): void
    {
        $this->travelTo('2026-10-12 12:00:00');
        $colaborador = $this->colaborador($this->escalaDiurna());
        PontoMarcacao::factory()->for($colaborador)->em('2026-10-07 11:00')->create(['dentro_da_cerca' => false]);
        PontoMarcacao::factory()->for($colaborador)->em('2026-10-07 15:00')->create(['dentro_da_cerca' => true]);

        $dia = $this->apurar($colaborador, '2026-10-07');

        $this->assertSame([ApuracaoService::FORA_DA_CERCA], $dia->inconsistencias);
    }

    public function test_dia_de_periodo_fechado_nao_e_reapurado(): void
    {
        $this->travelTo('2026-10-12 12:00:00');
        $colaborador = $this->colaborador($this->escalaDiurna());
        PontoPeriodo::factory()->entre('2026-10-05', '2026-10-11')->status(StatusPeriodo::Fechado)->create();
        $this->marcar($colaborador, ['2026-10-07 11:00', '2026-10-07 15:00']);

        $dia = app(ApuracaoService::class)->apurarDia($colaborador, Carbon::parse('2026-10-07'));

        $this->assertNull($dia);
        $this->assertSame(0, PontoDia::count());
    }

    public function test_apuracao_vincula_o_dia_ao_periodo_da_periodicidade_do_colaborador(): void
    {
        $this->travelTo('2026-10-12 12:00:00');
        $colaborador = $this->colaborador($this->escalaDiurna());
        $periodo = PontoPeriodo::factory()->entre('2026-10-05', '2026-10-11')->create();

        $dia = $this->apurar($colaborador, '2026-10-07');

        $this->assertSame($periodo->id, $dia->ponto_periodo_id);
    }

    /** Seg–sex 11:00–15:00, sem intervalo (240 min). */
    private function escalaDiurna(): Escala
    {
        return Escala::factory()->comDias([1, 2, 3, 4, 5], '11:00', '15:00')->create();
    }

    private function colaborador(Escala $escala): Colaborador
    {
        return Colaborador::factory()->comEscala($escala)->comRemuneracao()->create();
    }

    /**
     * @param  array<int, string>  $horarios
     * @return array<int, PontoMarcacao>
     */
    private function marcar(Colaborador $colaborador, array $horarios): array
    {
        return array_map(fn (string $horario): PontoMarcacao => PontoMarcacao::factory()->for($colaborador)->em($horario)->create(), $horarios);
    }

    private function apurar(Colaborador $colaborador, string $dia): PontoDia
    {
        return app(ApuracaoService::class)->apurarDia($colaborador, Carbon::parse($dia));
    }
}
