<?php

namespace Tests\Feature;

use App\Enums\Ponto\OrigemMarcacao;
use App\Models\Colaborador;
use App\Models\Escala;
use App\Models\PontoMarcacao;
use App\Services\Ponto\IntegridadePonto;
use App\Services\Ponto\PontoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntegridadePontoTest extends TestCase
{
    use RefreshDatabase;

    public function test_horario_alterado_direto_no_banco_e_detectado(): void
    {
        $alvo = $this->tresMarcacoes()[1];

        DB::table('ponto_marcacoes')->where('id', $alvo->id)->update(['registrada_em' => '2026-10-07 18:00:00']);

        $problemas = app(IntegridadePonto::class)->verificar();

        $this->assertSame([2], array_column($problemas, 'nsr'));
        $this->artisan('ponto:verificar-integridade')->assertFailed();
    }

    public function test_marcacao_apagada_direto_no_banco_quebra_a_sequencia(): void
    {
        $alvo = $this->tresMarcacoes()[1];

        DB::table('ponto_marcacoes')->where('id', $alvo->id)->delete();

        $this->assertNotSame([], app(IntegridadePonto::class)->verificar());
    }

    public function test_cadeia_intacta_passa(): void
    {
        $this->tresMarcacoes();

        $this->artisan('ponto:verificar-integridade')->assertSuccessful();
    }

    /** @return array<int, PontoMarcacao> */
    private function tresMarcacoes(): array
    {
        $this->travelTo('2026-10-07 17:30:00');
        $colaborador = Colaborador::factory()->comEscala(Escala::factory()->noitePizzaria()->create())->create();
        $ponto = app(PontoService::class);

        $marcacoes = [$ponto->registrarMarcacao($colaborador, OrigemMarcacao::App)];
        $this->travel(3)->hours();
        $marcacoes[] = $ponto->registrarMarcacao($colaborador, OrigemMarcacao::App);
        $this->travel(1)->hours();
        $marcacoes[] = $ponto->registrarMarcacao($colaborador, OrigemMarcacao::App);

        return $marcacoes;
    }
}
