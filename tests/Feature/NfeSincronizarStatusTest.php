<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Venda;
use App\Services\NfeIoService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class NfeSincronizarStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Empresa::create([
            'empresa_razao_social' => 'Pizzaria Teste LTDA',
            'empresa_cnpj' => '11222333000181',
            'empresa_api_nfeio_company_id' => 'company-teste',
            'empresa_api_nfeio_apikey' => 'chave-teste',
        ]);
    }

    private function venda(?string $status, ?string $idNfe, ?Carbon $finalizadaEm = null): Venda
    {
        return Venda::create(['venda_status' => 'FINALIZADA', 'venda_status_nfe' => $status, 'venda_id_nfe' => $idNfe, 'venda_datahora_iniciada' => now(), 'venda_datahora_finalizada' => $finalizadaEm ?? now()]);
    }

    public function test_filtra_por_dias_e_minutos_e_respeita_o_limite(): void
    {
        $recente = $this->venda(null, 'a', now()->subHour());
        $antiga = $this->venda(null, 'b', now()->subDays(10));
        $noVapor = $this->venda(null, 'c', now()->subMinutes(2));
        $outra = $this->venda(null, 'd', now()->subHours(2));

        $consultadas = [];
        $this->mock(NfeIoService::class, function (MockInterface $mock) use (&$consultadas): void {
            $mock->shouldReceive('sincronizarStatusLocal')->andReturnUsing(function (Venda $venda) use (&$consultadas): string {
                $consultadas[] = $venda->id;
                $venda->update(['venda_status_nfe' => 'Issued']);

                return 'Issued';
            });
        });

        $this->artisan('nfe:sincronizar-status', ['--dias' => 7, '--minutos' => 10, '--limite' => 1])->assertSuccessful();

        $this->assertSame([$recente->id], $consultadas);
        $this->assertNull($antiga->fresh()->venda_status_nfe);
        $this->assertNull($noVapor->fresh()->venda_status_nfe);
        $this->assertNull($outra->fresh()->venda_status_nfe);
    }

    public function test_sai_em_silencio_sem_a_nfeio_configurada(): void
    {
        Empresa::query()->delete();
        $this->venda(null, 'a');

        $this->mock(NfeIoService::class, fn (MockInterface $mock) => $mock->shouldReceive('sincronizarStatusLocal')->never());

        $this->artisan('nfe:sincronizar-status')->assertSuccessful()->doesntExpectOutputToContain('venda(s) a consultar');
    }

    public function test_esta_agendado_de_hora_em_hora(): void
    {
        $evento = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'nfe:sincronizar-status'));

        $this->assertNotNull($evento);
        $this->assertSame('0 * * * *', $evento->expression);
        $this->assertStringContainsString('--dias=7', $evento->command);
        $this->assertTrue($evento->withoutOverlapping);
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
