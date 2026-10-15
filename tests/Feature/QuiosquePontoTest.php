<?php

namespace Tests\Feature;

use App\Enums\Ponto\OrigemMarcacao;
use App\Livewire\Ponto\Quiosque;
use App\Models\Colaborador;
use App\Models\PontoMarcacao;
use App\Services\Ponto\QuiosquePonto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/** Quiosque de ponto no tablet da loja (spec §7): pareamento, PIN e limite de tentativas. */
class QuiosquePontoTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'tablet-da-loja-token-de-teste-0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        config(['ponto.quiosque.tokens' => [self::TOKEN]]);
        $this->travelTo('2026-10-14 18:00:00');
    }

    public function test_aparelho_sem_pareamento_nao_abre_o_quiosque(): void
    {
        $this->get(route('ponto.quiosque'))->assertForbidden();
    }

    public function test_pareamento_com_token_valido_grava_o_cookie(): void
    {
        $this->get(route('ponto.quiosque.parear', 'token-errado'))->assertForbidden();

        $this->get(route('ponto.quiosque.parear', self::TOKEN))
            ->assertRedirect(route('ponto.quiosque'))
            ->assertCookie(QuiosquePonto::COOKIE, self::TOKEN);
    }

    public function test_tablet_pareado_abre_o_quiosque(): void
    {
        $this->withCookie(QuiosquePonto::COOKIE, self::TOKEN)
            ->get(route('ponto.quiosque'))
            ->assertOk()
            ->assertSeeText('Digite seu PIN');
    }

    public function test_pin_identifica_e_confirmar_registra_pelo_quiosque(): void
    {
        $colaborador = Colaborador::factory()->comPin('4821')->comEscala()->create(['nome' => 'Ana Quiosque']);

        $this->quiosque()
            ->set('pin', '4821')
            ->call('identificar')
            ->assertSet('colaboradorId', $colaborador->id)
            ->assertSeeText('Ana Quiosque')
            ->assertSeeText('Entrada')
            ->call('confirmar')
            ->assertSet('comprovante.nome', 'Ana Quiosque')
            ->assertSet('colaboradorId', null);

        $marcacao = PontoMarcacao::query()->sole();
        $this->assertSame($colaborador->id, $marcacao->colaborador_id);
        $this->assertSame(OrigemMarcacao::Quiosque, $marcacao->origem);
        $this->assertNull($marcacao->registrada_por);
    }

    public function test_pin_errado_cinco_vezes_bloqueia_o_tablet(): void
    {
        Colaborador::factory()->comPin('4821')->comEscala()->create();
        $quiosque = $this->quiosque();

        foreach (range(1, 5) as $tentativa) {
            $quiosque->set('pin', '0000')->call('identificar');
        }

        $quiosque->set('pin', '4821')
            ->call('identificar')
            ->assertSet('colaboradorId', null)
            ->assertSet('erro', fn (string $erro): bool => str_contains($erro, 'Muitas tentativas'));
    }

    public function test_identificacao_expira_e_nao_registra(): void
    {
        Colaborador::factory()->comPin('4821')->comEscala()->create();
        $quiosque = $this->quiosque()->set('pin', '4821')->call('identificar');

        $this->travel(31)->seconds();
        $quiosque->call('confirmar')->assertSet('erro', 'Tempo esgotado. Digite o PIN de novo.');

        $this->assertSame(0, PontoMarcacao::query()->count());
    }

    public function test_colaborador_desligado_nao_e_achado_pelo_pin(): void
    {
        Colaborador::factory()->comPin('4821')->comEscala()->desligado()->create();

        $this->quiosque()->set('pin', '4821')->call('identificar')
            ->assertSet('colaboradorId', null)
            ->assertSet('erro', fn (string $erro): bool => str_contains($erro, 'PIN não reconhecido'));
    }

    private function quiosque(): Testable
    {
        return Livewire::withCookie(QuiosquePonto::COOKIE, self::TOKEN)->test(Quiosque::class);
    }
}
