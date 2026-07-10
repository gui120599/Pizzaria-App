<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Livewire\ConfirmacoesPedidos;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\PromocaoRelampago;
use App\Models\SessaoMesa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fase 3: estorno da promoção relâmpago nos fluxos de cancelamento/remoção
 * que não passam pelo balcão (PedidoProdutoSelectorPrecoTest cobre esse).
 */
class PromocaoRelampagoEstornoFluxosTest extends TestCase
{
    use RefreshDatabase;

    private Categoria $categoria;

    protected function setUp(): void
    {
        parent::setUp();

        $this->categoria = Categoria::create(['categoria_nome' => 'Pizzas']);
        $this->actingAs(User::factory()->create(['name_first' => 'Admin']));
    }

    private function produto(float $preco = 55.00): Produto
    {
        return Produto::create([
            'produto_descricao' => 'Marguerita',
            'produto_categoria_id' => $this->categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => $preco,
            'produto_cardapio' => true,
        ]);
    }

    private function promocaoCom(Produto $produto): PromocaoRelampago
    {
        $promocao = PromocaoRelampago::create([
            'promocao_nome' => 'Dia da Pizza',
            'promocao_ativa' => true,
            'promocao_inicio' => now()->subHour(),
            'promocao_fim' => now()->addHour(),
            'promocao_qtd_total' => 40,
            'promocao_qtd_vendida' => 0,
        ]);

        $promocao->promocaoProdutos()->create([
            'prp_produto_id' => $produto->id,
            'prp_preco_promocional' => 39.90,
        ]);

        return $promocao;
    }

    /** Pedido com um item promocional já consumido (contador debitado). */
    private function pedidoComItemPromocional(Produto $produto, PromocaoRelampago $promocao, array $pedidoAttrs = []): array
    {
        $pedido = Pedido::create(array_merge([
            'pedido_status' => 'INICIADO',
            'pedido_datahora_abertura' => now(),
            'pedido_valor_itens' => 39.90,
            'pedido_valor_total' => 39.90,
        ], $pedidoAttrs));

        $item = ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $produto->id,
            'item_pedido_promocao_id' => $promocao->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 55.00,
            'item_pedido_desconto_unitario' => 15.10,
            'item_pedido_valor' => 39.90,
            'item_pedido_desconto' => 15.10,
            'item_pedido_valor_adicionais' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);

        app(\App\Services\PromocaoRelampagoService::class)->consumir($item);

        $this->assertSame(1.0, (float) $promocao->fresh()->promocao_qtd_vendida);

        return [$pedido, $item];
    }

    public function test_cancelar_pedido_estorna_a_promocao(): void
    {
        $produto = $this->produto();
        $promocao = $this->promocaoCom($produto);
        [$pedido] = $this->pedidoComItemPromocional($produto, $promocao);

        $this->post(route('pedido.cancelar', $pedido->id), [
            'pedido_motivo_cancelamento' => 'outro',
        ])->assertRedirect();

        $this->assertSame('CANCELADO', $pedido->fresh()->pedido_status);
        $this->assertSame(0.0, (float) $promocao->fresh()->promocao_qtd_vendida);
    }

    public function test_rejeitar_pedido_estorna_a_promocao(): void
    {
        $produto = $this->produto();
        $promocao = $this->promocaoCom($produto);
        [$pedido] = $this->pedidoComItemPromocional($produto, $promocao);

        $this->post(route('rejeitar_pedido'), ['id' => $pedido->id])
            ->assertOk();

        $this->assertSame('CANCELADO', $pedido->fresh()->pedido_status);
        $this->assertSame(0.0, (float) $promocao->fresh()->promocao_qtd_vendida);
    }

    public function test_remover_item_estorna_a_promocao(): void
    {
        $produto = $this->produto();
        $promocao = $this->promocaoCom($produto);
        [, $item] = $this->pedidoComItemPromocional($produto, $promocao);

        $this->post(route('item_pedido.remove'), ['id' => $item->id])
            ->assertOk();

        $this->assertSame('REMOVIDO', $item->fresh()->item_pedido_status);
        $this->assertSame(0.0, (float) $promocao->fresh()->promocao_qtd_vendida);
    }

    public function test_remover_item_pedido_mesa_estorna_a_promocao(): void
    {
        $produto = $this->produto();
        $promocao = $this->promocaoCom($produto);

        $mesa = Mesa::create(['mesa_nome' => 'Mesa 1', 'mesa_status' => 'OCUPADA']);
        $sessaoMesa = SessaoMesa::create([
            'sessao_mesa_mesa_id' => $mesa->id,
            'sessao_mesa_usuario_id' => auth()->id(),
            'sessao_mesa_status' => 'ABERTA',
        ]);

        [$pedido, $item] = $this->pedidoComItemPromocional($produto, $promocao, [
            'pedido_sessao_mesa_id' => $sessaoMesa->id,
        ]);

        // Segundo item não-promocional para o pedido não fechar sozinho no CANCELADO.
        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $produto->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 55.00,
            'item_pedido_valor' => 55.00,
            'item_pedido_desconto' => 0,
            'item_pedido_valor_adicionais' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);

        $this->get(route('removerItemPedidoMesa', ['item_pedido_id' => $item->id, 'pedido_id' => $pedido->id]))
            ->assertRedirect();

        $this->assertSame('REMOVIDO', $item->fresh()->item_pedido_status);
        $this->assertSame(0.0, (float) $promocao->fresh()->promocao_qtd_vendida);
    }

    public function test_confirmacoes_pedidos_cancelar_estorna_a_promocao(): void
    {
        $produto = $this->produto();
        $promocao = $this->promocaoCom($produto);
        [$pedido] = $this->pedidoComItemPromocional($produto, $promocao);

        Livewire::test(ConfirmacoesPedidos::class)
            ->call('cancelar', $pedido->id);

        $this->assertSame('CANCELADO', $pedido->fresh()->pedido_status);
        $this->assertSame(0.0, (float) $promocao->fresh()->promocao_qtd_vendida);
    }

    public function test_comando_zerar_pedidos_iniciados_estorna_a_promocao(): void
    {
        $produto = $this->produto();
        $promocao = $this->promocaoCom($produto);
        [$pedido] = $this->pedidoComItemPromocional($produto, $promocao);

        Artisan::call('pedidos:zerar-iniciados', ['--confirmar' => true]);

        $this->assertSame('CANCELADO', $pedido->fresh()->pedido_status);
        $this->assertSame(0.0, (float) $promocao->fresh()->promocao_qtd_vendida);
    }

    public function test_comando_reconciliar_detecta_e_corrige_divergencia(): void
    {
        $produto = $this->produto();
        $promocao = $this->promocaoCom($produto);
        [, $item] = $this->pedidoComItemPromocional($produto, $promocao);

        // Simula drift: zera o contador manualmente sem passar pelo estorno,
        // como aconteceria se um fluxo esquecesse de chamar estornarItem/Pedido.
        // consumir() debitou via UPDATE bruto (bypassa o model em memória), então
        // é preciso sincronizar antes para o Eloquent perceber a mudança como dirty.
        $promocao->refresh()->update(['promocao_qtd_vendida' => 0]);

        $this->artisan('promocoes:reconciliar')
            ->assertSuccessful();

        // Sem --aplicar, só reporta: o contador continua divergente.
        $this->assertSame(0.0, (float) $promocao->fresh()->promocao_qtd_vendida);

        $this->artisan('promocoes:reconciliar', ['--aplicar' => true])
            ->assertSuccessful();

        $this->assertSame(1.0, (float) $promocao->fresh()->promocao_qtd_vendida);
    }
}
