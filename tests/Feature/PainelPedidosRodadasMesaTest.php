<?php

namespace Tests\Feature;

use App\Enums\PedidoOrigemEnum;
use App\Enums\ProdutoTipoEnum;
use App\Filament\Pages\PainelPedidos;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\SessaoMesa;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Rodadas do Painel do Garçom no Painel de Pedidos da cozinha: alerta só
 * quando a rodada é enviada (não quando o garçom abre o rascunho) e
 * auto-impressão das rodadas novas.
 */
class PainelPedidosRodadasMesaTest extends TestCase
{
    use RefreshDatabase;

    private SessaoMesa $sessao;

    private Produto $produto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        Carbon::setTestNow('2026-09-30 20:00:00');

        $gerente = User::factory()->create(['name_first' => 'Gerente']);
        $gerente->assignRole('Gerente');
        $gerente->givePermissionTo(Permission::firstOrCreate(['name' => 'view_any:pedido', 'guard_name' => 'web']));
        $this->actingAs($gerente);

        $mesa = Mesa::create(['mesa_nome' => 'Mesa 3', 'mesa_status' => 'OCUPADA']);
        $this->sessao = SessaoMesa::create(['sessao_mesa_mesa_id' => $mesa->id, 'sessao_mesa_usuario_id' => $gerente->id, 'sessao_mesa_status' => 'ABERTA']);
        $this->produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Pizzas'])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
        ]);

        // Um pedido já na fila, para o alerta estar "armado" (só toca quando a fila cresce a partir de > 0).
        $this->rodada('ABERTO', Carbon::now()->subMinutes(5));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function rodada(string $status, ?Carbon $abertura = null): Pedido
    {
        $pedido = Pedido::create([
            'pedido_status' => $status,
            'pedido_origem' => PedidoOrigemEnum::MESA,
            'pedido_sessao_mesa_id' => $this->sessao->id,
            'pedido_datahora_abertura' => $abertura,
        ]);

        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $this->produto->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 50,
            'item_pedido_valor' => 50,
            'item_pedido_status' => 'INSERIDO',
        ]);

        return $pedido;
    }

    public function test_rascunho_de_rodada_nao_dispara_alerta_nem_impressao(): void
    {
        $painel = Livewire::test(PainelPedidos::class);

        Carbon::setTestNow(Carbon::now()->addSeconds(10));
        $this->rodada('INICIADO');

        $painel->call('atualizar')
            ->assertNotDispatched('novo-pedido')
            ->assertNotDispatched('imprimir-rodadas-mesa');
    }

    public function test_rodada_enviada_alerta_e_vai_para_impressao_uma_vez(): void
    {
        $painel = Livewire::test(PainelPedidos::class);

        Carbon::setTestNow(Carbon::now()->addSeconds(10));
        $rodada = $this->rodada('ABERTO', Carbon::now());

        $painel->call('atualizar')
            ->assertDispatched('novo-pedido')
            ->assertDispatched('imprimir-rodadas-mesa', urls: [route('pedido.imprimir', ['id' => $rodada->id])]);

        // Mudança em outro pedido: a rodada já impressa não volta.
        Carbon::setTestNow(Carbon::now()->addSeconds(10));
        $rodada->update(['pedido_status' => 'PREPARANDO']);

        $painel->call('atualizar')->assertNotDispatched('imprimir-rodadas-mesa');
    }
}
