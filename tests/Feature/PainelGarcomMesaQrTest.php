<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Enums\StatusAprovacaoPedidoEnum;
use App\Enums\StatusChamadoMesaEnum;
use App\Enums\StatusMapaMesaEnum;
use App\Enums\TipoChamadoMesaEnum;
use App\Filament\Garcom\Pages\AtenderMesa;
use App\Filament\Garcom\Pages\MapaMesas;
use App\Models\Categoria;
use App\Models\Mesa;
use App\Models\MesaChamado;
use App\Models\MesaParticipante;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\SessaoMesa;
use App\Models\User;
use App\Services\Garcom\MapaMesasService;
use App\Services\ItemSolicitado;
use App\Services\MesaCliente\MesaChamadoService;
use App\Services\MesaCliente\ParticipanteMesaService;
use App\Services\MesaCliente\PedidoMesaClienteService;
use App\Services\SessaoMesaService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * O que chega pelo QR da mesa no Painel do Garçom: mapa, avisos, aprovar,
 * editar, recusar, chamados e bloqueio de celular.
 */
class PainelGarcomMesaQrTest extends TestCase
{
    use RefreshDatabase;

    private User $garcom;

    private Mesa $mesa;

    private SessaoMesa $sessao;

    private MesaParticipante $participante;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('garcom'));

        $this->garcom = User::factory()->garcom()->create(['name_first' => 'Bruno']);
        $this->mesa = Mesa::create(['mesa_nome' => 'Mesa 1', 'mesa_status' => 'LIBERADA', 'mesa_numero' => 1]);
        $this->sessao = app(SessaoMesaService::class)->abrir($this->mesa->id, $this->garcom->id);
        $this->participante = app(ParticipanteMesaService::class)->entrar($this->mesa->fresh(), 'Ana', '64999991234')['participante'];
        $this->actingAs($this->garcom);
    }

    public function test_mapa_mostra_a_mesa_com_pedido_para_aprovar_antes_do_chamado(): void
    {
        $this->pedidoPendente();
        $this->chamar(TipoChamadoMesaEnum::CHAMAR_GARCOM);

        $mesa = app(MapaMesasService::class)->mapa()->firstWhere('id', $this->mesa->id);

        $this->assertSame(StatusMapaMesaEnum::APROVAR_PEDIDO, $mesa['status']);
        $this->assertSame(1, $mesa['aprovar']);
    }

    public function test_mapa_mostra_quem_chamou_o_garcom_e_a_mesa_livre_que_querem_abrir(): void
    {
        $this->chamar(TipoChamadoMesaEnum::CHAMAR_GARCOM);
        $livre = Mesa::create(['mesa_nome' => 'Mesa 2', 'mesa_status' => 'LIBERADA', 'mesa_numero' => 2]);
        app(MesaChamadoService::class)->abrir($livre, TipoChamadoMesaEnum::ABRIR_MESA, ip: '10.0.0.1');

        $mapa = app(MapaMesasService::class)->mapa();

        $this->assertSame(StatusMapaMesaEnum::CHAMOU_GARCOM, $mapa->firstWhere('id', $this->mesa->id)['status']);
        $this->assertSame(StatusMapaMesaEnum::LIVRE, $mapa->firstWhere('id', $livre->id)['status']);
        $this->assertTrue($mapa->firstWhere('id', $livre->id)['querem_abrir']);
    }

    public function test_mapa_avisa_o_dono_da_mesa_uma_vez_e_o_pedido_de_abertura_a_todos(): void
    {
        $mapa = Livewire::test(MapaMesas::class);
        $this->pedidoPendente();

        $mapa->call('verificarAvisos')->assertNotified('Pedido do cliente para aprovar')->assertDispatched('garcom-pedido-pronto');
        $mapa->call('verificarAvisos')->assertNotDispatched('garcom-pedido-pronto');

        $outro = User::factory()->garcom()->create(['name_first' => 'Carla']);
        $livre = Mesa::create(['mesa_nome' => 'Mesa 2', 'mesa_status' => 'LIBERADA', 'mesa_numero' => 2]);
        app(MesaChamadoService::class)->abrir($livre, TipoChamadoMesaEnum::ABRIR_MESA, ip: '10.0.0.1');

        $avisosDaCarla = array_column(app(MapaMesasService::class)->avisosDoGarcom($outro->id), 'mesa');
        $this->assertSame(['Mesa 2'], $avisosDaCarla);
    }

    public function test_aprovar_manda_o_pedido_do_cliente_para_a_cozinha(): void
    {
        $pendente = $this->pedidoPendente();

        Livewire::test(AtenderMesa::class, ['sessao' => $this->sessao->id])
            ->assertSee('Pedido de Ana pelo celular')
            ->call('aprovarPedido', $pendente->id)
            ->assertNotified('Pedido do cliente enviado para a cozinha.');

        $pedido = $pendente->fresh();
        $this->assertSame('ABERTO', $pedido->pedido_status);
        $this->assertSame($this->garcom->id, $pedido->pedido_aprovado_por_id);
    }

    public function test_recusar_exige_motivo_e_cancela_o_pedido(): void
    {
        $pendente = $this->pedidoPendente();
        $tela = Livewire::test(AtenderMesa::class, ['sessao' => $this->sessao->id]);

        $tela->callAction(TestAction::make('recusarPedido')->arguments(['pedido' => $pendente->id]), ['motivo' => ''])
            ->assertHasFormErrors(['motivo' => 'required']);
        Livewire::test(AtenderMesa::class, ['sessao' => $this->sessao->id])
            ->callAction(TestAction::make('recusarPedido')->arguments(['pedido' => $pendente->id]), ['motivo' => 'Acabou a cerveja'])
            ->assertHasNoFormErrors();

        $pedido = $pendente->fresh();
        $this->assertSame(StatusAprovacaoPedidoEnum::RECUSADO, $pedido->pedido_aprovacao_status);
        $this->assertSame('Acabou a cerveja', $pedido->pedido_recusa_motivo);
    }

    public function test_editar_e_enviar_aprova_o_pedido_do_cliente_e_nao_mexe_no_rascunho(): void
    {
        $pendente = $this->pedidoPendente();
        $tela = Livewire::test(AtenderMesa::class, ['sessao' => $this->sessao->id]);
        $rascunhoId = $tela->get('rascunhoId');

        $tela->call('editarPedido', $pendente->id)
            ->assertSet('editandoPedidoId', $pendente->id)
            ->call('enviarRodada')
            ->assertSet('editandoPedidoId', null);

        $this->assertSame('ABERTO', $pendente->fresh()->pedido_status);
        $this->assertSame('INICIADO', Pedido::find($rascunhoId)->pedido_status);
    }

    public function test_atender_o_chamado_tira_da_fila(): void
    {
        $chamado = $this->chamar(TipoChamadoMesaEnum::CHAMAR_GARCOM);

        Livewire::test(AtenderMesa::class, ['sessao' => $this->sessao->id])
            ->assertSee('Chamou o garçom')
            ->call('atenderChamado', $chamado->id);

        $this->assertSame(StatusChamadoMesaEnum::ATENDIDO, $chamado->fresh()->mc_status);
    }

    public function test_bloqueia_so_celular_desta_mesa(): void
    {
        $outraMesa = Mesa::create(['mesa_nome' => 'Mesa 2', 'mesa_status' => 'LIBERADA', 'mesa_numero' => 2]);
        app(SessaoMesaService::class)->abrir($outraMesa->id, $this->garcom->id);
        $deOutraMesa = app(ParticipanteMesaService::class)->entrar($outraMesa->fresh(), 'João', '64988887777')['participante'];
        $tela = Livewire::test(AtenderMesa::class, ['sessao' => $this->sessao->id]);

        $tela->call('bloquearParticipante', $this->participante->id);
        $this->assertTrue($this->participante->fresh()->estaBloqueado());

        $this->expectException(ModelNotFoundException::class);
        $tela->call('bloquearParticipante', $deOutraMesa->id);
    }

    public function test_rodada_do_cliente_aparece_com_o_nome_de_quem_pediu(): void
    {
        $tela = Livewire::test(AtenderMesa::class, ['sessao' => $this->sessao->id]);
        $tela->call('aprovarPedido', $this->pedidoPendente()->id);

        $tela->assertSee('Pelo cliente · Ana');
    }

    /** Primeiro pedido do QR na mesa: espera o garçom pela regra padrão. */
    private function pedidoPendente(): Pedido
    {
        $produto = Produto::create([
            'produto_descricao' => 'Refrigerante',
            'produto_categoria_id' => Categoria::firstOrCreate(['categoria_nome' => 'Bebidas'], ['categoria_cardapio' => true])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 8.00,
            'produto_cardapio' => true,
        ]);

        return app(PedidoMesaClienteService::class)->enviar($this->participante, [new ItemSolicitado($produto->id)], (string) Str::uuid());
    }

    private function chamar(TipoChamadoMesaEnum $tipo): MesaChamado
    {
        return app(MesaChamadoService::class)->abrir($this->mesa->fresh(), $tipo, $this->participante);
    }
}
