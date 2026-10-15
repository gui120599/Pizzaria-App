<?php

namespace Tests\Feature;

use App\Models\EscalaDia;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EscalaDiaTest extends TestCase
{
    public function test_turno_que_vira_a_meia_noite_desconta_o_intervalo(): void
    {
        $dia = EscalaDia::factory()->make(['entrada' => '17:30', 'saida' => '00:30', 'intervalo_minutos' => 60]);

        $this->assertTrue($dia->viraMeiaNoite());
        $this->assertSame(360, $dia->minutosPrevistos());
    }

    public function test_turno_no_mesmo_dia_nao_vira_a_meia_noite(): void
    {
        $dia = EscalaDia::factory()->make(['entrada' => '11:00', 'saida' => '15:00', 'intervalo_minutos' => 15]);

        $this->assertFalse($dia->viraMeiaNoite());
        $this->assertSame(225, $dia->minutosPrevistos());
    }

    public function test_folga_nao_tem_jornada_prevista(): void
    {
        $dia = EscalaDia::factory()->folga()->make();

        $this->assertSame(0, $dia->minutosPrevistos());
        $this->assertNull($dia->entradaEm(Carbon::parse('2026-10-10')));
    }

    public function test_saida_prevista_cai_no_dia_seguinte_quando_vira_a_meia_noite(): void
    {
        $dia = EscalaDia::factory()->make(['entrada' => '18:00', 'saida' => '01:30']);

        $this->assertSame('2026-10-10 18:00', $dia->entradaEm(Carbon::parse('2026-10-10'))->format('Y-m-d H:i'));
        $this->assertSame('2026-10-11 01:30', $dia->saidaEm(Carbon::parse('2026-10-10'))->format('Y-m-d H:i'));
    }
}
