<?php

namespace Tests\Feature;

use App\Enums\Ponto\StatusPeriodo;
use App\Enums\Ponto\StatusRepasse;
use App\Exceptions\PontoException;
use App\Models\Colaborador;
use App\Models\Escala;
use App\Models\PontoMarcacao;
use App\Models\PontoPeriodo;
use App\Models\Repasse;
use App\Models\User;
use App\Services\Ponto\FechamentoPeriodoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FechamentoPeriodoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private PontoPeriodo $periodo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create(['name_first' => 'Admin']);
        $this->periodo = PontoPeriodo::factory()->entre('2026-10-05', '2026-10-11')->create();
    }

    public function test_fechamento_apura_trava_e_gera_os_repasses_em_rascunho(): void
    {
        $colaborador = $this->colaborador();
        PontoMarcacao::factory()->for($colaborador)->em('2026-10-07 17:30')->create();
        PontoMarcacao::factory()->for($colaborador)->em('2026-10-08 00:30')->create();
        $this->travelTo('2026-10-12 12:00:00');

        $resultado = $this->fechamento()->iniciarFechamento($this->periodo, $this->admin);

        $this->assertSame(StatusPeriodo::EmConferencia, $this->periodo->fresh()->status);
        $repasse = $resultado['repasses']->sole();
        $this->assertSame(StatusRepasse::Rascunho, $repasse->status);
        // Uma diária + 1 h extra: trabalhou 7 h direto, e a escala prevê 6 h com 1 h de intervalo.
        $this->assertEquals(145.71, $repasse->valor_liquido);
    }

    public function test_periodo_nao_fecha_antes_do_ultimo_dia(): void
    {
        $this->colaborador();
        $this->travelTo('2026-10-11 23:00:00');

        $this->expectException(PontoException::class);

        $this->fechamento()->iniciarFechamento($this->periodo, $this->admin);
    }

    public function test_dia_com_marcacao_faltante_bloqueia_o_fechamento(): void
    {
        $colaborador = $this->colaborador();
        PontoMarcacao::factory()->for($colaborador)->em('2026-10-07 17:30')->create();
        $this->travelTo('2026-10-12 12:00:00');

        try {
            $this->fechamento()->iniciarFechamento($this->periodo, $this->admin);
            $this->fail('Período com dia incompleto foi fechado.');
        } catch (PontoException $e) {
            $this->assertStringContainsString('07/10', $e->getMessage());
        }

        $this->assertSame(StatusPeriodo::Aberto, $this->periodo->fresh()->status);
        $this->assertSame(0, Repasse::count());
    }

    public function test_colaborador_sem_remuneracao_bloqueia_o_fechamento(): void
    {
        Colaborador::factory()->comEscala(Escala::factory()->noitePizzaria()->create())->create(['data_admissao' => '2026-01-01']);
        $this->travelTo('2026-10-12 12:00:00');

        $this->expectException(PontoException::class);

        $this->fechamento()->iniciarFechamento($this->periodo, $this->admin);
    }

    public function test_reabrir_com_repasse_aprovado_e_bloqueado(): void
    {
        $this->periodo->update(['status' => StatusPeriodo::EmConferencia]);
        Repasse::factory()->for($this->periodo, 'periodo')->status(StatusRepasse::Aprovado)->create();

        $this->expectException(PontoException::class);

        $this->fechamento()->reabrir($this->periodo, $this->admin);
    }

    public function test_reabrir_sem_aprovados_volta_o_periodo_para_aberto(): void
    {
        $this->periodo->update(['status' => StatusPeriodo::EmConferencia]);
        Repasse::factory()->for($this->periodo, 'periodo')->create();

        $this->fechamento()->reabrir($this->periodo, $this->admin);

        $this->assertSame(StatusPeriodo::Aberto, $this->periodo->fresh()->status);
    }

    public function test_gerente_nao_fecha_periodo(): void
    {
        $this->travelTo('2026-10-12 12:00:00');

        $this->expectException(PontoException::class);

        $this->fechamento()->iniciarFechamento($this->periodo, User::factory()->gerente()->create(['name_first' => 'Gerente']));
    }

    /** Diarista semanal (R$ 120/dia) na escala "Noite pizzaria" (qua–dom 17:30–00:30). */
    private function colaborador(): Colaborador
    {
        return Colaborador::factory()
            ->comEscala(Escala::factory()->noitePizzaria()->create())
            ->comRemuneracao(['valor_base' => 120])
            ->create(['data_admissao' => '2026-01-01']);
    }

    private function fechamento(): FechamentoPeriodoService
    {
        return app(FechamentoPeriodoService::class);
    }
}
