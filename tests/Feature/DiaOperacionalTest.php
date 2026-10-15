<?php

namespace Tests\Feature;

use App\Support\DiaOperacional;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DiaOperacionalTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function instantes(): array
    {
        return [
            'madrugada de domingo é sábado' => ['2026-10-11 01:30:00', '2026-10-10'],
            'um minuto antes da virada' => ['2026-10-11 04:59:59', '2026-10-10'],
            'na virada já é o novo dia' => ['2026-10-11 05:00:00', '2026-10-11'],
            'tarde do mesmo dia' => ['2026-10-11 17:30:00', '2026-10-11'],
        ];
    }

    #[DataProvider('instantes')]
    public function test_dia_operacional_vira_as_cinco_da_manha(string $instante, string $diaEsperado): void
    {
        $dia = DiaOperacional::de(Carbon::parse($instante));

        $this->assertSame($diaEsperado, $dia->toDateString());
    }

    public function test_virada_segue_a_configuracao(): void
    {
        config(['operacao.virada_dia' => '03:00']);

        $dia = DiaOperacional::de(Carbon::parse('2026-10-11 03:30:00'));

        $this->assertSame('2026-10-11', $dia->toDateString());
    }

    public function test_intervalo_do_dia_vai_da_virada_ate_a_virada_seguinte(): void
    {
        $dia = Carbon::parse('2026-10-10');

        $this->assertSame('2026-10-10 05:00:00', DiaOperacional::inicio($dia)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-11 05:00:00', DiaOperacional::fim($dia)->format('Y-m-d H:i:s'));
        $this->assertTrue(DiaOperacional::contem($dia, Carbon::parse('2026-10-11 04:59:00')));
        $this->assertFalse(DiaOperacional::contem($dia, Carbon::parse('2026-10-11 05:00:00')));
    }
}
