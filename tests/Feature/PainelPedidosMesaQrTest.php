<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Filament\Pages\PainelPedidos;
use App\Models\Categoria;
use App\Models\Mesa;
use App\Models\MesaParticipante;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\User;
use App\Services\ItemSolicitado;
use App\Services\MesaCliente\AprovacaoPedidoMesaService;
use App\Services\MesaCliente\ParticipanteMesaService;
use App\Services\MesaCliente\PedidoMesaClienteService;
use App\Services\SessaoMesaService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Rodada pedida pelo cliente no QR da mesa na cozinha: imprime sozinha como a
 * rodada do garçom, mas só depois de aprovada, e a comanda diz quem pediu.
 */
class PainelPedidosMesaQrTest extends TestCase
{
    use RefreshDatabase;

    private User $gerente;

    private MesaParticipante $participante;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        Carbon::setTestNow('2026-09-30 20:00:00');

        $this->gerente = User::factory()->create(['name_first' => 'Gerente']);
        $this->gerente->assignRole('Gerente');
        $this->gerente->givePermissionTo(Permission::firstOrCreate(['name' => 'view_any:pedido', 'guard_name' => 'web']));
        $this->actingAs($this->gerente);

        $mesa = Mesa::factory()->create(['mesa_nome' => 'Mesa 3']);
        app(SessaoMesaService::class)->abrir($mesa->id, $this->gerente->id);
        $this->participante = app(ParticipanteMesaService::class)->entrar($mesa->fresh(), 'Ana', '64999991234')['participante'];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_pedido_do_cliente_so_vai_para_impressao_depois_de_aprovado(): void
    {
        $painel = Livewire::test(PainelPedidos::class);

        Carbon::setTestNow(Carbon::now()->addSeconds(10));
        $pendente = $this->pedidoDoCliente();
        $painel->call('atualizar')->assertNotDispatched('imprimir-rodadas-mesa');

        Carbon::setTestNow(Carbon::now()->addSeconds(10));
        app(AprovacaoPedidoMesaService::class)->aprovar($pendente, $this->gerente);

        $painel->call('atualizar')
            ->assertDispatched('imprimir-rodadas-mesa', urls: [route('pedido.imprimir', ['id' => $pendente->id])]);
    }

    public function test_comanda_mostra_quem_pediu_pelo_celular(): void
    {
        $pedido = $this->pedidoDoCliente();

        $this->get(route('pedido.imprimir', ['id' => $pedido->id]))
            ->assertOk()
            ->assertSee('Pedido pelo cliente: Ana');
    }

    private function pedidoDoCliente(): Pedido
    {
        $produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => Categoria::firstOrCreate(['categoria_nome' => 'Pizzas'], ['categoria_cardapio' => true])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 50.00,
            'produto_cardapio' => true,
        ]);

        return app(PedidoMesaClienteService::class)->enviar($this->participante, [new ItemSolicitado($produto->id)], (string) Str::uuid());
    }
}
