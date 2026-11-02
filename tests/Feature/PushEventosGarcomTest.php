<?php

namespace Tests\Feature;

use App\Enums\DestinoAvisoMesaEnum;
use App\Enums\EventoPushGarcomEnum;
use App\Enums\PedidoOrigemEnum;
use App\Enums\Ponto\TipoMarcacao;
use App\Enums\ProdutoTipoEnum;
use App\Enums\TipoChamadoMesaEnum;
use App\Filament\Pages\ConfiguracaoPedidos;
use App\Jobs\EnviarPushGarcomJob;
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
use App\Services\Push\DispositivoPushService;
use App\Services\Push\EnviadorPush;
use App\Services\Push\NotificadorGarcom;
use App\Services\SessaoMesaService;
use App\Support\Push\EventoGarcom;
use App\Support\Push\ResultadoEnvioPush;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\FakeEnviadorPush;
use Tests\TestCase;

/** Gatilhos, destinatários e envio do push do salão (Fase 2 do plano). */
class PushEventosGarcomTest extends TestCase
{
    use RefreshDatabase;

    private FakeEnviadorPush $enviador;

    protected function setUp(): void
    {
        parent::setUp();

        config(['pizzaria.push.ativo' => true, 'pizzaria.push.envio' => 'sync']);

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

    private function destino(DestinoAvisoMesaEnum $destino): void
    {
        Empresa::query()->forceDelete();
        Empresa::create(['empresa_razao_social' => 'Pizzaria Teste', 'empresa_push_destino_mesa' => $destino->value]);
    }

    private function mesaAberta(User $dono): SessaoMesa
    {
        $mesa = Mesa::factory()->create(['mesa_nome' => 'Mesa 05']);

        return app(SessaoMesaService::class)->abrir($mesa->id, $dono->id, pessoas: 2);
    }

    private function chamar(SessaoMesa $sessao, TipoChamadoMesaEnum $tipo = TipoChamadoMesaEnum::CHAMAR_GARCOM): MesaChamado
    {
        $mesa = $sessao->mesa->fresh();
        $participante = app(ParticipanteMesaService::class)->entrar($mesa, 'Ana', '64999991234', $sessao->sessao_mesa_pin)['participante'];

        return app(MesaChamadoService::class)->abrir($mesa, $tipo, $participante);
    }

    /** @return list<int> ids dos usuários que receberam push */
    private function destinatarios(): array
    {
        return PushEnvio::query()->pluck('pe_user_id')->unique()->sort()->values()->all();
    }

    private function rodada(SessaoMesa $sessao, ?User $garcom, string $status = 'ABERTO'): Pedido
    {
        return Pedido::create([
            'pedido_status' => $status,
            'pedido_origem' => PedidoOrigemEnum::MESA,
            'pedido_sessao_mesa_id' => $sessao->id,
            'pedido_usuario_garcom_id' => $garcom?->id,
            'pedido_datahora_abertura' => now(),
        ]);
    }

    private function ficarPronto(Pedido $pedido): void
    {
        $pedido->pedido_status = 'PRONTO';
        $pedido->save();
    }

    public function test_chamado_no_modo_padrao_avisa_so_o_dono_da_mesa(): void
    {
        $dono = $this->garcom('Ana');
        $this->garcom('Bia', TipoMarcacao::Entrada);

        $this->chamar($this->mesaAberta($dono));

        $this->assertSame([$dono->id], $this->destinatarios());
        $this->assertCount(1, $this->enviador->enviados);
        $this->assertStringContainsString('Mesa 05', $this->enviador->enviados[0]['payload']['titulo']);
        $this->assertSame(PushEnvio::ENVIADO, PushEnvio::query()->sole()->pe_status);
    }

    public function test_modo_todos_avisa_o_dono_mesmo_sem_ponto_e_quem_esta_com_ponto_aberto(): void
    {
        $this->destino(DestinoAvisoMesaEnum::TODOS_EM_TURNO);
        $dono = $this->garcom('Ana');
        $emTurno = $this->garcom('Bia', TipoMarcacao::Entrada);
        $voltou = $this->garcom('Cris', TipoMarcacao::RetornoIntervalo);
        $this->garcom('Duda', TipoMarcacao::Saida);
        $this->garcom('Edu', TipoMarcacao::SaidaIntervalo);
        $this->garcom('Fabi');

        $this->chamar($this->mesaAberta($dono));

        $this->assertEqualsCanonicalizing([$dono->id, $emTurno->id, $voltou->id], $this->destinatarios());
    }

    public function test_transferencia_de_mesa_leva_o_aviso_ao_novo_dono(): void
    {
        $antigo = $this->garcom('Ana');
        $novo = $this->garcom('Bia');
        $sessao = $this->mesaAberta($antigo);
        $sessao->update(['sessao_mesa_usuario_id' => $novo->id]);

        $this->chamar($sessao->fresh());

        $this->assertSame([$novo->id], $this->destinatarios());
    }

    public function test_pedir_a_conta_tem_texto_proprio(): void
    {
        $dono = $this->garcom('Ana');

        $this->chamar($this->mesaAberta($dono), TipoChamadoMesaEnum::PEDIR_CONTA);

        $this->assertSame(EventoPushGarcomEnum::PEDIU_CONTA, PushEnvio::query()->sole()->pe_evento);
        $this->assertStringContainsString('pediu a conta', $this->enviador->enviados[0]['payload']['titulo']);
    }

    public function test_chamado_repetido_nao_notifica_de_novo(): void
    {
        $sessao = $this->mesaAberta($this->garcom('Ana'));

        $this->chamar($sessao);
        $this->chamar($sessao);

        $this->assertDatabaseCount('push_envios', 1);
        $this->assertCount(1, $this->enviador->enviados);
    }

    public function test_abrir_mesa_vai_so_para_quem_tem_ponto_aberto_em_qualquer_modo(): void
    {
        foreach ([DestinoAvisoMesaEnum::GARCOM_DA_MESA, DestinoAvisoMesaEnum::TODOS_EM_TURNO] as $destino) {
            PushEnvio::query()->delete();
            User::query()->forceDelete();
            $this->destino($destino);
            $emTurno = $this->garcom('Bia'.$destino->value, TipoMarcacao::Entrada);
            $this->garcom('Ana'.$destino->value);

            app(MesaChamadoService::class)->abrir(Mesa::factory()->create(), TipoChamadoMesaEnum::ABRIR_MESA);

            $this->assertSame([$emTurno->id], $this->destinatarios(), $destino->value);
        }
    }

    public function test_ninguem_com_ponto_aberto_nao_envia_e_nao_da_erro(): void
    {
        $this->garcom('Ana');

        $chamado = app(MesaChamadoService::class)->abrir(Mesa::factory()->create(), TipoChamadoMesaEnum::ABRIR_MESA);

        $this->assertSame(TipoChamadoMesaEnum::ABRIR_MESA, $chamado->mc_tipo);
        $this->assertDatabaseCount('push_envios', 0);
        $this->assertSame([], $this->enviador->enviados);
    }

    public function test_preferencia_desligada_nao_envia(): void
    {
        $dono = $this->garcom('Ana');
        $dono->forceFill(['user_push_preferencias' => ['CHAMOU_GARCOM' => false]])->save();

        $this->chamar($this->mesaAberta($dono));

        $this->assertDatabaseCount('push_envios', 0);
    }

    public function test_aparelho_pausado_ou_inativo_nao_recebe(): void
    {
        $dono = $this->garcom('Ana');
        DispositivoPush::query()->where('dp_user_id', $dono->id)->update(['dp_pausado_ate' => now()->addHour()]);
        DispositivoPush::factory()->inativo()->create(['dp_user_id' => $dono->id]);

        $this->chamar($this->mesaAberta($dono));

        $this->assertDatabaseCount('push_envios', 0);
    }

    public function test_usuario_que_perdeu_o_papel_nao_recebe_e_tem_o_aparelho_desativado(): void
    {
        $dono = $this->garcom('Ana');
        $sessao = $this->mesaAberta($dono);
        $dono->syncRoles([]);

        $this->chamar($sessao);

        $this->assertDatabaseCount('push_envios', 0);
    }

    public function test_job_desativa_o_aparelho_de_quem_perdeu_o_papel_depois_do_enfileiramento(): void
    {
        Bus::fake([EnviarPushGarcomJob::class]);
        $dono = $this->garcom('Ana');
        $this->chamar($this->mesaAberta($dono));
        $dono->syncRoles([]);

        (new EnviarPushGarcomJob(PushEnvio::query()->pluck('id')->all()))->handle($this->enviador, app(DispositivoPushService::class));

        $envio = PushEnvio::query()->sole();
        $this->assertSame(PushEnvio::DESCARTADO, $envio->pe_status);
        $this->assertSame('sem_permissao', $envio->dispositivo->dp_motivo_desativacao);
        $this->assertSame([], $this->enviador->enviados);
    }

    public function test_pedido_do_qr_para_aprovar_avisa_o_dono(): void
    {
        $dono = $this->garcom('Ana');
        $pedido = $this->pedidoDoQr($dono);

        $envio = PushEnvio::query()->sole();
        $this->assertSame("aprovar:{$pedido->id}", $envio->pe_chave);
        $this->assertSame($dono->id, $envio->pe_user_id);
        $this->assertSame(EventoPushGarcomEnum::APROVAR_PEDIDO, $envio->pe_evento);
        $this->assertStringContainsString('pedido para aprovar', $this->enviador->enviados[0]['payload']['titulo']);
    }

    public function test_pedido_do_qr_que_nao_precisa_de_aprovacao_nao_avisa(): void
    {
        config(['pizzaria.mesa_cliente.aprovacao_primeiro_pedido' => 'off']);

        $this->pedidoDoQr($this->garcom('Ana'));

        $this->assertDatabaseMissing('push_envios', ['pe_evento' => EventoPushGarcomEnum::APROVAR_PEDIDO->value]);
    }

    public function test_pedido_aprovado_antes_do_job_e_descartado(): void
    {
        Bus::fake([EnviarPushGarcomJob::class]);
        $dono = $this->garcom('Ana');
        $pedido = $this->pedidoDoQr($dono);
        app(AprovacaoPedidoMesaService::class)->aprovar($pedido, $dono);

        (new EnviarPushGarcomJob(PushEnvio::query()->pluck('id')->all()))->handle($this->enviador, app(DispositivoPushService::class));

        $this->assertSame(PushEnvio::DESCARTADO, PushEnvio::query()->sole()->pe_status);
        $this->assertSame([], $this->enviador->enviados);
    }

    /** Primeiro pedido do QR na mesa: cai na regra de aprovação padrão. */
    private function pedidoDoQr(User $dono): Pedido
    {
        $sessao = $this->mesaAberta($dono);
        $mesa = $sessao->mesa->fresh();
        $participante = app(ParticipanteMesaService::class)->entrar($mesa, 'Ana', '64999991234', $sessao->sessao_mesa_pin)['participante'];
        $refrigerante = Produto::create([
            'produto_descricao' => 'Refrigerante',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Bebidas', 'categoria_cardapio' => true])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 8.00,
            'produto_cardapio' => true,
        ]);

        return app(PedidoMesaClienteService::class)->enviar($participante, [new ItemSolicitado($refrigerante->id)], (string) Str::uuid());
    }

    public function test_pedido_pronto_de_mesa_avisa_o_garcom_da_rodada(): void
    {
        $dono = $this->garcom('Ana');
        $outro = $this->garcom('Bia', TipoMarcacao::Entrada);
        $sessao = $this->mesaAberta($dono);
        $pedido = $this->rodada($sessao, $outro);

        $this->ficarPronto($pedido);

        $this->assertSame([$outro->id], $this->destinatarios());
        $this->assertSame("pronto:{$pedido->id}", PushEnvio::query()->sole()->pe_chave);
        $this->assertStringContainsString("#{$pedido->id}", $this->enviador->enviados[0]['payload']['titulo']);
        $this->assertStringStartsWith("/garcom/mesa/{$sessao->id}?aviso=", $this->enviador->enviados[0]['payload']['url']);
    }

    public function test_pedido_pronto_no_modo_todos_avisa_quem_esta_em_turno(): void
    {
        $this->destino(DestinoAvisoMesaEnum::TODOS_EM_TURNO);
        $dono = $this->garcom('Ana');
        $emTurno = $this->garcom('Bia', TipoMarcacao::Entrada);
        $pedido = $this->rodada($this->mesaAberta($dono), $dono);

        $this->ficarPronto($pedido);

        $this->assertEqualsCanonicalizing([$dono->id, $emTurno->id], $this->destinatarios());
    }

    public function test_retirada_do_garcom_pronta_avisa_com_url_da_retirada(): void
    {
        $garcom = $this->garcom('Ana');
        $pedido = Pedido::create([
            'pedido_status' => 'ABERTO',
            'pedido_origem' => PedidoOrigemEnum::GARCOM,
            'pedido_usuario_garcom_id' => $garcom->id,
            'pedido_datahora_abertura' => now(),
        ]);

        $this->ficarPronto($pedido);

        $this->assertSame([$garcom->id], $this->destinatarios());
        $this->assertStringStartsWith("/garcom/retirada/{$pedido->id}?aviso=", $this->enviador->enviados[0]['payload']['url']);
        $this->assertSame("retirada-{$pedido->id}", $this->enviador->enviados[0]['topic']);
    }

    public function test_delivery_pronto_nao_avisa_o_garcom(): void
    {
        $garcom = $this->garcom('Ana', TipoMarcacao::Entrada);
        $pedido = Pedido::create([
            'pedido_status' => 'ABERTO',
            'pedido_origem' => PedidoOrigemEnum::CARDAPIO,
            'pedido_usuario_garcom_id' => $garcom->id,
            'pedido_datahora_abertura' => now(),
        ]);

        $this->ficarPronto($pedido);

        $this->assertDatabaseCount('push_envios', 0);
    }

    public function test_mudanca_de_status_que_nao_e_pronto_nao_avisa(): void
    {
        $dono = $this->garcom('Ana');
        $pedido = $this->rodada($this->mesaAberta($dono), $dono);

        $pedido->pedido_status = 'PREPARANDO';
        $pedido->save();

        $this->assertDatabaseCount('push_envios', 0);
    }

    public function test_transacao_revertida_nao_envia(): void
    {
        $dono = $this->garcom('Ana');
        $sessao = $this->mesaAberta($dono);

        try {
            DB::transaction(function () use ($sessao): void {
                $this->chamar($sessao);

                throw new \RuntimeException('falha depois do chamado');
            });
        } catch (\RuntimeException) {
        }

        $this->assertDatabaseCount('push_envios', 0);
        $this->assertSame([], $this->enviador->enviados);
    }

    public function test_mesma_ocorrencia_duas_vezes_gera_um_envio_por_aparelho(): void
    {
        Bus::fake([EnviarPushGarcomJob::class]);
        $dono = $this->garcom('Ana');
        $chamado = $this->chamar($this->mesaAberta($dono));
        $evento = EventoGarcom::chamado($chamado);

        $repetido = app(NotificadorGarcom::class)->enfileirar($evento);

        $this->assertSame([], $repetido);
        $this->assertDatabaseCount('push_envios', 1);
    }

    public function test_chamado_atendido_antes_do_job_e_descartado(): void
    {
        Bus::fake([EnviarPushGarcomJob::class]);
        $dono = $this->garcom('Ana');
        $chamado = $this->chamar($this->mesaAberta($dono));
        app(MesaChamadoService::class)->atender($chamado, $dono);

        (new EnviarPushGarcomJob(PushEnvio::query()->pluck('id')->all()))->handle($this->enviador, app(DispositivoPushService::class));

        $this->assertSame(PushEnvio::DESCARTADO, PushEnvio::query()->sole()->pe_status);
        $this->assertSame([], $this->enviador->enviados);
    }

    public function test_pedido_que_ja_foi_servido_antes_do_job_e_descartado(): void
    {
        Bus::fake([EnviarPushGarcomJob::class]);
        $dono = $this->garcom('Ana');
        $pedido = $this->rodada($this->mesaAberta($dono), $dono);
        $this->ficarPronto($pedido);
        $pedido->pedido_status = 'ENTREGUE';
        $pedido->save();

        (new EnviarPushGarcomJob(PushEnvio::query()->pluck('id')->all()))->handle($this->enviador, app(DispositivoPushService::class));

        $this->assertSame(PushEnvio::DESCARTADO, PushEnvio::query()->sole()->pe_status);
        $this->assertSame([], $this->enviador->enviados);
    }

    public function test_envio_vencido_vira_expirado_sem_chamar_o_servico(): void
    {
        Bus::fake([EnviarPushGarcomJob::class]);
        $dono = $this->garcom('Ana');
        $this->chamar($this->mesaAberta($dono));
        PushEnvio::query()->update(['pe_expira_em' => now()->subMinute()]);

        (new EnviarPushGarcomJob(PushEnvio::query()->pluck('id')->all()))->handle($this->enviador, app(DispositivoPushService::class));

        $this->assertSame(PushEnvio::EXPIRADO, PushEnvio::query()->sole()->pe_status);
        $this->assertSame([], $this->enviador->enviados);
    }

    public function test_respostas_do_servico_de_push_atualizam_envio_e_aparelho(): void
    {
        $dono = $this->garcom('Ana');
        $this->enviador->resultado = ResultadoEnvioPush::falha(410, 'Gone');

        $this->chamar($this->mesaAberta($dono));

        $envio = PushEnvio::query()->sole();
        $this->assertSame(410, $envio->pe_http_status);
        $this->assertSame(PushEnvio::EXPIRADO, $envio->pe_status);
        $this->assertFalse($envio->dispositivo->dp_ativo);
    }

    public function test_falha_transitoria_deixa_o_envio_como_falhou(): void
    {
        $dono = $this->garcom('Ana');
        $this->enviador->resultado = ResultadoEnvioPush::falha(503, 'indisponível');

        $this->chamar($this->mesaAberta($dono));

        $this->assertSame(PushEnvio::FALHOU, PushEnvio::query()->sole()->pe_status);
        $this->assertTrue(PushEnvio::query()->sole()->dispositivo->dp_ativo);
    }

    public function test_payload_tem_ttl_topic_e_urgencia_coerentes_e_sem_dados_sensiveis(): void
    {
        $dono = $this->garcom('Ana');

        $this->chamar($this->mesaAberta($dono));

        $enviado = $this->enviador->enviados[0];
        $this->assertSame('high', $enviado['urgencia']);
        $this->assertLessThanOrEqual(EventoPushGarcomEnum::CHAMOU_GARCOM->ttl(), $enviado['ttl']);
        $this->assertGreaterThan(0, $enviado['ttl']);
        $this->assertSame($enviado['payload']['tag'], $enviado['topic']);
        $this->assertLessThanOrEqual(32, strlen($enviado['topic']));
        $this->assertStringStartsWith('/garcom', $enviado['payload']['url']);
        $this->assertStringNotContainsString('64999991234', json_encode($enviado['payload']));
        $this->assertStringNotContainsString('Ana', json_encode($enviado['payload']['corpo']));
    }

    public function test_com_a_chave_desligada_nenhum_gatilho_grava_envio(): void
    {
        config(['pizzaria.push.ativo' => false]);
        $dono = $this->garcom('Ana');

        $this->chamar($this->mesaAberta($dono));

        $this->assertDatabaseCount('push_envios', 0);
    }

    public function test_erro_inesperado_no_enviador_vira_falha_do_envio_e_nao_quebra_o_fluxo(): void
    {
        $dono = $this->garcom('Ana');
        $sessao = $this->mesaAberta($dono);
        $this->app->instance(EnviadorPush::class, new class implements EnviadorPush
        {
            public function enviar(DispositivoPush $dispositivo, array $payload, int $ttl, string $urgencia = 'high', ?string $topic = null): ResultadoEnvioPush
            {
                throw new \RuntimeException('boom');
            }
        });

        $chamado = $this->chamar($sessao);

        $this->assertNotNull($chamado->id);
        $this->assertSame(PushEnvio::FALHOU, PushEnvio::query()->sole()->pe_status);
    }

    public function test_falha_ao_despachar_o_job_nunca_quebra_o_fluxo_de_negocio(): void
    {
        $dono = $this->garcom('Ana');
        $sessao = $this->mesaAberta($dono);
        $this->app->bind(DispositivoPushService::class, fn () => throw new \RuntimeException('boom'));

        $chamado = $this->chamar($sessao);

        $this->assertNotNull($chamado->id);
        $this->assertSame(PushEnvio::PENDENTE, PushEnvio::query()->sole()->pe_status);
    }

    public function test_configuracao_do_push_tem_padroes_e_limites(): void
    {
        $this->assertSame(DestinoAvisoMesaEnum::GARCOM_DA_MESA, Empresa::configuracaoPush()['destino']);
        $this->assertTrue(Empresa::configuracaoPush()['repetir']);

        Empresa::create(['empresa_razao_social' => 'Pizzaria', 'empresa_push_destino_mesa' => 'TODOS_EM_TURNO', 'empresa_push_repetir_intervalo_min' => 5, 'empresa_push_repetir_max' => 20]);

        $config = Empresa::configuracaoPush();
        $this->assertSame(DestinoAvisoMesaEnum::TODOS_EM_TURNO, $config['destino']);
        $this->assertSame(5, $config['intervalo_min']);
        $this->assertSame(20, $config['repetir_max']);
    }

    public function test_admin_salva_os_parametros_do_salao(): void
    {
        $this->seed(PermissionSeeder::class);
        Empresa::create(['empresa_razao_social' => 'Pizzaria Teste']);
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        Livewire::test(ConfiguracaoPedidos::class)
            ->fillForm([
                'empresa_push_destino_mesa' => 'TODOS_EM_TURNO',
                'empresa_push_repetir_ate_atender' => true,
                'empresa_push_repetir_intervalo_min' => 3,
                'empresa_push_repetir_max' => 15,
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $empresa = Empresa::first();
        $this->assertSame(DestinoAvisoMesaEnum::TODOS_EM_TURNO, $empresa->empresa_push_destino_mesa);
        $this->assertSame(3, $empresa->empresa_push_repetir_intervalo_min);
        $this->assertSame(15, $empresa->empresa_push_repetir_max);
    }

    public function test_parametros_do_salao_validam_os_limites(): void
    {
        $this->seed(PermissionSeeder::class);
        Empresa::create(['empresa_razao_social' => 'Pizzaria Teste']);
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        foreach ([['empresa_push_repetir_intervalo_min', 0], ['empresa_push_repetir_intervalo_min', 31], ['empresa_push_repetir_max', 0], ['empresa_push_repetir_max', 61]] as [$campo, $valor]) {
            Livewire::test(ConfiguracaoPedidos::class)
                ->fillForm(['empresa_push_repetir_ate_atender' => true, $campo => $valor])
                ->call('save')
                ->assertHasFormErrors([$campo]);
        }
    }

    public function test_secao_do_salao_so_aparece_com_o_push_ligado(): void
    {
        $this->seed(PermissionSeeder::class);
        Empresa::create(['empresa_razao_social' => 'Pizzaria Teste']);
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        Livewire::test(ConfiguracaoPedidos::class)->assertSee('Notificações do salão');

        config(['pizzaria.push.ativo' => false]);
        Livewire::test(ConfiguracaoPedidos::class)->assertDontSee('Notificações do salão');
    }
}
