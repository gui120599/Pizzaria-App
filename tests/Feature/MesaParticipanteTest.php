<?php

namespace Tests\Feature;

use App\Models\MesaParticipante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Celular identificado na mesa: o token do cookie nunca é gravado em texto.
 */
class MesaParticipanteTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_fica_so_como_hash_e_encontra_o_participante(): void
    {
        $token = MesaParticipante::gerarToken();
        $participante = MesaParticipante::factory()->comToken($token)->create();

        $this->assertNotSame($token, $participante->mp_token_hash);
        $this->assertTrue($participante->is(MesaParticipante::porToken($token)));
        $this->assertNull(MesaParticipante::porToken($token.'x'));
        $this->assertArrayNotHasKey('mp_token_hash', $participante->toArray());
    }

    public function test_celular_fica_so_com_digitos(): void
    {
        $participante = MesaParticipante::factory()->create(['mp_celular' => '(64) 99999-1234']);

        $this->assertSame('64999991234', $participante->fresh()->mp_celular);
    }

    public function test_celular_bloqueado_pelo_garcom_e_reconhecido(): void
    {
        $this->assertTrue(MesaParticipante::factory()->bloqueado()->create()->estaBloqueado());
        $this->assertFalse(MesaParticipante::factory()->create()->estaBloqueado());
    }
}
