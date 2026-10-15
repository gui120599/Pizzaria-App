<?php

namespace Tests\Feature;

use App\Enums\Ponto\MotivoAjuste;
use App\Enums\Ponto\OrigemAjuste;
use App\Enums\Ponto\OrigemMarcacao;
use App\Enums\Ponto\StatusAjuste;
use App\Enums\Ponto\StatusPeriodo;
use App\Enums\Ponto\StatusRepasse;
use App\Enums\Ponto\TipoAjuste;
use App\Filament\Garcom\Pages\BaterPonto;
use App\Filament\Garcom\Pages\MeuEspelho;
use App\Filament\Garcom\Pages\MeusRepasses;
use App\Models\Colaborador;
use App\Models\PontoAjuste;
use App\Models\PontoMarcacao;
use App\Models\PontoPeriodo;
use App\Models\Repasse;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Telas do colaborador no /garcom: bater ponto, meu espelho e meus repasses (spec §7). */
class PainelGarcomPontoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('garcom'));
        $this->travelTo('2026-10-14 18:00:00');
    }

    public function test_colaborador_sem_papel_do_salao_entra_direto_no_ponto(): void
    {
        $this->actingAs($this->colaborador()->user);

        $this->get('/garcom')->assertRedirect(BaterPonto::getUrl());
        $this->get(BaterPonto::getUrl())->assertOk()->assertSeeText('Entrada');
        $this->get('/garcom/checklists')->assertForbidden();
        $this->get('/garcom/historico-checklists')->assertForbidden();
    }

    public function test_entregador_com_cadastro_de_colaborador_bate_ponto_pelo_garcom(): void
    {
        $entregador = User::factory()->entregador()->create(['name_first' => 'Edu']);
        Colaborador::factory()->entregador()->comEscala()->for($entregador, 'user')->create();

        $this->actingAs($entregador)->get(BaterPonto::getUrl())->assertOk();
    }

    public function test_garcom_sem_cadastro_de_colaborador_nao_ve_as_telas_do_ponto(): void
    {
        $this->actingAs(User::factory()->garcom()->create(['name_first' => 'Bruno']));

        $this->get(BaterPonto::getUrl())->assertForbidden();
        $this->get(MeusRepasses::getUrl())->assertForbidden();
    }

    public function test_bater_ponto_registra_com_localizacao_e_mostra_comprovante(): void
    {
        $colaborador = $this->colaborador();
        $this->actingAs($colaborador->user);

        Livewire::test(BaterPonto::class)
            ->call('bater', -16.6869, -49.2648, 12)
            ->assertSet('comprovante.tipo', 'Entrada')
            ->assertSet('comprovante.sem_localizacao', false);

        $marcacao = PontoMarcacao::query()->sole();
        $this->assertSame($colaborador->id, $marcacao->colaborador_id);
        $this->assertSame(OrigemMarcacao::App, $marcacao->origem);
        $this->assertSame('-16.6869000', (string) $marcacao->latitude);
        $this->assertSame(12, $marcacao->precisao_m);
        $this->assertSame($colaborador->user_id, $marcacao->registrada_por);
    }

    public function test_localizacao_negada_registra_sem_coordenadas_e_avisa(): void
    {
        $this->actingAs($this->colaborador()->user);

        Livewire::test(BaterPonto::class)
            ->call('bater', null, null, null)
            ->assertSet('comprovante.sem_localizacao', true);

        $this->assertNull(PontoMarcacao::query()->sole()->latitude);
    }

    public function test_selfie_enviada_fica_no_disco_do_ponto(): void
    {
        Storage::fake((string) config('ponto.disco'));
        $this->actingAs($this->colaborador()->user);

        Livewire::test(BaterPonto::class)
            ->set('selfie', UploadedFile::fake()->image('selfie.jpg', 640, 480))
            ->call('bater', null, null, null)
            ->assertSet('selfie', null);

        Storage::disk((string) config('ponto.disco'))->assertExists(PontoMarcacao::query()->sole()->foto_caminho);
    }

    public function test_erro_do_servico_nao_registra_e_avisa(): void
    {
        $colaborador = Colaborador::factory()->comUsuario()->create();
        $this->actingAs($colaborador->user);

        Livewire::test(BaterPonto::class)
            ->call('bater', null, null, null)
            ->assertNotified('Ponto não registrado')
            ->assertSet('comprovante', null);

        $this->assertSame(0, PontoMarcacao::query()->count());
    }

    public function test_meu_espelho_destaca_correcao_e_registra_ciencia(): void
    {
        $colaborador = $this->colaborador();
        $correcao = PontoAjuste::factory()->doGestor()->inclusao('2026-10-13 18:00:00')
            ->create(['colaborador_id' => $colaborador->id, 'data_referencia' => '2026-10-13']);
        $this->actingAs($colaborador->user);

        Livewire::test(MeuEspelho::class)
            ->assertSeeText('corrigido pelo gestor')
            ->callAction('ciente', arguments: ['dia' => '2026-10-13'])
            ->assertNotified('Ciência registrada');

        $this->assertNotNull($correcao->fresh()->colaborador_ciente_em);
    }

    public function test_meu_espelho_nao_mostra_o_ponto_de_outro_colaborador(): void
    {
        $ana = $this->colaborador();
        $bruno = $this->colaborador();
        PontoAjuste::factory()->doGestor()->inclusao('2026-10-13 18:00:00')
            ->create(['colaborador_id' => $bruno->id, 'data_referencia' => '2026-10-13']);
        $this->actingAs($ana->user);

        Livewire::test(MeuEspelho::class)->assertDontSeeText('corrigido pelo gestor');
    }

    public function test_solicitar_ajuste_pelo_espelho_cria_pedido_pendente(): void
    {
        $colaborador = $this->colaborador();
        $this->actingAs($colaborador->user);

        Livewire::test(MeuEspelho::class)
            ->callAction('solicitarAjuste', [
                'tipo' => TipoAjuste::Inclusao->value,
                'hora' => '18:05',
                'motivo' => MotivoAjuste::Esquecimento->value,
                'justificativa' => 'Esqueci de marcar a entrada.',
            ], ['dia' => '2026-10-13'])
            ->assertHasNoFormErrors()
            ->assertNotified('Pedido enviado ao gestor');

        $ajuste = PontoAjuste::query()->sole();
        $this->assertSame(StatusAjuste::Pendente, $ajuste->status);
        $this->assertSame(OrigemAjuste::Colaborador, $ajuste->origem);
        $this->assertSame('2026-10-13 18:05', $ajuste->data_hora->format('Y-m-d H:i'));
    }

    public function test_solicitar_ajuste_em_periodo_fechado_e_bloqueado(): void
    {
        $colaborador = $this->colaborador();
        PontoPeriodo::factory()->entre('2026-10-05', '2026-10-11')->status(StatusPeriodo::Fechado)->create();
        $this->actingAs($colaborador->user);

        Livewire::test(MeuEspelho::class)
            ->callAction('solicitarAjuste', [
                'tipo' => TipoAjuste::Inclusao->value,
                'hora' => '18:05',
                'motivo' => MotivoAjuste::Esquecimento->value,
                'justificativa' => 'Esqueci de marcar a entrada.',
            ], ['dia' => '2026-10-10'])
            ->assertNotified('Pedido não enviado');

        $this->assertSame(0, PontoAjuste::query()->count());
    }

    public function test_meus_repasses_lista_so_os_proprios_aprovados_e_registra_ciencia(): void
    {
        $colaborador = $this->colaborador();
        $periodo = PontoPeriodo::factory()->entre('2026-10-05', '2026-10-11')->status(StatusPeriodo::Fechado)->create();
        $aprovado = Repasse::factory()->status(StatusRepasse::Aprovado)->create(['ponto_periodo_id' => $periodo->id, 'colaborador_id' => $colaborador->id, 'valor_liquido' => 432.10, 'aprovado_em' => now()]);
        Repasse::factory()->create(['ponto_periodo_id' => $periodo->id, 'colaborador_id' => $this->colaborador()->id, 'valor_liquido' => 999.99, 'status' => StatusRepasse::Aprovado]);
        $this->actingAs($colaborador->user);

        Livewire::test(MeusRepasses::class)
            ->assertSeeText('R$ 432,10')
            ->assertDontSeeText('R$ 999,99')
            ->callAction('ciencia', arguments: ['repasse' => $aprovado->id])
            ->assertNotified('Ciência registrada');

        $this->assertNotNull($aprovado->fresh()->ciente_em);
        $this->assertNotNull($aprovado->fresh()->ciente_ip);
    }

    public function test_rascunho_nao_aparece_e_repasse_de_outro_e_negado(): void
    {
        $colaborador = $this->colaborador();
        $periodo = PontoPeriodo::factory()->entre('2026-10-05', '2026-10-11')->create();
        Repasse::factory()->create(['ponto_periodo_id' => $periodo->id, 'colaborador_id' => $colaborador->id, 'valor_liquido' => 123.45]);
        $deOutro = Repasse::factory()->status(StatusRepasse::Aprovado)->create(['ponto_periodo_id' => $periodo->id, 'colaborador_id' => $this->colaborador()->id]);
        $this->actingAs($colaborador->user);

        Livewire::test(MeusRepasses::class)
            ->assertDontSeeText('R$ 123,45')
            ->callAction('ciencia', arguments: ['repasse' => $deOutro->id])
            ->assertForbidden();

        $this->assertNull($deOutro->fresh()->ciente_em);
    }

    private function colaborador(): Colaborador
    {
        return Colaborador::factory()->comUsuario()->comEscala()->create();
    }
}
