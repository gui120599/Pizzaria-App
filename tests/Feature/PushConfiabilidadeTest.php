<?php

namespace Tests\Feature;

use App\Console\Commands\LembrarPendentesPush;
use App\Enums\DestinoAvisoMesaEnum;
use App\Enums\EventoPushGarcomEnum;
use App\Enums\PedidoOrigemEnum;
use App\Enums\Ponto\TipoMarcacao;
use App\Enums\ProdutoTipoEnum;
use App\Enums\TipoChamadoMesaEnum;
use App\Filament\Garcom\Pages\AtenderMesa;
use App\Filament\Pages\ConfiguracaoPedidos;
use App\Livewire\PushSincronizador;
use App\Models\Categoria;
use App\Models\Colaborador;
use App\Models\DispositivoPush;
use App\Models\Empresa;
use App\Models\Mesa;
use App\Models\MesaChamado;
use App\Models\Pedido;
use App\Models\PontoMarcacao;
use App\Models\Produto;
use App\Models\PushEnvio;
use App\Models\SessaoMesa;
use App\Models\User;
use App\Services\ItemSolicitado;
use App\Services\MesaCliente\AprovacaoPedidoMesaService;
use App\Services\MesaCliente\MesaChamadoService;
use App\Services\MesaCliente\ParticipanteMesaService;
use App\Services\MesaCliente\PedidoMesaClienteService;
use App\Services\Push\AvisoResolvidoService;
use App\Services\Push\EnviadorPush;
use App\Services\SessaoMesaService;
use App\Support\Push\PendenciaPush;
use App\Support\Push\ResultadoEnvioPush;
use Database\Seeders\PermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\FakeEnviadorPush;
use Tests\TestCase;

/** Cooldown, reenvio, lembretes (D7), limpeza e "já atendido" (Fase 3 do plano). */
class PushConfiabilidadeTest extends TestCase
{
    use RefreshDatabase;

    private FakeEnviadorPush $enviador;

    protected function setUp(): void
    {
        parent::setUp();

        config(['pizzaria.push.ativo' => true, 'pizzaria.push.envio' => 'sync']);
        Filament::setCurrentPanel(Filament::getPanel('garcom'));

        $this->enviador = new FakeEnviadorPush;
        $this->app->instance(EnviadorPush::class, $this->enviador);
    }

