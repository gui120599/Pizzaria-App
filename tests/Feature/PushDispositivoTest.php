<?php

namespace Tests\Feature;

use App\Enums\EventoPushGarcomEnum;
use App\Exceptions\PushException;
use App\Filament\Garcom\Pages\Auth\LoginGarcom;
use App\Filament\Garcom\Pages\MapaMesas;
use App\Filament\Garcom\Pages\NotificacoesCelular;
use App\Livewire\PushSincronizador;
use App\Models\DispositivoPush;
use App\Models\PushEnvio;
use App\Models\User;
use App\Services\Push\DispositivoPushService;
use App\Services\Push\EnviadorPush;
use App\Support\Push\ResultadoEnvioPush;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\Support\FakeEnviadorPush;
use Tests\TestCase;

class PushDispositivoTest extends TestCase
{
    use RefreshDatabase;

    private FakeEnviadorPush $enviador;

    protected function setUp(): void
    {
        parent::setUp();

        config(['pizzaria.push.ativo' => true]);
        Filament::setCurrentPanel(Filament::getPanel('garcom'));

        $this->enviador = new FakeEnviadorPush;
        $this->app->instance(EnviadorPush::class, $this->enviador);
    }

    /**
     * @return array{endpoint: string, keys: array{p256dh: string, auth: string}}
     */
    private function inscricao(string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc123'): array
    {
        return ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'BPublicKeyAAAA', 'auth' => 'authSecret']];
    }

    private function garcom(): User
    {
        return User::factory()->garcom()->create(['name_first' => 'Garçom']);
    }

    private function cozinha(): User
    {
        return User::factory()->cozinha()->create(['name_first' => 'Cozinha']);
    }

    private function servico(): DispositivoPushService
    {
        return $this->app->make(DispositivoPushService::class);
    }

    public function test_registra_inscricao_de_usuario_do_salao(): void
    {
        $garcom = $this->garcom();

        $dispositivo = $this->servico()->registrar($garcom, $this->inscricao(), 'Mozilla/5.0 (Linux; Android 14) Chrome/120', true);

        $this->assertSame($garcom->id, $dispositivo->dp_user_id);
        $this->assertTrue($dispositivo->dp_ativo);
        $this->assertSame('android', $dispositivo->dp_plataforma);
        $this->assertSame('Chrome', $dispositivo->dp_navegador);
        $this->assertTrue($dispositivo->dp_instalado);
        $this->assertSame(session(DispositivoPushService::CHAVE_SESSAO), $dispositivo->id);
    }

    public function test_guarda_endpoint_e_chaves_cifrados_no_banco(): void
    {
        $dispositivo = $this->servico()->registrar($this->garcom(), $this->inscricao());

        $bruto = \DB::table('dispositivos_push')->where('id', $dispositivo->id)->first();

        $this->assertStringNotContainsString('fcm.googleapis.com', $bruto->dp_endpoint);
        $this->assertStringNotContainsString('authSecret', $bruto->dp_chave_auth);
        $this->assertSame(hash('sha256', 'https://fcm.googleapis.com/fcm/send/abc123'), $bruto->dp_endpoint_hash);
    }

    public function test_nega_usuario_sem_papel_do_salao(): void
    {
        $cozinha = $this->cozinha();

        $this->expectException(PushException::class);

        try {
            $this->servico()->registrar($cozinha, $this->inscricao());
        } finally {
            $this->assertDatabaseCount('dispositivos_push', 0);
        }
    }

    public function test_nao_registra_com_a_chave_desligada(): void
    {
        config(['pizzaria.push.ativo' => false]);

        $this->expectException(PushException::class);

        $this->servico()->registrar($this->garcom(), $this->inscricao());
    }

    public function test_mesmo_endpoint_com_outro_usuario_revincula_o_aparelho(): void
    {
        $ana = $this->garcom();
        $bia = $this->garcom();

        $primeiro = $this->servico()->registrar($ana, $this->inscricao());
        $primeiro->update(['dp_pausado_ate' => now()->addHours(2)]);
        $segundo = $this->servico()->registrar($bia, $this->inscricao());

        $this->assertSame($primeiro->id, $segundo->id);
        $this->assertDatabaseCount('dispositivos_push', 1);
        $this->assertSame($bia->id, $segundo->fresh()->dp_user_id);
        $this->assertNull($segundo->fresh()->dp_pausado_ate);
    }

    public function test_reativa_aparelho_desativado_ao_sincronizar(): void
    {
        $garcom = $this->garcom();
        $dispositivo = $this->servico()->registrar($garcom, $this->inscricao());
        $this->servico()->desativar($dispositivo, 'expirado');

        $this->servico()->registrar($garcom, $this->inscricao());

        $dispositivo->refresh();
        $this->assertTrue($dispositivo->dp_ativo);
        $this->assertNull($dispositivo->dp_motivo_desativacao);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function endpointsInvalidos(): array
    {
        return [
            'http puro' => ['http://fcm.googleapis.com/fcm/send/abc'],
            'host fora da lista' => ['https://evil.example.com/fcm/send/abc'],
            'host interno' => ['https://169.254.169.254/latest/meta-data'],
            'sufixo enganoso' => ['https://fcm.googleapis.com.evil.com/x'],
            'usuário na url' => ['https://fcm.googleapis.com@evil.com/x'],
            'porta' => ['https://fcm.googleapis.com:8443/x'],
            'vazio' => [''],
        ];
    }

    /**
     * @dataProvider endpointsInvalidos
     */
    public function test_rejeita_endpoint_fora_da_allowlist(string $endpoint): void
    {
        $this->expectException(PushException::class);

        $this->servico()->registrar($this->garcom(), $this->inscricao($endpoint));
    }

    public function test_aceita_hosts_de_apple_e_mozilla(): void
    {
        $garcom = $this->garcom();

        $this->assertTrue($this->servico()->endpointPermitido('https://web.push.apple.com/QGx9'));
        $this->assertTrue($this->servico()->endpointPermitido('https://updates.push.services.mozilla.com/wpush/v2/abc'));
        $this->assertFalse($this->servico()->endpointPermitido('https://push.apple.com.evil.com/x'));
        $this->assertNotNull($garcom);
    }

    public function test_rejeita_inscricao_sem_chaves(): void
    {
        $this->expectException(PushException::class);

        $this->servico()->registrar($this->garcom(), ['endpoint' => 'https://fcm.googleapis.com/x', 'keys' => []]);
    }

    public function test_logout_desvincula_o_aparelho_da_sessao(): void
    {
        $garcom = $this->garcom();
        $dispositivo = $this->servico()->registrar($garcom, $this->inscricao());

        Event::dispatch(new Logout('web', $garcom));

        $dispositivo->refresh();
        $this->assertNull($dispositivo->dp_user_id);
        $this->assertFalse($dispositivo->dp_ativo);
        $this->assertSame('logout', $dispositivo->dp_motivo_desativacao);
        $this->assertNull(session(DispositivoPushService::CHAVE_SESSAO));
    }

    public function test_logout_sem_aparelho_na_sessao_nao_faz_nada(): void
    {
        $outro = DispositivoPush::factory()->create(['dp_user_id' => $this->garcom()->id]);

        Event::dispatch(new Logout('web', $this->garcom()));

        $this->assertTrue($outro->fresh()->dp_ativo);
    }

    public function test_pausar_e_retomar(): void
    {
        $dispositivo = $this->servico()->registrar($this->garcom(), $this->inscricao());

        $this->servico()->pausar($dispositivo, now()->addHours(4));
        $this->assertTrue($dispositivo->fresh()->estaPausado());
        $this->assertSame(0, DispositivoPush::query()->recebendo()->count());

        $this->servico()->retomar($dispositivo);
        $this->assertFalse($dispositivo->fresh()->estaPausado());
        $this->assertSame(1, DispositivoPush::query()->recebendo()->count());
    }

    public function test_envia_teste_so_para_o_aparelho_com_payload_minimo(): void
    {
        $garcom = $this->garcom();
        $dispositivo = $this->servico()->registrar($garcom, $this->inscricao());

        $envio = $this->servico()->enviarTeste($garcom, $dispositivo);

        $this->assertSame(PushEnvio::ENVIADO, $envio->pe_status);
        $this->assertSame(EventoPushGarcomEnum::TESTE, $envio->pe_evento);
        $this->assertCount(1, $this->enviador->enviados);
        $enviado = $this->enviador->enviados[0];
        $this->assertSame($dispositivo->id, $enviado['dispositivo_id']);
        $this->assertSame('/garcom', $enviado['payload']['url']);
        $this->assertSame('high', $enviado['urgencia']);
        $this->assertSame(EventoPushGarcomEnum::TESTE->ttl(), $enviado['ttl']);
        $this->assertNotNull($dispositivo->fresh()->dp_ultimo_sucesso_em);
    }

    public function test_teste_tem_limite_de_um_a_cada_trinta_segundos(): void
    {
        Cache::flush();
        $garcom = $this->garcom();
        $dispositivo = $this->servico()->registrar($garcom, $this->inscricao());

        $this->servico()->enviarTeste($garcom, $dispositivo);

        $this->expectException(PushException::class);
        $this->servico()->enviarTeste($garcom, $dispositivo);
    }

    public function test_resposta_410_desativa_o_aparelho(): void
    {
        Cache::flush();
        $garcom = $this->garcom();
        $dispositivo = $this->servico()->registrar($garcom, $this->inscricao());
        $this->enviador->resultado = ResultadoEnvioPush::falha(410, 'Gone');

        $envio = $this->servico()->enviarTeste($garcom, $dispositivo);

        $this->assertSame(PushEnvio::EXPIRADO, $envio->pe_status);
        $this->assertSame(410, $envio->pe_http_status);
        $this->assertFalse($dispositivo->fresh()->dp_ativo);
        $this->assertSame('expirado', $dispositivo->fresh()->dp_motivo_desativacao);
    }

    public function test_falha_transitoria_conta_falhas_e_nao_desativa_de_imediato(): void
    {
        Cache::flush();
        $garcom = $this->garcom();
        $dispositivo = $this->servico()->registrar($garcom, $this->inscricao());
        $this->enviador->resultado = ResultadoEnvioPush::falha(503, 'indisponível');

        $envio = $this->servico()->enviarTeste($garcom, $dispositivo);

        $this->assertSame(PushEnvio::FALHOU, $envio->pe_status);
        $this->assertTrue($dispositivo->fresh()->dp_ativo);
        $this->assertSame(1, $dispositivo->fresh()->dp_falhas_consecutivas);
    }

    public function test_desativa_depois_de_falhas_consecutivas_demais(): void
    {
        config(['pizzaria.push.max_falhas_consecutivas' => 2]);
        $garcom = $this->garcom();
        $dispositivo = $this->servico()->registrar($garcom, $this->inscricao());
        $envio = PushEnvio::factory()->create(['pe_dispositivo_id' => $dispositivo->id]);

        foreach (range(1, 3) as $_) {
            $this->servico()->aplicarResultado($envio, $dispositivo->fresh(), ResultadoEnvioPush::falha(500, 'erro'));
        }

        $this->assertFalse($dispositivo->fresh()->dp_ativo);
        $this->assertSame('falhas', $dispositivo->fresh()->dp_motivo_desativacao);
    }

    public function test_classificacao_do_resultado_do_envio(): void
    {
        $this->assertTrue(ResultadoEnvioPush::falha(null, 'timeout')->reenviavel());
        $this->assertTrue(ResultadoEnvioPush::falha(429, null)->reenviavel());
        $this->assertTrue(ResultadoEnvioPush::falha(502, null)->reenviavel());
        $this->assertFalse(ResultadoEnvioPush::falha(400, null)->reenviavel());
        $this->assertFalse(ResultadoEnvioPush::falha(413, null)->reenviavel());
        $this->assertFalse(ResultadoEnvioPush::falha(410, null)->reenviavel());
        $this->assertTrue(ResultadoEnvioPush::falha(404, null)->inscricaoExpirada());
        $this->assertFalse(ResultadoEnvioPush::enviado()->reenviavel());
    }

    public function test_pagina_registra_testa_pausa_e_desativa(): void
    {
        Cache::flush();
        $garcom = $this->garcom();
        $this->actingAs($garcom);

        $pagina = Livewire::test(NotificacoesCelular::class)
            ->assertSet('servidorAtivo', false)
            ->call('registrar', $this->inscricao(), false)
            ->assertSet('servidorAtivo', true);

        $pagina->call('testar')->assertNotified('Teste enviado');
        $this->assertCount(1, $this->enviador->enviados);

        $pagina->call('pausar', 4)->assertSet('pausadoAte', now()->addHours(4)->format('H:i'));
        $pagina->call('retomar')->assertSet('pausadoAte', null);
        $pagina->call('desativar')->assertSet('servidorAtivo', false);

        $this->assertFalse(DispositivoPush::query()->first()->dp_ativo);
    }

    public function test_pagina_recusa_endpoint_invalido_sem_gravar(): void
    {
        $this->actingAs($this->garcom());

        Livewire::test(NotificacoesCelular::class)
            ->call('registrar', $this->inscricao('https://evil.example.com/x'), false)
            ->assertSet('servidorAtivo', false);

        $this->assertDatabaseCount('dispositivos_push', 0);
    }

    public function test_preferencias_do_usuario_sao_gravadas_por_evento(): void
    {
        $garcom = $this->garcom();
        $this->actingAs($garcom);

        Livewire::test(NotificacoesCelular::class)
            ->assertSet('preferencias.ABRIR_MESA', true)
            ->set('preferencias.ABRIR_MESA', false);

        $salvas = $garcom->fresh()->user_push_preferencias;
        $this->assertFalse($salvas['ABRIR_MESA']);
        $this->assertTrue($salvas['CHAMOU_GARCOM']);
        $this->assertArrayNotHasKey('TESTE', $salvas);
    }

    public function test_pagina_so_para_quem_opera_o_salao_e_com_a_chave_ligada(): void
    {
        $this->actingAs($this->garcom());
        $this->assertTrue(NotificacoesCelular::canAccess());

        config(['pizzaria.push.ativo' => false]);
        $this->assertFalse(NotificacoesCelular::canAccess());

        config(['pizzaria.push.ativo' => true]);
        $this->actingAs($this->cozinha());
        $this->assertFalse(NotificacoesCelular::canAccess());
    }

    public function test_sincronizador_registra_em_silencio_e_ignora_endpoint_ruim(): void
    {
        $garcom = $this->garcom();
        $this->actingAs($garcom);

        Livewire::test(PushSincronizador::class)
            ->call('sincronizar', $this->inscricao(), true)
            ->call('sincronizar', $this->inscricao('https://evil.example.com/x'), true)
            ->assertHasNoErrors();

        $this->assertDatabaseCount('dispositivos_push', 1);
        $this->assertSame($garcom->id, DispositivoPush::query()->first()->dp_user_id);
    }

    public function test_manifest_do_garcom(): void
    {
        $resposta = $this->get('/garcom/manifest.webmanifest')->assertOk();

        $this->assertStringContainsString('application/manifest+json', $resposta->headers->get('Content-Type'));
        $json = $resposta->json();
        $this->assertSame('/garcom/', $json['scope']);
        $this->assertSame('/garcom/', $json['id']);
        $this->assertSame('/garcom', $json['start_url']);
        $this->assertSame('standalone', $json['display']);
        $this->assertNotEmpty($json['icons']);
    }

    public function test_service_worker_com_escopo_e_sem_cache(): void
    {
        $resposta = $this->get('/garcom/sw.js')->assertOk();

        $this->assertSame('/garcom/', $resposta->headers->get('Service-Worker-Allowed'));
        $this->assertStringContainsString('application/javascript', $resposta->headers->get('Content-Type'));
        $this->assertStringContainsString('no-cache', $resposta->headers->get('Cache-Control'));
        $this->assertStringContainsString("addEventListener('push'", $resposta->getContent());
        $this->assertDirectoryDoesNotExist(public_path('garcom'));
    }

    public function test_com_a_chave_desligada_nada_do_push_aparece(): void
    {
        config(['pizzaria.push.ativo' => false]);

        $this->get('/garcom/manifest.webmanifest')->assertNotFound();
        $this->get('/garcom/sw.js')->assertNotFound();

        $this->get('/garcom/login')
            ->assertOk()
            ->assertDontSee('manifest.webmanifest', false)
            ->assertDontSee('garcom-push', false);

        $this->actingAs($this->garcom());
        $this->get('/garcom')
            ->assertOk()
            ->assertDontSee('garcom-push', false)
            ->assertDontSee('GarcomPushConfig', false)
            ->assertDontSee('Receba os chamados', false);
    }

    public function test_com_a_chave_ligada_o_painel_carrega_manifest_script_e_convite(): void
    {
        $this->actingAs($this->garcom());

        $this->get('/garcom')
            ->assertOk()
            ->assertSee('manifest.webmanifest', false)
            ->assertSee('GarcomPushConfig', false)
            ->assertSee('Receba os chamados mesmo com o celular no bolso', false);
    }

    public function test_quem_nao_opera_o_salao_nao_recebe_o_script_de_push(): void
    {
        $this->actingAs($this->cozinha());

        $this->get('/garcom/checklists')
            ->assertDontSee('GarcomPushConfig', false);
    }

    public function test_sessao_expirada_volta_para_a_url_da_notificacao_depois_do_pin(): void
    {
        $this->get('/garcom/mesa/5')
            ->assertRedirect()
            ->assertSessionHas('url.intended', url('/garcom/mesa/5'));

        $garcom = User::factory()->garcom()->comPin('1234')->create(['name_first' => 'Ana']);

        Livewire::withQueryParams([])
            ->test(LoginGarcom::class)
            ->call('escolherUsuario', $garcom->id)
            ->set('pin', '1234')
            ->call('entrarComPin')
            ->assertRedirect(url('/garcom/mesa/5'));
    }

    public function test_mapa_de_mesas_continua_abrindo_sem_inscricao(): void
    {
        $this->actingAs($this->garcom());

        Livewire::test(MapaMesas::class)->assertOk();
    }
}
