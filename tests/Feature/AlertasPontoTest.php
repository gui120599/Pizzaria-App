<?php

namespace Tests\Feature;

use App\Models\Colaborador;
use App\Models\Escala;
use App\Models\PontoMarcacao;
use App\Models\User;
use App\Notifications\Ponto\MarcacaoFaltanteNotification;
use App\Services\Ponto\AlertasPonto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AlertasPontoTest extends TestCase
{
    use RefreshDatabase;

    public function test_entrada_nao_marcada_avisa_o_gestor_uma_vez_so(): void
    {
        $this->travelTo('2026-10-07 18:10:00');
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $this->colaborador();

        $this->alertas()->alertarFaltantes(now());
        $this->travel(30)->minutes();
        $this->alertas()->alertarFaltantes(now());

        $this->assertSame(1, $gerente->notifications()->where('type', MarcacaoFaltanteNotification::class)->count());
    }

    public function test_nao_avisa_antes_dos_30_minutos_de_tolerancia(): void
    {
        $this->travelTo('2026-10-07 17:50:00');
        User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $this->colaborador();

        $this->assertSame(0, $this->alertas()->alertarFaltantes(now()));
    }

    public function test_nao_avisa_quem_ja_marcou_a_entrada(): void
    {
        $this->travelTo('2026-10-07 18:10:00');
        User::factory()->gerente()->create(['name_first' => 'Gerente']);
        PontoMarcacao::factory()->for($this->colaborador())->em('2026-10-07 17:31')->create();

        $this->assertSame(0, $this->alertas()->alertarFaltantes(now()));
    }

    public function test_marcacao_sem_saida_depois_do_turno_avisa_o_colaborador(): void
    {
        $this->travelTo('2026-10-08 01:40:00');
        $colaborador = Colaborador::factory()->comUsuario()->comEscala(Escala::factory()->noitePizzaria()->create())->create();
        PontoMarcacao::factory()->for($colaborador)->em('2026-10-07 17:30')->create();

        $this->alertas()->alertarFaltantes(Carbon::now());

        $this->assertSame(1, $colaborador->user->notifications()->where('data->motivo', MarcacaoFaltanteNotification::SAIDA)->count());
    }

    private function colaborador(): Colaborador
    {
        return Colaborador::factory()->comEscala(Escala::factory()->noitePizzaria()->create())->create();
    }

    private function alertas(): AlertasPonto
    {
        return app(AlertasPonto::class);
    }
}
