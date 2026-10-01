<?php

namespace Tests\Unit;

use App\Support\FormatoDuracao;
use Tests\TestCase;

class FormatoDuracaoTest extends TestCase
{
    public function test_minutos_abaixo_de_uma_hora_tem_dois_digitos(): void
    {
        $this->assertSame('02 min', FormatoDuracao::minutos(2));
        $this->assertSame('15 min', FormatoDuracao::minutos(15));
        $this->assertSame('59 min', FormatoDuracao::minutos(59));
    }

    public function test_uma_hora_exata_nao_mostra_minutos(): void
    {
        $this->assertSame('1h', FormatoDuracao::minutos(60));
        $this->assertSame('2h', FormatoDuracao::minutos(120));
    }

    public function test_horas_com_minutos_restantes(): void
    {
        $this->assertSame('1h 35min', FormatoDuracao::minutos(95));
        $this->assertSame('1h 12min', FormatoDuracao::minutos(72));
    }

    public function test_negativo_e_tratado_como_zero(): void
    {
        $this->assertSame('00 min', FormatoDuracao::minutos(-5));
    }
}
