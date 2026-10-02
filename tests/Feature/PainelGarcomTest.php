<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Enums\StatusPedidoEnum;
use App\Filament\Garcom\Pages\AtenderMesa;
use App\Filament\Garcom\Pages\Auth\LoginGarcom;
use App\Filament\Garcom\Pages\MapaMesas;
use App\Livewire\PedidoProdutoSelector;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\SessaoMesa;
use App\Models\User;
use App\Services\Garcom\AtendimentoMesaService;
use App\Services\SessaoMesaService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PainelGarcomTest extends TestCase
{
    use RefreshDatabase;

    private User $garcom;

    private Mesa $mesa;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('garcom'));

        $this->garcom = User::factory()->garcom()->comPin('1234')->create(['name_first' => 'Ana']);
        $this->mesa = Mesa::create(['mesa_nome' => 'Mesa 1', 'mesa_status' => 'LIBERADA', 'mesa_numero' => 1]);
    }

    private function sessaoAberta(): SessaoMesa
    {
        return app(SessaoMesaService::class)->abrir($this->mesa->id, $this->garcom->id);
    }

    private function item(Pedido $pedido): ItensPedido
    {
        $produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Pizzas'])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 50.00,
        ]);

        return ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $produto->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 50.00,
            'item_pedido_valor' => 50.00,
            'item_pedido_desconto' => 0,
            'item_pedido_valor_adicionais' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);
    }

    public function test_garcom_acessa_o_salao_mas_nao_o_admin(): void
    {
        $this->actingAs($this->garcom);

        $this->get('/garcom')->assertOk()->assertSee('Mesa 1');
        $this->get('/admin')->assertForbidden();
    }

    public function test_entregador_nao_acessa_o_salao(): void
    {
        $this->actingAs(User::factory()->entregador()->create(['name_first' => 'Beto']));

        $this->get('/garcom')->assertForbidden();
    }

    public function test_login_por_pin(): void
    {
        Livewire::test(LoginGarcom::class)
            ->assertSet('modo', 'pin')
            ->call('escolherUsuario', $this->garcom->id)
            ->set('pin', '1234')
            ->call('entrarComPin');

        $this->assertAuthenticatedAs($this->garcom);
    }

    public function test_login_por_pin_errado_nao_autentica(): void
    {
        Livewire::test(LoginGarcom::class)
            ->call('escolherUsuario', $this->garcom->id)
            ->set('pin', '9999')
            ->call('entrarComPin')
            ->assertNotified('PIN incorreto.');

        $this->assertGuest();
    }

    public function test_abrir_mesa_pelo_mapa(): void
    {
        $this->actingAs($this->garcom);

        Livewire::test(MapaMesas::class)
            ->call('tocarMesa', $this->mesa->id)
            ->assertActionMounted('abrirMesa')
            ->setActionData(['pessoas' => 3])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $sessao = SessaoMesa::where('sessao_mesa_mesa_id', $this->mesa->id)->sole();
        $this->assertSame(3, $sessao->sessao_mesa_pessoas);
        $this->assertSame($this->garcom->id, $sessao->sessao_mesa_usuario_id);
    }

    public function test_enviar_rodada_pela_tela_da_mesa(): void
    {
        $this->actingAs($this->garcom);
        $sessao = $this->sessaoAberta();

        $tela = Livewire::test(AtenderMesa::class, ['sessao' => $sessao->id]);
        $rascunho = Pedido::findOrFail($tela->get('rascunhoId'));
        $this->item($rascunho);

        $tela->call('enviarRodada')->assertSet('aba', 'rodadas');

        $this->assertSame(StatusPedidoEnum::ABERTO->value, $rascunho->fresh()->pedido_status);
        $this->assertNotSame($rascunho->id, $tela->get('rascunhoId'));
    }

    public function test_marcar_rodada_pronta_como_servida(): void
    {
        $this->actingAs($this->garcom);
        $sessao = $this->sessaoAberta();
        $rodada = app(AtendimentoMesaService::class)->rascunho($sessao, $this->garcom);
        $this->item($rodada);
        app(AtendimentoMesaService::class)->enviarRodada($rodada);
        $rodada->fresh()->update(['pedido_status' => StatusPedidoEnum::PRONTO->value]);

        Livewire::test(AtenderMesa::class, ['sessao' => $sessao->id])
            ->set('aba', 'rodadas')
            ->assertSee('Servido na mesa')
            ->call('marcarEntregue', $rodada->id);

        $this->assertSame(StatusPedidoEnum::ENTREGUE->value, $rodada->fresh()->pedido_status);
    }

    public function test_mapa_avisa_rodada_que_ficou_pronta(): void
    {
        $this->actingAs($this->garcom);
        $sessao = $this->sessaoAberta();
        $rodada = app(AtendimentoMesaService::class)->rascunho($sessao, $this->garcom);
        $this->item($rodada);
        app(AtendimentoMesaService::class)->enviarRodada($rodada);

        $mapa = Livewire::test(MapaMesas::class);

        $rodada->fresh()->update(['pedido_status' => StatusPedidoEnum::PRONTO->value]);

        $mapa->call('verificarProntas')
            ->assertNotified('Pedido pronto!')
            ->assertDispatched('garcom-pedido-pronto');
    }

    public function test_pizza_meio_a_meio_sai_com_a_observacao_do_garcom(): void
    {
        $categoria = Categoria::create(['categoria_nome' => 'Pizzas', 'categoria_permite_sabores' => true, 'categoria_cardapio_garcom' => true]);
        $categoria->sincronizarQuantidadesSabores(2);
        [$calabresa, $mussarela] = collect(['Calabresa' => 50.0, 'Mussarela' => 60.0])
            ->map(fn (float $preco, string $nome) => Produto::create([
                'produto_descricao' => $nome,
                'produto_categoria_id' => $categoria->id,
                'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
                'produto_preco_venda' => $preco,
            ]))
            ->values()
            ->all();
        $rascunho = app(AtendimentoMesaService::class)->rascunho($this->sessaoAberta(), $this->garcom);

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $rascunho->id])
            ->call('selecionarProduto', $calabresa->id)
            ->call('setModoSabores', 2)
            ->call('toggleSabor', $calabresa->id)
            ->call('toggleSabor', $mussarela->id)
            ->set('saboresObservacao', 'Sem cebola')
            ->call('confirmarSabores')
            ->assertSet('saboresObservacao', '');

        $this->assertSame('Sem cebola', ItensPedido::sole()->item_pedido_observacao);
    }
}
