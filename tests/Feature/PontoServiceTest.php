<?php

namespace Tests\Feature;

use App\Enums\Ponto\MotivoAjuste;
use App\Enums\Ponto\OrigemMarcacao;
use App\Enums\Ponto\StatusAjuste;
use App\Enums\Ponto\StatusPeriodo;
use App\Enums\Ponto\TipoAjuste;
use App\Enums\Ponto\TipoMarcacao;
use App\Exceptions\PontoException;
use App\Models\Colaborador;
use App\Models\Escala;
use App\Models\PontoAjuste;
use App\Models\PontoDia;
use App\Models\PontoMarcacao;
use App\Models\PontoPeriodo;
use App\Models\User;
use App\Notifications\Ponto\AjusteDecididoNotification;
use App\Notifications\Ponto\AjusteSolicitadoNotification;
use App\Services\Ponto\IntegridadePonto;
use App\Services\Ponto\PontoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PontoServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_marcacoes_recebem_nsr_sequencial_e_hash_encadeado(): void
    {
        $this->travelTo('2026-10-07 17:30:00');
        $ana = $this->colaborador();
        $bruno = $this->colaborador();

        $primeira = $this->ponto()->registrarMarcacao($ana, OrigemMarcacao::App);
        $segunda = $this->ponto()->registrarMarcacao($bruno, OrigemMarcacao::Quiosque);
        $this->travel(4)->hours();
        $terceira = $this->ponto()->registrarMarcacao($ana, OrigemMarcacao::App);

        $this->assertSame([1, 2, 3], [$primeira->nsr, $segunda->nsr, $terceira->nsr]);
        $this->assertNull($primeira->hash_anterior);
        $this->assertSame($primeira->hash, $segunda->hash_anterior);
        $this->assertSame($segunda->hash, $terceira->hash_anterior);
        $this->assertSame([], app(IntegridadePonto::class)->verificar());
    }

    public function test_marcacao_usa_a_hora_do_servidor_e_o_dia_operacional(): void
    {
        $this->travelTo('2026-10-11 01:30:00');
        $colaborador = $this->colaborador();

        $marcacao = $this->ponto()->registrarMarcacao($colaborador, OrigemMarcacao::App, ['ip' => '10.0.0.5']);

        $this->assertSame('2026-10-11 01:30:00', $marcacao->registrada_em->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-10', $marcacao->data_referencia->toDateString());
        $this->assertSame(TipoMarcacao::Entrada, $marcacao->tipo_sugerido);
    }

    public function test_rejeita_marcacao_a_menos_de_60_segundos_da_anterior(): void
    {
        $this->travelTo('2026-10-07 17:30:00');
        $colaborador = $this->colaborador();
        $this->ponto()->registrarMarcacao($colaborador, OrigemMarcacao::App);
        $this->travel(59)->seconds();

        try {
            $this->ponto()->registrarMarcacao($colaborador, OrigemMarcacao::App);
            $this->fail('Marcação duplicada foi aceita.');
        } catch (PontoException $e) {
            $this->assertStringContainsString('duplicada', $e->getMessage());
        }

        $this->assertSame(1, PontoMarcacao::count());
    }

    public function test_colaborador_desligado_nao_marca(): void
    {
        $this->travelTo('2026-10-07 17:30:00');
        $colaborador = Colaborador::factory()->desligado()->comEscala()->create();

        $this->expectException(PontoException::class);

        $this->ponto()->registrarMarcacao($colaborador, OrigemMarcacao::App);
    }

    public function test_colaborador_sem_escala_vigente_nao_marca(): void
    {
        $this->travelTo('2026-10-07 17:30:00');
        $colaborador = Colaborador::factory()->create();

        $this->expectException(PontoException::class);

        $this->ponto()->registrarMarcacao($colaborador, OrigemMarcacao::App);
    }

    public function test_fora_da_cerca_grava_sinalizado_quando_nao_bloqueia(): void
    {
        $this->travelTo('2026-10-07 17:30:00');
        config(['ponto.cerca' => ['lat' => -23.5505, 'lng' => -46.6333, 'raio_m' => 150, 'bloquear_fora' => false]]);
        $colaborador = $this->colaborador();

        $marcacao = $this->ponto()->registrarMarcacao($colaborador, OrigemMarcacao::App, ['latitude' => -23.5605, 'longitude' => -46.6333]);

        $this->assertFalse($marcacao->dentro_da_cerca);
    }

    public function test_fora_da_cerca_e_recusada_quando_bloqueia(): void
    {
        $this->travelTo('2026-10-07 17:30:00');
        config(['ponto.cerca' => ['lat' => -23.5505, 'lng' => -46.6333, 'raio_m' => 150, 'bloquear_fora' => true]]);
        $colaborador = $this->colaborador();

        try {
            $this->ponto()->registrarMarcacao($colaborador, OrigemMarcacao::App, ['latitude' => -23.5605, 'longitude' => -46.6333]);
            $this->fail('Marcação fora da cerca foi aceita.');
        } catch (PontoException) {
        }

        $this->assertSame(0, PontoMarcacao::count());
    }

    public function test_dentro_da_cerca_e_marcada_como_dentro(): void
    {
        $this->travelTo('2026-10-07 17:30:00');
        config(['ponto.cerca' => ['lat' => -23.5505, 'lng' => -46.6333, 'raio_m' => 150, 'bloquear_fora' => true]]);
        $colaborador = $this->colaborador();

        $marcacao = $this->ponto()->registrarMarcacao($colaborador, OrigemMarcacao::App, ['latitude' => -23.5510, 'longitude' => -46.6335]);

        $this->assertTrue($marcacao->dentro_da_cerca);
    }

    public function test_marcacao_reapura_o_dia(): void
    {
        $this->travelTo('2026-10-07 17:30:00');
        $colaborador = $this->colaborador();
        $this->ponto()->registrarMarcacao($colaborador, OrigemMarcacao::App);

        $this->travelTo('2026-10-08 00:30:00');
        $this->ponto()->registrarMarcacao($colaborador, OrigemMarcacao::App);

        $dia = PontoDia::sole();
        $this->assertSame('2026-10-07', $dia->data_referencia->toDateString());
        $this->assertSame(441, $dia->minutos_trabalhados);
    }

    public function test_colaborador_solicita_ajuste_e_os_gestores_sao_avisados(): void
    {
        $this->travelTo('2026-10-08 12:00:00');
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $colaborador = Colaborador::factory()->comUsuario()->comEscala()->create();
        Notification::fake();

        $ajuste = $this->ponto()->solicitarAjuste($colaborador, TipoAjuste::Inclusao, MotivoAjuste::Esquecimento, [
            'data_referencia' => '2026-10-07',
            'data_hora' => '2026-10-07 17:30',
            'justificativa' => 'Esqueci de marcar a entrada.',
        ], $colaborador->user);

        $this->assertSame(StatusAjuste::Pendente, $ajuste->status);
        $this->assertSame('2026-10-07 17:30', $ajuste->data_hora->format('Y-m-d H:i'));
        Notification::assertSentTo($gerente, AjusteSolicitadoNotification::class);
    }

    public function test_ninguem_solicita_ajuste_do_ponto_de_outro(): void
    {
        $this->travelTo('2026-10-08 12:00:00');
        $colaborador = Colaborador::factory()->comUsuario()->comEscala()->create();
        $outro = User::factory()->create(['name_first' => 'Outro']);

        $this->expectException(PontoException::class);

        $this->ponto()->solicitarAjuste($colaborador, TipoAjuste::Abono, MotivoAjuste::Atestado, [
            'data_referencia' => '2026-10-07',
            'justificativa' => 'Atestado médico.',
        ], $outro);
    }

    public function test_inclusao_fora_do_dia_operacional_e_recusada(): void
    {
        $this->travelTo('2026-10-08 12:00:00');
        $colaborador = Colaborador::factory()->comUsuario()->comEscala()->create();

        $this->expectException(PontoException::class);

        $this->ponto()->solicitarAjuste($colaborador, TipoAjuste::Inclusao, MotivoAjuste::Esquecimento, [
            'data_referencia' => '2026-10-07',
            'data_hora' => '2026-10-08 06:00',
            'justificativa' => 'Esqueci de marcar.',
        ], $colaborador->user);
    }

    public function test_gerente_aprova_ajuste_reapura_e_avisa_o_colaborador(): void
    {
        $this->travelTo('2026-10-08 12:00:00');
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $colaborador = Colaborador::factory()->comUsuario()->comEscala()->create();
        PontoMarcacao::factory()->for($colaborador)->em('2026-10-07 17:30')->create();
        $ajuste = PontoAjuste::factory()->for($colaborador)->inclusao('2026-10-08 00:30')->create(['data_referencia' => '2026-10-07']);
        Notification::fake();

        $this->ponto()->aprovarAjuste($ajuste, $gerente);

        $this->assertSame(StatusAjuste::Aprovado, $ajuste->fresh()->status);
        $this->assertSame($gerente->id, $ajuste->fresh()->aprovado_por);
        $this->assertSame(441, PontoDia::sole()->minutos_trabalhados);
        Notification::assertSentTo($colaborador->user, AjusteDecididoNotification::class);
    }

    public function test_gerente_nao_aprova_ajuste_do_proprio_ponto(): void
    {
        $this->travelTo('2026-10-08 12:00:00');
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $colaborador = Colaborador::factory()->for($gerente, 'user')->comEscala()->create();
        $ajuste = PontoAjuste::factory()->for($colaborador)->abono()->create(['data_referencia' => '2026-10-07']);

        try {
            $this->ponto()->aprovarAjuste($ajuste, $gerente);
            $this->fail('O gerente aprovou o próprio ajuste.');
        } catch (PontoException) {
        }

        $this->assertSame(StatusAjuste::Pendente, $ajuste->fresh()->status);
    }

    public function test_ajuste_em_periodo_em_conferencia_e_bloqueado(): void
    {
        $this->travelTo('2026-10-12 12:00:00');
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $colaborador = Colaborador::factory()->comUsuario()->comEscala()->create();
        PontoPeriodo::factory()->entre('2026-10-05', '2026-10-11')->status(StatusPeriodo::EmConferencia)->create();
        $ajuste = PontoAjuste::factory()->for($colaborador)->abono()->create(['data_referencia' => '2026-10-07']);

        $this->expectException(PontoException::class);

        $this->ponto()->aprovarAjuste($ajuste, $gerente);
    }

    public function test_rejeicao_exige_motivo_e_avisa_o_colaborador(): void
    {
        $this->travelTo('2026-10-08 12:00:00');
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $colaborador = Colaborador::factory()->comUsuario()->comEscala()->create();
        $ajuste = PontoAjuste::factory()->for($colaborador)->abono()->create(['data_referencia' => '2026-10-07']);
        Notification::fake();

        $this->ponto()->rejeitarAjuste($ajuste, $gerente, 'Sem atestado anexado.');

        $this->assertSame(StatusAjuste::Rejeitado, $ajuste->fresh()->status);
        $this->assertSame('Sem atestado anexado.', $ajuste->fresh()->motivo_rejeicao);
        Notification::assertSentTo($colaborador->user, AjusteDecididoNotification::class);
    }

    private function colaborador(): Colaborador
    {
        return Colaborador::factory()->comEscala(Escala::factory()->noitePizzaria()->create())->create();
    }

    private function ponto(): PontoService
    {
        return app(PontoService::class);
    }
}