    private function garcom(string $nome, ?TipoMarcacao $ponto = null): User
    {
        $usuario = User::factory()->garcom()->create(['name_first' => $nome]);
        DispositivoPush::factory()->create(['dp_user_id' => $usuario->id]);

        if ($ponto !== null) {
            $colaborador = Colaborador::factory()->create(['user_id' => $usuario->id]);
            PontoMarcacao::factory()->em(now()->subHour()->toDateTimeString())->create([
                'colaborador_id' => $colaborador->id,
                'tipo_sugerido' => $ponto,
            ]);
        }

        return $usuario;
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function empresa(array $atributos = []): Empresa
    {
        Empresa::query()->forceDelete();

        return Empresa::create(['empresa_razao_social' => 'Pizzaria Teste', ...$atributos]);
    }

    private function mesaAberta(User $dono): SessaoMesa
    {
        return app(SessaoMesaService::class)->abrir(Mesa::factory()->create(['mesa_nome' => 'Mesa 05'])->id, $dono->id, pessoas: 2);
    }

    private function chamar(SessaoMesa $sessao, TipoChamadoMesaEnum $tipo = TipoChamadoMesaEnum::CHAMAR_GARCOM): MesaChamado
    {
        $mesa = $sessao->mesa->fresh();
        $participante = app(ParticipanteMesaService::class)->entrar($mesa, 'Ana', '64999991234', $sessao->sessao_mesa_pin)['participante'];

        return app(MesaChamadoService::class)->abrir($mesa, $tipo, $participante);
    }

    private function pedidoDoQr(User $dono): Pedido
    {
        $sessao = $this->mesaAberta($dono);
        $mesa = $sessao->mesa->fresh();
        $participante = app(ParticipanteMesaService::class)->entrar($mesa, 'Ana', '64999991234', $sessao->sessao_mesa_pin)['participante'];
        $produto = Produto::create([
            'produto_descricao' => 'Refrigerante',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Bebidas', 'categoria_cardapio' => true])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 8.00,
            'produto_cardapio' => true,
        ]);

        return app(PedidoMesaClienteService::class)->enviar($participante, [new ItemSolicitado($produto->id)], (string) Str::uuid());
    }

    private function lembrar(): int
    {
        Artisan::call('push:lembrar-pendentes');

        return PushEnvio::query()->where('pe_chave', 'like', '%:lembrete:%')->distinct()->count('pe_chave');
    }

    // ----- cooldown -----

    public function test_chamado_logo_apos_outro_da_mesma_mesa_fica_contido_pelo_cooldown(): void
    {
        $dono = $this->garcom('Ana');
        $sessao = $this->mesaAberta($dono);

        $primeiro = $this->chamar($sessao);
        app(MesaChamadoService::class)->atender($primeiro, $dono);
        $this->chamar($sessao);

        $this->assertDatabaseCount('push_envios', 1);

        $this->travel(61)->seconds();
        MesaChamado::pendentes()->delete();
        $this->chamar($sessao);

        $this->assertDatabaseCount('push_envios', 2);
    }

    public function test_cooldown_e_por_mesa_e_por_tipo(): void
    {
        $dono = $this->garcom('Ana');
        $sessao = $this->mesaAberta($dono);

        $this->chamar($sessao);
        $this->chamar($sessao, TipoChamadoMesaEnum::PEDIR_CONTA);
        $this->chamar($this->mesaAberta($dono));

        $this->assertDatabaseCount('push_envios', 3);
    }

    // ----- reenvio de falhas -----

    private function falhar(int $status, ?string $erro = 'erro'): PushEnvio
    {
        $dono = $this->garcom('Ana');
        $this->enviador->resultado = ResultadoEnvioPush::falha($status, $erro);
        $this->chamar($this->mesaAberta($dono));
        $this->enviador->enviados = [];

        return PushEnvio::query()->sole();
    }

    public function test_falha_transitoria_e_reenviada_pelo_comando(): void
    {
        $envio = $this->falhar(503);
        $this->assertSame(PushEnvio::FALHOU, $envio->pe_status);

        $this->enviador->resultado = ResultadoEnvioPush::enviado();
        Artisan::call('push:reenviar-falhas');

        $this->assertSame(PushEnvio::ENVIADO, $envio->fresh()->pe_status);
        $this->assertSame(2, $envio->fresh()->pe_tentativas);
        $this->assertCount(1, $this->enviador->enviados);
    }

    public function test_reenvio_para_depois_de_tres_tentativas(): void
    {
        $envio = $this->falhar(500);

        foreach (range(1, 5) as $_) {
            Artisan::call('push:reenviar-falhas');
        }

        $this->assertSame(PushEnvio::MAX_TENTATIVAS, $envio->fresh()->pe_tentativas);
        $this->assertSame(PushEnvio::FALHOU, $envio->fresh()->pe_status);
        $this->assertCount(PushEnvio::MAX_TENTATIVAS - 1, $this->enviador->enviados);
    }

    public function test_falha_permanente_nao_e_reenviada(): void
    {
        $envio = $this->falhar(413);

        Artisan::call('push:reenviar-falhas');

        $this->assertSame(1, $envio->fresh()->pe_tentativas);
        $this->assertSame([], $this->enviador->enviados);
    }

    public function test_reenvio_nao_envia_o_que_ja_foi_resolvido(): void
    {
        $envio = $this->falhar(503);
        MesaChamado::pendentes()->update(['mc_status' => 'ATENDIDO']);

        Artisan::call('push:reenviar-falhas');

        $this->assertSame(PushEnvio::DESCARTADO, $envio->fresh()->pe_status);
        $this->assertSame([], $this->enviador->enviados);
    }

    public function test_falha_depois_do_prazo_vira_expirada_sem_reenvio(): void
    {
        $envio = $this->falhar(503);
        $this->travel(EventoPushGarcomEnum::CHAMOU_GARCOM->ttl() + 5)->seconds();

        Artisan::call('push:reenviar-falhas');

        $this->assertSame(PushEnvio::EXPIRADO, $envio->fresh()->pe_status);
        $this->assertSame([], $this->enviador->enviados);
    }

    public function test_envio_pendente_preso_e_retomado(): void
    {
        $dono = $this->garcom('Ana');
        $this->chamar($this->mesaAberta($dono));
        $envio = PushEnvio::query()->sole();
        $envio->forceFill(['pe_status' => PushEnvio::PENDENTE, 'pe_tentativas' => 0])->save();
        $envio->newQuery()->whereKey($envio->id)->update(['created_at' => now()->subMinutes(2)]);
        $this->enviador->enviados = [];

        Artisan::call('push:reenviar-falhas');

        $this->assertSame(PushEnvio::ENVIADO, $envio->fresh()->pe_status);
    }

    // ----- lembretes (D7) -----

    public function test_lembrete_a_cada_intervalo_enquanto_pendente_com_mesma_tag(): void
    {
        $this->empresa(['empresa_push_repetir_intervalo_min' => 2]);
        $dono = $this->garcom('Ana');
        $this->chamar($this->mesaAberta($dono));
        $tag = $this->enviador->enviados[0]['payload']['tag'];
        $this->enviador->enviados = [];

        $this->travel(1)->minutes();
        $this->assertSame(0, $this->lembrar());

        $this->travel(1)->minutes();
        $this->assertSame(1, $this->lembrar());
        $lembrete = $this->enviador->enviados[0];
        $this->assertSame($tag, $lembrete['payload']['tag']);
        $this->assertSame($tag, $lembrete['topic']);
        $this->assertStringContainsString('chamando há 2 min', $lembrete['payload']['titulo']);
        $this->assertStringContainsString(':lembrete:1', $lembrete['payload']['chave']);

        $this->travel(2)->minutes();
        $this->assertSame(2, $this->lembrar());
        $this->assertStringContainsString('chamando há 4 min', $this->enviador->enviados[1]['payload']['titulo']);
    }

    public function test_comando_duas_vezes_no_mesmo_minuto_nao_duplica(): void
    {
        $this->empresa(['empresa_push_repetir_intervalo_min' => 1]);
        $this->chamar($this->mesaAberta($this->garcom('Ana')));
        $this->travel(1)->minutes();

        $this->lembrar();
        $this->lembrar();

        $this->assertSame(1, PushEnvio::query()->where('pe_chave', 'like', '%:lembrete:%')->count());
    }

    public function test_lembrete_para_no_limite_de_repeticoes(): void
    {
        $this->empresa(['empresa_push_repetir_intervalo_min' => 1, 'empresa_push_repetir_max' => 2]);
        $this->chamar($this->mesaAberta($this->garcom('Ana')));

        foreach (range(1, 5) as $_) {
            $this->travel(1)->minutes();
            $this->lembrar();
        }

        $this->assertSame(2, PushEnvio::query()->where('pe_chave', 'like', '%:lembrete:%')->distinct()->count('pe_chave'));
    }

    public function test_sem_repetir_ate_atender_nao_ha_lembrete(): void
    {
        $this->empresa(['empresa_push_repetir_ate_atender' => false, 'empresa_push_repetir_intervalo_min' => 1]);
        $this->chamar($this->mesaAberta($this->garcom('Ana')));
        $this->travel(5)->minutes();

        $this->assertSame(0, $this->lembrar());
    }

    public function test_lembrete_para_quando_o_chamado_e_atendido(): void
    {
        $this->empresa(['empresa_push_repetir_intervalo_min' => 1]);
        $dono = $this->garcom('Ana');
        $chamado = $this->chamar($this->mesaAberta($dono));
        app(MesaChamadoService::class)->atender($chamado, $dono);
        $this->travel(3)->minutes();

        $this->assertSame(0, $this->lembrar());
    }

    public function test_lembrete_para_quando_a_conta_fecha(): void
    {
        $this->empresa(['empresa_push_repetir_intervalo_min' => 1]);
        $dono = $this->garcom('Ana');
        $sessao = $this->mesaAberta($dono);
        $this->chamar($sessao);
        app(MesaChamadoService::class)->encerrarDaSessao($sessao);
        $this->travel(3)->minutes();

        $this->assertSame(0, $this->lembrar());
    }

    public function test_lembrete_para_quando_a_mesa_e_aberta(): void
    {
        $this->empresa(['empresa_push_repetir_intervalo_min' => 1]);
        $emTurno = $this->garcom('Bia', TipoMarcacao::Entrada);
        $mesa = Mesa::factory()->create();
        app(MesaChamadoService::class)->abrir($mesa, TipoChamadoMesaEnum::ABRIR_MESA);
        $this->travel(1)->minutes();
        $this->assertSame(1, $this->lembrar());
        $this->assertSame($emTurno->id, PushEnvio::query()->first()->pe_user_id);

        app(MesaChamadoService::class)->atenderAberturaDaMesa($mesa->id, $emTurno->id);
        $this->travel(2)->minutes();

        $this->assertSame(1, $this->lembrar());
    }

    public function test_lembrete_de_pedido_para_aprovar_para_ao_aprovar(): void
    {
        $this->empresa(['empresa_push_repetir_intervalo_min' => 1]);
        $dono = $this->garcom('Ana');
        $pedido = $this->pedidoDoQr($dono);
        $this->travel(1)->minutes();
        $this->assertSame(1, $this->lembrar());
        $this->assertStringContainsString('aguarda aprovação', $this->enviador->enviados[1]['payload']['titulo']);

        app(AprovacaoPedidoMesaService::class)->aprovar($pedido, $dono);
        $this->travel(2)->minutes();

        $this->assertSame(1, $this->lembrar());
    }

    public function test_lembrete_recalcula_os_destinatarios_a_cada_rodada(): void
    {
        $this->empresa(['empresa_push_destino_mesa' => DestinoAvisoMesaEnum::TODOS_EM_TURNO->value, 'empresa_push_repetir_intervalo_min' => 1]);
        $dono = $this->garcom('Ana');
        $this->chamar($this->mesaAberta($dono));
        $this->assertSame([$dono->id], PushEnvio::query()->pluck('pe_user_id')->all());

        $chegou = $this->garcom('Bia', TipoMarcacao::Entrada);
        $this->travel(1)->minutes();
        $this->lembrar();

        $this->assertEqualsCanonicalizing([$dono->id, $chegou->id], PushEnvio::query()->where('pe_chave', 'like', '%:lembrete:1')->pluck('pe_user_id')->all());
    }

    public function test_lembrete_nao_e_contido_pelo_cooldown(): void
    {
        $this->empresa(['empresa_push_repetir_intervalo_min' => 1]);
        $this->chamar($this->mesaAberta($this->garcom('Ana')));
        $this->travel(1)->minutes();

        $this->assertSame(1, $this->lembrar());
    }

    public function test_sem_destinatarios_nao_grava_lembrete_nem_da_erro(): void
    {
        $this->empresa(['empresa_push_repetir_intervalo_min' => 1]);
        app(MesaChamadoService::class)->abrir(Mesa::factory()->create(), TipoChamadoMesaEnum::ABRIR_MESA);
        $this->garcom('Ana');
        $this->travel(2)->minutes();

        $this->assertSame(0, $this->lembrar());
        $this->assertDatabaseCount('push_envios', 0);
    }

    public function test_comando_de_lembrete_grava_o_batimento_do_agendador(): void
    {
        $this->assertFalse(LembrarPendentesPush::agendadorAtivo());

        Artisan::call('push:lembrar-pendentes');
        $this->assertTrue(LembrarPendentesPush::agendadorAtivo());

        $this->travel(6)->minutes();
        $this->assertFalse(LembrarPendentesPush::agendadorAtivo());
    }

    public function test_admin_ve_alerta_quando_o_agendador_esta_parado(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->empresa();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        Livewire::test(ConfiguracaoPedidos::class)->assertSee('não rodou nos últimos 5 minutos');

        Cache::put(LembrarPendentesPush::CHAVE_BATIMENTO, now()->timestamp);
        Livewire::test(ConfiguracaoPedidos::class)->assertDontSee('não rodou nos últimos 5 minutos');
    }

    public function test_comandos_estao_no_agendador(): void
    {
        Artisan::call('schedule:list');
        $saida = Artisan::output();

        foreach (['push:lembrar-pendentes', 'push:reenviar-falhas', 'push:limpar'] as $comando) {
            $this->assertStringContainsString($comando, $saida);
        }
    }

    // ----- limpeza -----

    public function test_limpar_apaga_o_antigo_e_encerra_pausas_vencidas(): void
    {
        $usuario = $this->garcom('Ana');
        $aparelho = DispositivoPush::query()->where('dp_user_id', $usuario->id)->sole();
        $antigo = PushEnvio::factory()->create(['pe_dispositivo_id' => $aparelho->id]);
        $antigo->newQuery()->whereKey($antigo->id)->update(['created_at' => now()->subDays(31)]);
        $recente = PushEnvio::factory()->create(['pe_dispositivo_id' => $aparelho->id]);
        $inativoAntigo = DispositivoPush::factory()->inativo()->create(['dp_user_id' => $usuario->id, 'dp_desativado_em' => now()->subDays(31)]);
        $inativoRecente = DispositivoPush::factory()->inativo()->create(['dp_user_id' => $usuario->id, 'dp_desativado_em' => now()->subDays(2)]);
        $aparelho->update(['dp_pausado_ate' => now()->subMinute()]);

        Artisan::call('push:limpar');

        $this->assertModelMissing($antigo);
        $this->assertModelExists($recente);
        $this->assertModelMissing($inativoAntigo);
        $this->assertModelExists($inativoRecente);
        $this->assertNull($aparelho->fresh()->dp_pausado_ate);
    }

    // ----- fechar resolvidas / já atendido -----

    public function test_chaves_abertas_listam_so_o_que_ainda_pede_o_garcom(): void
    {
        $dono = $this->garcom('Ana');
        $chamado = $this->chamar($this->mesaAberta($dono));
        $atendido = $this->chamar($this->mesaAberta($dono));
        app(MesaChamadoService::class)->atender($atendido, $dono);
        $pedido = $this->pedidoDoQr($dono);

        $chaves = PendenciaPush::chavesAbertas();

        $this->assertContains("chamado:{$chamado->id}", $chaves);
        $this->assertNotContains("chamado:{$atendido->id}", $chaves);
        $this->assertContains("aprovar:{$pedido->id}", $chaves);
    }

    public function test_pedido_pronto_aparece_nas_chaves_abertas_e_some_ao_ser_servido(): void
    {
        $dono = $this->garcom('Ana');
        $rodada = Pedido::create([
            'pedido_status' => 'ABERTO',
            'pedido_origem' => PedidoOrigemEnum::MESA,
            'pedido_sessao_mesa_id' => $this->mesaAberta($dono)->id,
            'pedido_usuario_garcom_id' => $dono->id,
            'pedido_datahora_abertura' => now(),
        ]);
        $rodada->update(['pedido_status' => 'PRONTO']);
        $this->assertContains("pronto:{$rodada->id}", PendenciaPush::chavesAbertas());

        $rodada->update(['pedido_status' => 'ENTREGUE']);
        $this->assertNotContains("pronto:{$rodada->id}", PendenciaPush::chavesAbertas());
    }

    public function test_sincronizador_publica_as_pendencias_para_o_navegador(): void
    {
        $dono = $this->garcom('Ana');
        $chamado = $this->chamar($this->mesaAberta($dono));
        $this->actingAs($dono);

        Livewire::test(PushSincronizador::class)
            ->call('pendencias')
            ->assertDispatched('garcom-push-pendencias', chaves: ["chamado:{$chamado->id}"]);
    }

    public function test_url_da_notificacao_leva_a_chave_do_assunto_sem_o_sufixo_de_lembrete(): void
    {
        $this->empresa(['empresa_push_repetir_intervalo_min' => 1]);
        $chamado = $this->chamar($this->mesaAberta($this->garcom('Ana')));
        $this->travel(1)->minutes();
        $this->lembrar();

        foreach ($this->enviador->enviados as $enviado) {
            $this->assertStringEndsWith('?aviso='.rawurlencode("chamado:{$chamado->id}"), $enviado['payload']['url']);
        }
    }

    public function test_mensagem_de_chamado_ja_atendido_diz_quem_e_quando(): void
    {
        $dono = $this->garcom('Ana');
        $outro = $this->garcom('Bia');
        $chamado = $this->chamar($this->mesaAberta($dono));
        app(MesaChamadoService::class)->atender($chamado, $outro);
        $hora = $chamado->fresh()->mc_atendido_em->format('H:i');

        $this->assertSame(
            "Chamado já atendido por Bia às {$hora}.",
            app(AvisoResolvidoService::class)->mensagem("chamado:{$chamado->id}"),
        );
    }

    public function test_mensagem_de_pedido_aprovado_e_de_conta_encerrada(): void
    {
        $dono = $this->garcom('Ana');
        $pedido = $this->pedidoDoQr($dono);
        app(AprovacaoPedidoMesaService::class)->aprovar($pedido, $dono);
        $this->assertStringStartsWith('Pedido já aprovado por Ana', app(AvisoResolvidoService::class)->mensagem("aprovar:{$pedido->id}"));

        $sessao = $this->mesaAberta($dono);
        $cancelado = $this->chamar($sessao);
        app(MesaChamadoService::class)->encerrarDaSessao($sessao);
        $this->assertStringContainsString('conta da mesa foi fechada', app(AvisoResolvidoService::class)->mensagem("chamado:{$cancelado->id}"));
    }

    public function test_sem_mensagem_quando_ainda_pendente_ou_chave_estranha(): void
    {
        $chamado = $this->chamar($this->mesaAberta($this->garcom('Ana')));
        $servico = app(AvisoResolvidoService::class);

        $this->assertNull($servico->mensagem("chamado:{$chamado->id}"));
        $this->assertNull($servico->mensagem(null));
        $this->assertNull($servico->mensagem('<script>alert(1)</script>'));
        $this->assertNull($servico->mensagem('chamado:abc'));
    }

    public function test_pagina_da_mesa_avisa_que_o_chamado_ja_foi_atendido(): void
    {
        $dono = $this->garcom('Ana');
        $sessao = $this->mesaAberta($dono);
        $chamado = $this->chamar($sessao);
        app(MesaChamadoService::class)->atender($chamado, $dono);
        $this->actingAs($dono);
        $hora = $chamado->fresh()->mc_atendido_em->format('H:i');

        Livewire::withQueryParams(['aviso' => "chamado:{$chamado->id}"])
            ->test(AtenderMesa::class, ['sessao' => $sessao->id])
            ->assertNotified("Chamado já atendido por Ana às {$hora}.");
    }

    public function test_pagina_da_mesa_nao_avisa_com_o_push_desligado(): void
    {
        config(['pizzaria.push.ativo' => false]);
        $dono = $this->garcom('Ana');
        $sessao = $this->mesaAberta($dono);
        $chamado = $this->chamar($sessao);
        app(MesaChamadoService::class)->atender($chamado, $dono);
        $this->actingAs($dono);

        Livewire::withQueryParams(['aviso' => "chamado:{$chamado->id}"])
            ->test(AtenderMesa::class, ['sessao' => $sessao->id])
            ->assertNotNotified();
    }
}
