<?php

namespace Tests\Feature;

use App\Models\Venda;
use App\Services\NfeIoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class NfeSincronizarStatusTest extends TestCase
{
    use RefreshDatabase;

    private function venda(?string $status, ?string $idNfe): Venda
    {
        return Venda::create(['venda_status' => 'FINALIZADA', 'venda_status_nfe' => $status, 'venda_id_nfe' => $idNfe, 'venda_datahora_iniciada' => now(), 'venda_datahora_finalizada' => now()]);
    }

    public function test_atualiza_so_as_pendentes_com_id_na_nfeio(): void
    {
        $parada = $this->venda(null, 'abc');
        $processando = $this->venda('Processing', 'def');
        $autorizada = $this->venda('Issued', 'ghi');
        $semNota = $this->venda(null, null);

        $this->mock(NfeIoService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('consultarStatus')->never();
            $mock->shouldReceive('sincronizarStatusLocal')->twice()->andReturnUsing(function (Venda $venda): string {
                $venda->update(['venda_status_nfe' => 'Issued']);

                return 'Issued';
            });
        });

        $this->artisan('nfe:sincronizar-status')->assertSuccessful();

        $this->assertSame('Issued', $parada->fresh()->venda_status_nfe);
        $this->assertSame('Issued', $processando->fresh()->venda_status_nfe);
        $this->assertSame('Issued', $autorizada->fresh()->venda_status_nfe);
        $this->assertNull($semNota->fresh()->venda_status_nfe);
    }

    public function test_simulacao_consulta_mas_nao_grava(): void
    {
        $parada = $this->venda(null, 'abc');

        $this->mock(NfeIoService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('consultarStatus')->once()->with('abc')->andReturn(['status' => 'Issued']);
            $mock->shouldReceive('sincronizarStatusLocal')->never();
        });

        $this->artisan('nfe:sincronizar-status', ['--simular' => true])
            ->expectsOutputToContain('Mudariam: 1')
            ->assertSuccessful();

        $this->assertNull($parada->fresh()->venda_status_nfe);
    }
}
