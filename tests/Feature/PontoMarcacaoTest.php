<?php

namespace Tests\Feature;

use App\Exceptions\RegistroImutavelException;
use App\Models\PontoMarcacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PontoMarcacaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_marcacao_nao_pode_ser_alterada(): void
    {
        $marcacao = PontoMarcacao::factory()->em('2026-10-10 18:00:00')->create();

        try {
            $marcacao->update(['registrada_em' => '2026-10-10 17:00:00']);
            $this->fail('A marcação foi alterada.');
        } catch (RegistroImutavelException) {
        }

        $this->assertSame('2026-10-10 18:00:00', $marcacao->fresh()->registrada_em->format('Y-m-d H:i:s'));
    }

    public function test_marcacao_nao_pode_ser_apagada(): void
    {
        $marcacao = PontoMarcacao::factory()->create();

        try {
            $marcacao->delete();
            $this->fail('A marcação foi apagada.');
        } catch (RegistroImutavelException) {
        }

        $this->assertModelExists($marcacao);
    }

    public function test_hash_reproduzido_a_partir_da_linha_gravada_confere(): void
    {
        $marcacao = PontoMarcacao::factory()->em('2026-10-10 18:00:00')->create([
            'latitude' => -23.5505199,
            'longitude' => -46.6333094,
            'hash_anterior' => str_repeat('a', 64),
        ]);

        $this->assertTrue($marcacao->fresh()->hashConfere());
    }

    public function test_hash_deixa_de_conferir_se_o_horario_for_adulterado(): void
    {
        $marcacao = PontoMarcacao::factory()->em('2026-10-10 18:00:00')->create()->fresh();

        $marcacao->registrada_em = $marcacao->registrada_em->subMinutes(30);

        $this->assertFalse($marcacao->hashConfere());
    }

    public function test_marcacao_de_madrugada_pertence_ao_dia_operacional_anterior(): void
    {
        $marcacao = PontoMarcacao::factory()->em('2026-10-11 01:30:00')->create();

        $this->assertSame('2026-10-10', $marcacao->data_referencia->toDateString());
    }
}
