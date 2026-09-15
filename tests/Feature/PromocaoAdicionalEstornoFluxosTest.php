<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Livewire\ConfirmacoesPedidos;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\PromocaoAdicional;
use App\Models\PromocaoAdicionalConsumo;
use App\Models\PromocaoAdicionalOferta;
use App\Models\PromocaoAdicionalRegra;
use App\Models\SessaoMesa;
use App\Models\User;
use App\Services\PromocaoAdicionalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Estorno/cascata da promoção adicional nos fluxos de cancelamento/remoção
 * que não passam pelo balcão (PedidoProdutoSelectorPromocaoAdicionalTest
 * cobre esse) — mesmos 5 pontos mapeados para a promoção relâmpago.
 */
class PromocaoAdicionalEstornoFluxosTest extends TestCase
{
    use RefreshDatabase;

    private Categoria $categoria;

    private Produto $pizza;

    private Produto $brotinho;

    protected function setUp(): void
    {
        parent::setUp();

        $this->categoria = Categoria::create(['categoria_nome' => 'Pizzas']);
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        $this->pizza = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => $this->categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 59.90,
            'produto_cardapio' => true,
        ]);

        $this->brotinho = Produto::create([
            'produto_descricao' => 'Pizza Brotinho',
            'produto_categoria_id' => $this->categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 20.00,
            'produto_cardapio' => true,
        ]);
    }

    /** @return array{0: PromocaoAdicionalRegra, 1: PromocaoAdicionalOferta} */
    private function regra(): array
    {
        $promocao = PromocaoAdicional::create([
            'promoad_nome' => 'Dia do Cliente',
            'promoad_ativa' => true,
            'promoad_inicio' => now()->subHour(),
            'promoad_fim' => now()->addHour(),
        ]);

        $regra = $promocao->regras()->create([
            'par_produto_gatilho_id' => $this->pizza->id,
            'par_preco_gatilho_override' => 39.90,
        ]);

        $oferta = $regra->ofertas()->create([
            'pao_produto_oferta_id' => $this->brotinho->id,
            'pao_valor_adicional' => 5.00,
            'pao_qtd_total' => 10,
        ]);

        return [$regra, $oferta];
    }

    /** Pedido com gatilho + oferta já consumidos (saldo debitado). */
    private function pedidoComOfertaAceita(PromocaoAdicionalRegra $regra, PromocaoAdicionalOferta $oferta, array $pedidoAttrs = []): array
    {
        $pedido = Pedido::create(array_merge([
            'pedido_status' => 'INICIADO',
            'pedido_datahora_abertura' => now(),
            'pedido_valor_itens' => 44.90,
            'pedido_valor_total' => 44.90,
        ], $pedidoAttrs));

        $gatilho = ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $this->pizza->id,
            'item_pedido_promocao_adicional_regra_id' => $regra->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 59.90,
            'item_pedido_desconto_unitario' => 20.00,
            'item_pedido_valor' => 39.90,
            'item_pedido_desconto' => 20.00,
            'item_pedido_valor_adicionais' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);

        $itemOferta = ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $this->brotinho->id,
            'item_pedido_promocao_adicional_regra_id' => $regra->id,
            'item_pedido_promocao_adicional_oferta_id' => $oferta->id,
            'item_pedido_origem_id' => $gatilho->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 5.00,
            'item_pedido_valor' => 5.00,
            'item_pedido_desconto' => 0,
            'item_pedido_valor_adicionais' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);

        app(PromocaoAdicionalService::class)->consumir($itemOferta);

        $this->assertSame(1.0, (float) $oferta->fresh()->pao_qtd_vendida);

        return [$pedido, $gatilho, $itemOferta];
    }

    public function test_cancelar_pedido_estorna_a_promocao_adicional(): void
    {
        [$regra, $oferta] = $this->regra();
        [$pedido] = $this->pedidoComOfertaAceita($regra, $oferta);

        $this->post(route('pedido.cancelar', $pedido->id), [
            'pedido_motivo_cancelamento' => 'outro',
        ])->assertRedirect();

        $this->assertSame('CANCELADO', $pedido->fresh()->pedido_status);
        $this->assertSame(0.0, (float) $oferta->fresh()->pao_qtd_vendida);
    }

    public function test_rejeitar_pedido_estorna_a_promocao_adicional(): void
    {
        [$regra, $oferta] = $this->regra();
        [$pedido] = $this->pedidoComOfertaAceita($regra, $oferta);

        $this->post(route('rejeitar_pedido'), ['id' => $pedido->id])
            ->assertOk();

        $this->assertSame('CANCELADO', $pedido->fresh()->pedido_status);
        $this->assertSame(0.0, (float) $oferta->fresh()->pao_qtd_vendida);
    }

    public function test_remover_item_gatilho_remove_oferta_em_cascata_e_estorna(): void
    {
        [$regra, $oferta] = $this->regra();
        [, $gatilho, $itemOferta] = $this->pedidoComOfertaAceita($regra, $oferta);

        $this->post(route('item_pedido.remove'), ['id' => $gatilho->id])
            ->assertOk();

        $this->assertSame('REMOVIDO', $gatilho->fresh()->item_pedido_status);
        $this->assertSame('REMOVIDO', $itemOferta->fresh()->item_pedido_status);
        $this->assertSame(0.0, (float) $oferta->fresh()->pao_qtd_vendida);
    }

    public function test_remover_item_pedido_mesa_gatilho_remove_oferta_em_cascata(): void
    {
        [$regra, $oferta] = $this->regra();

        $mesa = Mesa::create(['mesa_nome' => 'Mesa 1', 'mesa_status' => 'OCUPADA']);
        $sessaoMesa = SessaoMesa::create([
            'sessao_mesa_mesa_id' => $mesa->id,
            'sessao_mesa_usuario_id' => auth()->id(),
            'sessao_mesa_status' => 'ABERTA',
        ]);

        [$pedido, $gatilho, $itemOferta] = $this->pedidoComOfertaAceita($regra, $oferta, [
            'pedido_sessao_mesa_id' => $sessaoMesa->id,
        ]);

        // Terceiro item não-promocional para o pedido não fechar sozinho.
        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $this->pizza->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 59.90,
            'item_pedido_valor' => 59.90,
            'item_pedido_desconto' => 0,
            'item_pedido_valor_adicionais' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);

        $this->get(route('removerItemPedidoMesa', ['item_pedido_id' => $gatilho->id, 'pedido_id' => $pedido->id]))
            ->assertRedirect();

        $this->assertSame('REMOVIDO', $gatilho->fresh()->item_pedido_status);
        $this->assertSame('REMOVIDO', $itemOferta->fresh()->item_pedido_status);
        $this->assertSame(0.0, (float) $oferta->fresh()->pao_qtd_vendida);

        // O valor da oferta removida em cascata também saiu do total do pedido:
        // 44,90 (inicial) − 39,90 (gatilho) − 5,00 (oferta) = 0.
        $this->assertSame(0.0, (float) $pedido->fresh()->pedido_valor_total);
    }

    public function test_confirmacoes_pedidos_cancelar_estorna_a_promocao_adicional(): void
    {
        [$regra, $oferta] = $this->regra();
        [$pedido] = $this->pedidoComOfertaAceita($regra, $oferta);

        Livewire::test(ConfirmacoesPedidos::class)
            ->call('cancelar', $pedido->id);

        $this->assertSame('CANCELADO', $pedido->fresh()->pedido_status);
        $this->assertSame(0.0, (float) $oferta->fresh()->pao_qtd_vendida);
    }

    public function test_comando_zerar_pedidos_iniciados_estorna_a_promocao_adicional(): void
    {
        [$regra, $oferta] = $this->regra();
        [$pedido] = $this->pedidoComOfertaAceita($regra, $oferta);

        Artisan::call('pedidos:zerar-iniciados', ['--confirmar' => true]);

        $this->assertSame('CANCELADO', $pedido->fresh()->pedido_status);
        $this->assertSame(0.0, (float) $oferta->fresh()->pao_qtd_vendida);
    }

    public function test_remover_oferta_isolada_nao_afeta_o_gatilho(): void
    {
        [$regra, $oferta] = $this->regra();
        [, $gatilho, $itemOferta] = $this->pedidoComOfertaAceita($regra, $oferta);

        $this->post(route('item_pedido.remove'), ['id' => $itemOferta->id])
            ->assertOk();

        $this->assertSame('INSERIDO', $gatilho->fresh()->item_pedido_status);
        $this->assertSame('REMOVIDO', $itemOferta->fresh()->item_pedido_status);
        $this->assertSame(0.0, (float) $oferta->fresh()->pao_qtd_vendida);
        $this->assertSame(
            0,
            PromocaoAdicionalConsumo::where('pac_item_pedido_oferta_id', $itemOferta->id)
                ->whereNull('pac_revertido_em')
                ->count()
        );
    }
}
