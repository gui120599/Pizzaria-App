<?php

namespace Tests\Unit;

use App\Support\FormatoQuantidade;
use Tests\TestCase;

class FormatoQuantidadeTest extends TestCase
{
    public function test_inteiros_aparecem_sem_casas_decimais(): void
    {
        $this->assertSame('1', FormatoQuantidade::item(1));
        $this->assertSame('2', FormatoQuantidade::item(2.0));
        $this->assertSame('10', FormatoQuantidade::item(10));
    }

    public function test_metade_zero_vira_simbolo_de_fracao_em_vez_de_zero(): void
    {
        // O bug em produção: (int) 0.5 == 0, então meia pizza sumia como "0".
        $this->assertSame('½', FormatoQuantidade::item(0.5));
    }

    public function test_tercos_gravados_como_dizima_sao_reconhecidos(): void
    {
        $this->assertSame('⅓', FormatoQuantidade::item(0.333));
        $this->assertSame('⅔', FormatoQuantidade::item(0.667));
    }

    public function test_quartos_e_quarto_com_parte_inteira(): void
    {
        $this->assertSame('¼', FormatoQuantidade::item(0.25));
        $this->assertSame('¾', FormatoQuantidade::item(0.75));
        $this->assertSame('1½', FormatoQuantidade::item(1.5));
    }

    public function test_fracao_nao_reconhecida_cai_no_decimal_sem_zeros_a_direita(): void
    {
        $this->assertSame('1,2', FormatoQuantidade::item(1.2));
    }

    public function test_aceita_string_numerica_como_vem_do_banco(): void
    {
        $this->assertSame('½', FormatoQuantidade::item('0.500000'));
    }
}
