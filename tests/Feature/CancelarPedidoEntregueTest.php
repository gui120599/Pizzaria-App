<?php

namespace Tests\Feature;

use App\Enums\MotivoCancelamentoEnum;
use App\Enums\ProdutoTipoEnum;
use App\Exceptions\TransicaoPedidoInvalidaException;
use App\Filament\Resources\Pedidos\Pages\ListPedidos;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\User;
use App\Models\Venda;
use App\Services\PedidoStatusService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Pedido ENTREGUE que ficou sem pagamento (venda desfeita por estorno) pode
 * ser cancelado por Admin/Gerente, sem devolver o estoque.
 */
class CancelarPedidoEntregueTest extends TestCase
{
    use RefreshDatabase;

    private Produto $produto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->produto = Produto::create([
            'produto_descricao' => 'Refrigerante Lata',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Bebidas'])->id,
            'produto_tipo' => ProdutoTipoEnum::REVENDA->value,
            'produto_controla_estoque' => true,
            'produto_saldo_estoque' => 9,
            'produto_custo_medio' => 3,
        ]);
    }

    /** Mesmas permissões de pedido para os dois papéis: o que separa é a role. */
    private function usuario(string $role): User
    {
        $user = User::factory()->{$role}()->create(['name_first' => ucfirst($role)]);
        $user->givePermissionTo([
            Permission::findOrCreate('view_any:pedido'),
            Permission::findOrCreate('cancel:pedido'),
        ]);

        return $user;
    }

    private function pedidoEntregue(): Pedido
    {
        $pedido = Pedido::create(['pedido_status' => 'ENTREGUE', 'pedido_valor_total' => 20.00]);
        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $this->produto->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 20.00,
            'item_pedido_valor' => 20.00,
            'item_pedido_status' => 'INSERIDO',
        ]);

        return $pedido;
    }

    public function test_gerente_cancela_pedido_entregue_sem_devolver_estoque(): void
    {
        $gerente = $this->usuario('gerente');
        $this->actingAs($gerente);
        $pedido = $this->pedidoEntregue();

        Livewire::test(ListPedidos::class)
            ->callAction(TestAction::make('cancelarPedidoEntregue')->table($pedido), ['motivo' => MotivoCancelamentoEnum::PROBLEMA_PAGAMENTO->value])
            ->assertHasNoActionErrors();

        $pedido->refresh();
        $this->assertSame('CANCELADO', $pedido->pedido_status);
        $this->assertSame($gerente->id, $pedido->pedido_usuario_cancelou_id);
        $this->assertSame(9.0, (float) $this->produto->fresh()->produto_saldo_estoque);
    }

    public function test_atendente_nao_ve_a_acao(): void
    {
        $this->actingAs($this->usuario('atendente'));
        $pedido = $this->pedidoEntregue();

        Livewire::test(ListPedidos::class)
            ->assertActionHidden(TestAction::make('cancelarPedidoEntregue')->table($pedido));

        $this->expectException(AuthorizationException::class);
        app(PedidoStatusService::class)->cancelarEntregue($pedido, auth()->user(), MotivoCancelamentoEnum::OUTRO);
    }

    public function test_pedido_entregue_ja_pago_nao_pode_ser_cancelado(): void
    {
        $gerente = $this->usuario('gerente');
        $pedido = $this->pedidoEntregue();
        $venda = Venda::create(['venda_status' => 'INICIADA', 'venda_valor_total' => 20.00]);
        ItensPedido::where('item_pedido_pedido_id', $pedido->id)->update(['item_pedido_venda_id' => $venda->id]);

        $this->expectException(TransicaoPedidoInvalidaException::class);
        app(PedidoStatusService::class)->cancelarEntregue($pedido, $gerente, MotivoCancelamentoEnum::OUTRO);
    }
}
