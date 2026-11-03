<?php

namespace Tests\Feature;

use App\Enums\DestinoAvisoMesaEnum;
use App\Enums\TipoChamadoMesaEnum;
use App\Exceptions\AutorizacaoNegadaException;
use App\Filament\Garcom\Pages\PlantaoFreelancers;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\DispositivoPush;
use App\Models\Empresa;
use App\Models\Mesa;
use App\Models\PlantaoFreelancer;
use App\Models\PushEnvio;
use App\Models\SessaoMesa;
use App\Models\User;
use App\Services\Garcom\PlantaoFreelancerService;
use App\Services\MesaCliente\MesaChamadoService;
use App\Services\MesaCliente\ParticipanteMesaService;
use App\Services\Push\EnviadorPush;
use App\Services\SessaoMesaService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\FakeEnviadorPush;
use Tests\TestCase;

/** Plantão do dia dos garçons freelancers (marcado pelo gerente) e o push do salão. */
class PlantaoFreelancerTest extends TestCase
{
    use RefreshDatabase;

    private FakeEnviadorPush $enviador;

    protected function setUp(): void
    {
        parent::setUp();

        config(['pizzaria.push.ativo' => true, 'pizzaria.push.envio' => 'sync']);
        Filament::setCurrentPanel(Filament::getPanel('garcom'));
        $this->travelTo('2026-11-03 20:00:00');

        $this->enviador = new FakeEnviadorPush;
        $this->app->instance(EnviadorPush::class, $this->enviador);
    }

    private function usuario(string $estado, string $nome, bool $freelancer = false): User
    {
        return User::factory()->{$estado}()->create(['name_first' => $nome, 'user_freelancer' => $freelancer]);
    }

    private function freelancer(string $nome = 'Fred'): User
    {
        $usuario = $this->usuario('garcom', $nome, true);
        DispositivoPush::factory()->create(['dp_user_id' => $usuario->id]);

        return $usuario;
    }

    private function servico(): PlantaoFreelancerService
    {
        return app(PlantaoFreelancerService::class);
    }

    private function abrirMesaPeloQr(): void
    {
        app(MesaChamadoService::class)->abrir(Mesa::factory()->create(), TipoChamadoMesaEnum::ABRIR_MESA);
    }

    private function sessaoDe(User $dono): SessaoMesa
    {
        return app(SessaoMesaService::class)->abrir(Mesa::factory()->create(['mesa_nome' => 'Mesa 05'])->id, $dono->id, pessoas: 2);
    }

    // ----- tela do gerente -----

    public function test_gerente_e_admin_acessam_o_plantao_e_garcom_nao(): void
    {
        foreach (['gerente', 'admin'] as $estado) {
            $this->actingAs($this->usuario($estado, ucfirst($estado)))->get('/garcom/plantao')->assertOk();
        }

        $this->actingAs($this->usuario('garcom', 'Gabi'))->get('/garcom/plantao')->assertForbidden();
    }

    public function test_sem_push_ligado_a_tela_nao_existe(): void
    {
        config(['pizzaria.push.ativo' => false]);

        $this->actingAs($this->usuario('gerente', 'Gil'))->get('/garcom/plantao')->assertForbidden();
    }

    public function test_lista_so_os_freelancers_com_papel_do_salao(): void
    {
        $fred = $this->freelancer('Fred');
        $this->usuario('garcom', 'Regular');
        $this->usuario('cozinha', 'Cozinheiro', true);

        $lista = $this->servico()->freelancers();

        $this->assertSame([$fred->id], $lista->pluck('id')->all());
        $this->assertTrue($lista->first()['recebe_notificacoes']);
        $this->assertFalse($lista->first()['trabalhando']);
    }

    public function test_gerente_marca_e_desmarca_pela_tela(): void
    {
        $gerente = $this->usuario('gerente', 'Gil');
        $fred = $this->freelancer();
        $this->actingAs($gerente);

        Livewire::test(PlantaoFreelancers::class)
            ->assertSee('Fred')
            ->call('alternar', $fred->id);

        $plantao = PlantaoFreelancer::query()->sole();
        $this->assertSame($fred->id, $plantao->pl_user_id);
        $this->assertSame($gerente->id, $plantao->pl_marcado_por_id);
        $this->assertSame('2026-11-03', $plantao->pl_dia->toDateString());

        Livewire::test(PlantaoFreelancers::class)->call('alternar', $fred->id);

        $this->assertDatabaseCount('plantoes_freelancer', 0);
    }

    public function test_alternar_ignora_usuario_que_nao_e_freelancer(): void
    {
        $regular = $this->usuario('garcom', 'Regular');
        $this->actingAs($this->usuario('gerente', 'Gil'));

        $this->expectException(ModelNotFoundException::class);

        Livewire::test(PlantaoFreelancers::class)->call('alternar', $regular->id);
    }

