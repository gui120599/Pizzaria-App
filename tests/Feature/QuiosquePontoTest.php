<?php

namespace Tests\Feature;

use App\Enums\Ponto\OrigemMarcacao;
use App\Livewire\Ponto\Quiosque;
use App\Models\Colaborador;
use App\Models\PontoMarcacao;
use App\Models\User;
use App\Services\Ponto\QuiosquePonto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/** Quiosque de ponto no tablet da loja (spec §7): pareamento, PIN, limite de tentativas e selfie. */
class QuiosquePontoTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'tablet-da-loja-token-de-teste-0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        config(['ponto.quiosque.tokens' => [self::TOKEN], 'ponto.quiosque.exigir_selfie' => true]);
        Storage::fake((string) config('ponto.disco'));
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
            ->call('confirmar', $this->selfie())
            ->assertSet('comprovante.nome', 'Ana Quiosque')
            ->assertSet('colaboradorId', null);

        $marcacao = PontoMarcacao::query()->sole();
        $this->assertSame($colaborador->id, $marcacao->colaborador_id);
        $this->assertSame(OrigemMarcacao::Quiosque, $marcacao->origem);
        $this->assertNull($marcacao->registrada_por);
        Storage::disk((string) config('ponto.disco'))->assertExists($marcacao->foto_caminho);
    }

    public function test_sem_selfie_o_tablet_nao_registra_quando_obrigatoria(): void
    {
        Colaborador::factory()->comPin('4821')->comEscala()->create();

        $this->quiosque()->set('pin', '4821')->call('identificar')
            ->call('confirmar')
            ->assertSet('erro', 'Tire a selfie para registrar o ponto.');

        $this->assertSame(0, PontoMarcacao::query()->count());
    }

    public function test_sem_selfie_registra_quando_o_tablet_nao_exige(): void
    {
        config(['ponto.quiosque.exigir_selfie' => false, 'ponto.exigir_selfie' => true]);
        Colaborador::factory()->comPin('4821')->comEscala()->create();

        $this->quiosque()->set('pin', '4821')->call('identificar')->call('confirmar');

        $this->assertNull(PontoMarcacao::query()->sole()->foto_caminho);
    }

    public function test_selfie_que_nao_e_imagem_ou_grande_demais_e_recusada(): void
    {
        Colaborador::factory()->comPin('4821')->comEscala()->create();
        $quiosque = $this->quiosque();

        $quiosque->set('pin', '4821')->call('identificar')
            ->call('confirmar', 'data:image/jpeg;base64,'.base64_encode('<?php echo "não sou foto";'))
            ->assertSet('erro', 'A selfie não é uma imagem válida. Tente de novo.');

        $quiosque->set('pin', '4821')->call('identificar')
            ->call('confirmar', 'data:image/jpeg;base64,'.base64_encode(str_repeat('a', 1_048_577)))
            ->assertSet('erro', 'A selfie ficou grande demais. Tente de novo.');

        $this->assertSame(0, PontoMarcacao::query()->count());
    }

    public function test_selfie_da_marcacao_so_para_quem_ve_o_espelho(): void
    {
        $ana = Colaborador::factory()->comPin('4821')->comEscala()->comUsuario()->create();
        $this->quiosque()->set('pin', '4821')->call('identificar')->call('confirmar', $this->selfie());
        $marcacao = PontoMarcacao::query()->sole();
        $rota = route('ponto.marcacoes.selfie', $marcacao);

        $this->actingAs(User::factory()->gerente()->create(['name_first' => 'Gerente']))->get($rota)->assertOk();
        $this->actingAs($ana->user)->get($rota)->assertOk();
        $this->actingAs(Colaborador::factory()->comUsuario()->create()->user)->get($rota)->assertForbidden();

        Storage::disk((string) config('ponto.disco'))->delete($marcacao->foto_caminho);
        $this->actingAs($ana->user)->get($rota)->assertNotFound();
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

    /** JPEG de verdade, como o canvas do tablet manda. */
    private function selfie(): string
    {
        $imagem = imagecreatetruecolor(64, 64);
        ob_start();
        imagejpeg($imagem, null, 70);

        return 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
    }

    private function quiosque(): Testable
    {
        return Livewire::withCookie(QuiosquePonto::COOKIE, self::TOKEN)->test(Quiosque::class);
    }
}
