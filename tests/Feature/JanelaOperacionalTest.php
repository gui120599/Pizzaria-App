<?php

namespace Tests\Feature;

use App\Models\HorarioFuncionamento;
use App\Support\JanelaOperacional;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * JanelaOperacional passou a resolver o início do turno a partir de
 * HorarioFuncionamento (suporta vários turnos por dia), caindo no horário
 * fixo de config('pizzaria.janela_operacional.abertura') só quando não há
 * nenhum horário cadastrado/ativo.
 */
class JanelaOperacionalTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_sem_horario_cadastrado_cai_no_fallback_de_config(): void
    {
        config(['pizzaria.janela_operacional.abertura' => '07:00']);
        Carbon::setTestNow('2026-09-30 20:00:00');

        [$inicio, $fim] = JanelaOperacional::atual();

        $this->assertSame('2026-09-30 07:00:00', $inicio->toDateTimeString());
        $this->assertSame('2026-10-01 07:00:00', $fim->toDateTimeString());
    }

    public function test_resolve_pelo_horario_de_funcionamento_cadastrado(): void
    {
        Carbon::setTestNow('2026-09-30 20:00:00'); // quarta-feira

        HorarioFuncionamento::create([
            'horario_dia_semana' => Carbon::parse('2026-09-30')->dayOfWeek,
            'horario_abertura' => '18:00:00',
            'horario_fechamento' => '23:59:59',
            'horario_ativo' => true,
        ]);

        [$inicio] = JanelaOperacional::atual();

        $this->assertSame('2026-09-30 18:00:00', $inicio->toDateTimeString());
    }

    /** Pedido às 2h da manhã pertence ao turno que abriu na noite anterior. */
    public function test_madrugada_pertence_ao_turno_que_comecou_no_dia_anterior(): void
    {
        $diaAnterior = Carbon::parse('2026-09-29')->dayOfWeek;

        HorarioFuncionamento::create([
            'horario_dia_semana' => $diaAnterior,
            'horario_abertura' => '19:00:00',
            'horario_fechamento' => '02:00:00',
            'horario_ativo' => true,
        ]);

        Carbon::setTestNow('2026-09-30 01:30:00');

        [$inicio] = JanelaOperacional::atual();

        $this->assertSame('2026-09-29 19:00:00', $inicio->toDateTimeString());
    }

    /** Dois turnos no mesmo dia (almoço + jantar): usa o mais recente já iniciado. */
    public function test_dois_turnos_no_mesmo_dia_usa_o_mais_recente_ja_iniciado(): void
    {
        $diaDeHoje = Carbon::parse('2026-09-30')->dayOfWeek;

        HorarioFuncionamento::create([
            'horario_dia_semana' => $diaDeHoje,
            'horario_abertura' => '11:00:00',
            'horario_fechamento' => '15:00:00',
            'horario_ativo' => true,
        ]);

        HorarioFuncionamento::create([
            'horario_dia_semana' => $diaDeHoje,
            'horario_abertura' => '18:00:00',
            'horario_fechamento' => '23:59:59',
            'horario_ativo' => true,
        ]);

        // 20h: já entramos no turno da noite.
        Carbon::setTestNow('2026-09-30 20:00:00');
        [$inicioNoite] = JanelaOperacional::atual();
        $this->assertSame('2026-09-30 18:00:00', $inicioNoite->toDateTimeString());

        // 13h: ainda no turno do almoço, o da noite nem começou.
        Carbon::setTestNow('2026-09-30 13:00:00');
        [$inicioAlmoco] = JanelaOperacional::atual();
        $this->assertSame('2026-09-30 11:00:00', $inicioAlmoco->toDateTimeString());
    }

    public function test_horario_inativo_e_ignorado(): void
    {
        $diaDeHoje = Carbon::parse('2026-09-30')->dayOfWeek;

        HorarioFuncionamento::create([
            'horario_dia_semana' => $diaDeHoje,
            'horario_abertura' => '10:00:00',
            'horario_fechamento' => '22:00:00',
            'horario_ativo' => false,
        ]);

        config(['pizzaria.janela_operacional.abertura' => '07:00']);
        Carbon::setTestNow('2026-09-30 20:00:00');

        [$inicio] = JanelaOperacional::atual();

        // Ignorando o horário inativo, cai no fallback de config.
        $this->assertSame('2026-09-30 07:00:00', $inicio->toDateTimeString());
    }

    public function test_para_dia_de_abertura_usa_o_horario_mais_cedo_cadastrado_naquele_dia(): void
    {
        $dia = Carbon::parse('2026-09-30')->dayOfWeek;

        HorarioFuncionamento::create([
            'horario_dia_semana' => $dia,
            'horario_abertura' => '18:00:00',
            'horario_fechamento' => '23:59:59',
            'horario_ativo' => true,
        ]);

        HorarioFuncionamento::create([
            'horario_dia_semana' => $dia,
            'horario_abertura' => '11:00:00',
            'horario_fechamento' => '15:00:00',
            'horario_ativo' => true,
        ]);

        [$inicio, $fim] = JanelaOperacional::paraDiaDeAbertura('2026-09-30');

        $this->assertSame('2026-09-30 11:00:00', $inicio->toDateTimeString());
        $this->assertSame('2026-10-01 11:00:00', $fim->toDateTimeString());
    }
}
