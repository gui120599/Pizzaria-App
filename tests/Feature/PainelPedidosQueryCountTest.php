<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Enums\StatusPedidoEnum;
use App\Filament\Pages\PainelPedidos;
use App\Models\AdicionaisItemPedido;
use App\Models\Adicional;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\OpcoesEntregas;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A tela fica aberta o dia inteiro fazendo poll. Se o número de queries crescer
 * com a quantidade de pedidos, o banco sente.
 *
 * Este teste também é o único que pega o erro mais fácil de cometer na
 * baseQuery(): um `select()` numa relação que esqueceu a chave estrangeira. O
 * eager load falha em silêncio nesse caso — devolve vazio e o Blade volta a
 * buscar linha por linha.
 */
class PainelPedidosQueryCountTest extends TestCase
{
    use RefreshDatabase;

    private Produto $produto;

    private Adicional $adicional;

    private OpcoesEntregas $entrega;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        Carbon::setTestNow('2026-09-30 20:00:00');

        $categoria = Categoria::create(['categoria_nome' => 'Pizzas']);

        $this->produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
        ]);

        $this->adicional = Adicional::create(['adicional_nome' => 'Borda']);

        $this->entrega = OpcoesEntregas::create([
            'opcaoentrega_nome' => 'Delivery',
            'opcaoentrega_requer_endereco' => true,
        ]);

        $user = User::factory()->create(['name_first' => 'Gerente']);
        $user->assignRole('Gerente');
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'view_any:pedido', 'guard_name' => 'web']));

        $this->actingAs($user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Pedido completo: com entrega, itens, adicionais e observação. */
    private function criarPedidos(int $quantidade): void
    {
        foreach (range(1, $quantidade) as $i) {
            $pedido = Pedido::create([
                'pedido_status' => StatusPedidoEnum::PREPARANDO->value,
                'pedido_opcaoentrega_id' => $this->entrega->id,
                'pedido_endereco_entrega' => "Rua {$i}, 100",
                'pedido_datahora_abertura' => Carbon::now(),
                'pedido_datahora_preparo' => Carbon::now(),
            ]);

            $item = ItensPedido::create([
                'item_pedido_pedido_id' => $pedido->id,
                'item_pedido_produto_id' => $this->produto->id,
                'item_pedido_quantidade' => 2,
                'item_pedido_valor_unitario' => 40,
                'item_pedido_valor' => 80,
                'item_pedido_observacao' => 'Sem cebola',
                'item_pedido_status' => 'INSERIDO',
            ]);

            AdicionaisItemPedido::create([
                'aip_item_pedido_id' => $item->id,
                'aip_adicional_id' => $this->adicional->id,
                'aip_quantidade' => 1,
            ]);
        }
    }

    /** Conta as queries de um render completo do board. */
    private function queriesDeUmRender(): int
    {
        $total = 0;
        DB::listen(function () use (&$total) {
            $total++;
        });

        Livewire::test(PainelPedidos::class)->assertOk();

        return $total;
    }

    public function test_numero_de_queries_nao_cresce_com_a_quantidade_de_pedidos(): void
    {
        $this->criarPedidos(3);

        // Render descartado: o primeiro de cada processo aquece os caches de
        // autenticação e de permissions do Spatie, e essas queries entrariam na
        // medição só da primeira vez, mascarando a comparação.
        $this->queriesDeUmRender();

        $comPoucos = $this->queriesDeUmRender();

        $this->criarPedidos(27);
        $comMuitos = $this->queriesDeUmRender();

        $this->assertSame(
            $comPoucos,
            $comMuitos,
            "O render passou de {$comPoucos} para {$comMuitos} queries ao ir de 3 para 30 pedidos — há N+1."
        );
    }

    /**
     * Guarda direta contra `select()` sem a FK: se a relação vier vazia, o card
     * não teria itens nem adicionais para exibir.
     */
    public function test_eager_load_traz_itens_adicionais_e_relacoes_do_card(): void
    {
        $this->criarPedidos(1);

        $pedido = Livewire::test(PainelPedidos::class)
            ->get('colunas')['PREPARANDO']
            ->first();

        $this->assertNotNull($pedido);

        $item = $pedido->item_pedido_pedido_id->first();
        $this->assertNotNull($item, 'Os itens do pedido não foram carregados (FK faltando no select?).');
        $this->assertSame('Calabresa', $item->produto->produto_descricao);
        $this->assertSame('Pizzas', $item->produto->categoria->categoria_nome);

        $adicional = $item->adicionaisItemPedido->first();
        $this->assertNotNull($adicional, 'Os adicionais do item não foram carregados.');
        $this->assertSame('Borda', $adicional->adicional->adicional_nome);

        $this->assertSame('Delivery', $pedido->opcaoEntrega->opcaoentrega_nome);
        $this->assertTrue($pedido->exigeEntrega());
    }
}