    public function test_servico_nao_duplica_e_exige_gerencia_e_freelancer(): void
    {
        $gerente = $this->usuario('gerente', 'Gil');
        $fred = $this->freelancer();

        $this->servico()->marcar($fred, $gerente);
        $this->servico()->marcar($fred, $gerente);
        $this->assertDatabaseCount('plantoes_freelancer', 1);

        try {
            $this->servico()->marcar($fred, $this->usuario('garcom', 'Gabi'));
            $this->fail('garçom não marca plantão');
        } catch (AutorizacaoNegadaException) {
        }

        $this->expectException(AutorizacaoNegadaException::class);
        $this->servico()->marcar($this->usuario('garcom', 'Regular'), $gerente);
    }

    // ----- push -----

    public function test_freelancer_no_plantao_recebe_o_quer_abrir_a_mesa_sem_ponto(): void
    {
        $fred = $this->freelancer();
        $this->servico()->marcar($fred, $this->usuario('gerente', 'Gil'));

        $this->abrirMesaPeloQr();

        $this->assertSame([$fred->id], PushEnvio::query()->pluck('pe_user_id')->all());
    }

    public function test_freelancer_sem_plantao_nao_recebe_o_quer_abrir_a_mesa(): void
    {
        $this->freelancer();

        $this->abrirMesaPeloQr();

        $this->assertDatabaseCount('push_envios', 0);
    }

    public function test_plantao_de_ontem_nao_vale_hoje(): void
    {
        $fred = $this->freelancer();
        PlantaoFreelancer::factory()->create(['pl_user_id' => $fred->id, 'pl_dia' => '2026-11-02']);

        $this->abrirMesaPeloQr();

        $this->assertDatabaseCount('push_envios', 0);
    }

    public function test_plantao_vale_ate_a_virada_do_dia_operacional(): void
    {
        $fred = $this->freelancer();
        $this->servico()->marcar($fred, $this->usuario('gerente', 'Gil'));

        $this->travelTo('2026-11-04 04:30:00'); // madrugada: ainda é o dia operacional de 03/11
        $this->abrirMesaPeloQr();
        $this->assertDatabaseCount('push_envios', 1);

        $this->travelTo('2026-11-04 05:01:00'); // virou o dia
        $this->abrirMesaPeloQr();
        $this->assertDatabaseCount('push_envios', 1);
    }

    public function test_no_modo_todos_o_freelancer_do_plantao_recebe_avisos_de_mesa_alheia(): void
    {
        Empresa::create(['empresa_razao_social' => 'Pizzaria', 'empresa_push_destino_mesa' => DestinoAvisoMesaEnum::TODOS_EM_TURNO->value]);
        $dono = $this->usuario('garcom', 'Ana');
        DispositivoPush::factory()->create(['dp_user_id' => $dono->id]);
        $fred = $this->freelancer();
        $folgado = $this->freelancer('Fabio');
        $this->servico()->marcar($fred, $this->usuario('gerente', 'Gil'));

        $sessao = $this->sessaoDe($dono);
        $participante = app(ParticipanteMesaService::class)->entrar($sessao->mesa->fresh(), 'Cli', '64999991234', $sessao->sessao_mesa_pin)['participante'];
        app(MesaChamadoService::class)->abrir($sessao->mesa->fresh(), TipoChamadoMesaEnum::CHAMAR_GARCOM, $participante);

        $recebeu = PushEnvio::query()->pluck('pe_user_id')->unique()->all();
        $this->assertEqualsCanonicalizing([$dono->id, $fred->id], $recebeu);
        $this->assertNotContains($folgado->id, $recebeu);
    }

    public function test_freelancer_do_plantao_com_aparelho_pausado_nao_recebe(): void
    {
        $fred = $this->freelancer();
        $this->servico()->marcar($fred, $this->usuario('gerente', 'Gil'));
        DispositivoPush::query()->where('dp_user_id', $fred->id)->update(['dp_pausado_ate' => now()->addHour()]);

        $this->abrirMesaPeloQr();

        $this->assertDatabaseCount('push_envios', 0);
    }

    // ----- botão do Admin e cadastro -----

    public function test_botao_do_painel_administrativo_so_aparece_para_quem_tem_acesso(): void
    {
        $this->actingAs($this->usuario('gerente', 'Gil'))->get('/garcom')->assertOk()->assertSee('Painel administrativo');
        $this->actingAs($this->usuario('garcom', 'Gabi'))->get('/garcom')->assertOk()->assertDontSee('Painel administrativo');
    }

    public function test_admin_liga_o_freelancer_no_cadastro_do_usuario(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = $this->usuario('admin', 'Admin');
        $garcom = $this->usuario('garcom', 'Gabi');
        $this->actingAs($admin);

        Livewire::test(ManageUsers::class)
            ->callAction(TestAction::make('edit')->table($garcom), ['user_freelancer' => true])
            ->assertHasNoFormErrors();

        $this->assertTrue($garcom->fresh()->user_freelancer);
    }
}
