<?php

namespace Tests\Unit\Support;

use App\Support\Telefone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TelefoneTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function formatosDoMesmoCelular(): array
    {
        return [
            'com máscara' => ['(62) 98123-4567'],
            'só dígitos' => ['62981234567'],
            'zero antes do DDD' => ['062981234567'],
            'zero e código de operadora' => ['0 21 62 98123-4567'],
            'código do país com espaço' => ['55 62 98123-4567'],
            'código do país colado' => ['5562981234567'],
            'código do país com +' => ['+55 (62) 98123-4567'],
            'discagem internacional 00' => ['00 55 62 98123-4567'],
            'sem o nono dígito' => ['(62) 8123-4567'],
            'sem o nono dígito com código do país' => ['556281234567'],
            'sem o nono dígito com zero' => ['06281234567'],
        ];
    }

    #[DataProvider('formatosDoMesmoCelular')]
    public function test_formatos_equivalentes_do_celular_viram_a_mesma_forma_canonica(string $informado): void
    {
        $this->assertSame('62981234567', Telefone::canonico($informado));
        $this->assertSame('62981234567', Telefone::normalizar($informado));
    }

    public function test_telefone_fixo_mantem_dez_digitos_sem_ganhar_o_nove(): void
    {
        $this->assertSame('6232123456', Telefone::canonico('(62) 3212-3456'));
        $this->assertSame('6232123456', Telefone::canonico('+55 62 3212-3456'));
        $this->assertFalse(Telefone::ehCelular('(62) 3212-3456'));
        $this->assertTrue(Telefone::ehCelular('(62) 8123-4567'));
    }

    /** @return array<string, array{string}> */
    public static function valoresNaoReconhecidos(): array
    {
        return [
            'sem DDD' => ['98123-4567'],
            'sem DDD e sem o nove' => ['8123-4567'],
            'DDD inexistente' => ['(20) 98123-4567'],
            'dígito a mais' => ['629812345678'],
            'onze dígitos sem o nove na frente' => ['62812345678'],
            'número começando com 0 ou 1' => ['(62) 1123-4567'],
            'número estrangeiro' => ['+44 7931 223635'],
            'só zeros' => ['0000000000000'],
        ];
    }

    #[DataProvider('valoresNaoReconhecidos')]
    public function test_valor_nao_reconhecido_nao_tem_forma_canonica_e_so_bate_pelos_mesmos_digitos(string $informado): void
    {
        $this->assertNull(Telefone::canonico($informado));
        $this->assertSame(preg_replace('/\D/', '', $informado), Telefone::normalizar($informado));
    }

    /** @return array<string, array{string, string}> */
    public static function numerosDiferentes(): array
    {
        return [
            'mesmo número em outro DDD' => ['(62) 98123-4567', '(64) 98123-4567'],
            'celular e fixo com o mesmo final' => ['(62) 98123-4567', '(62) 3123-4567'],
            'sem DDD não vira o número com DDD' => ['(62) 98123-4567', '98123-4567'],
            'DDD 55 não é confundido com código do país' => ['(55) 99123-4567', '(62) 99123-4567'],
            'nove a mais em número que já tem nove dígitos' => ['(62) 98123-4567', '(62) 99812-3456'],
        ];
    }

    #[DataProvider('numerosDiferentes')]
    public function test_numeros_diferentes_nunca_tem_a_mesma_chave(string $um, string $outro): void
    {
        $this->assertNotSame(Telefone::normalizar($um), Telefone::normalizar($outro));
    }

    public function test_formata_para_exibicao_e_para_o_whatsapp(): void
    {
        $this->assertSame('(62) 98123-4567', Telefone::formatar('556281234567'));
        $this->assertSame('(62) 3212-3456', Telefone::formatar('6232123456'));
        $this->assertSame('98123-4567', Telefone::formatar('98123-4567'));
        $this->assertNull(Telefone::formatar(''));
        $this->assertSame('5562981234567', Telefone::whatsApp('(62) 8123-4567'));
        $this->assertSame('5555991234567', Telefone::whatsApp('(55) 99123-4567'));
        $this->assertNull(Telefone::whatsApp('98123-4567'));
    }
}
