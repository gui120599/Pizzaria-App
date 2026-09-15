<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Livewire\PedidoProdutoSelector;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\PromocaoAdicional;
use App\Models\PromocaoAdicionalConsumo;
use App\Models\PromocaoAdicionalOferta;
use App\Models\PromocaoAdicionalRegra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PedidoProdutoSelectorPromocaoAdicionalTest extends TestCase
{
    use RefreshDatabase;

    private Categoria $categoria;

    private Pedido $pedido;

    private Produto $pizza;

    private Produto $brotinho;

    protected function setUp(): void
    {
        parent::setUp();

        $this->categoria = Categoria::create(['categoria_nome' => 'Pizzas']);

        $this->pedido = Pedido::create([
            'pedido_status' => 'INICIADO',
            'pedido_datahora_abertura' => now(),
        ]);

        $this->pizza = $this->produto('Calabresa', 59.90);
        $this->brotinho = $this->produto('Pizza Brotinho', 20.00);
    }

    private function produto(string $nome, float $preco, array $attrs = []): Produto
    {
        return Produto::create(array_merge([
            'produto_descricao' => $nome,
            'produto_categoria_id' => $this->categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => $preco,
            'produto_cardapio' => true,
        ], $attrs));
    }

    /** Regra (config do gatilho) + 1 oferta padrão (brotinho). */
    private function regra(array $attrsRegra = [], array $attrsOferta = []): PromocaoAdicionalRegra
    {
        $promocao = PromocaoAdicional::create([
            'promoad_nome' => 'Dia do Cliente',
            'promoad_ativa' => true,
            'promoad_inicio' => now()->subHour(),
            'promoad_fim' => now()->addHour(),
        ]);

        $regra = $promocao->regras()->create(array_merge([
            'par_produto_gatilho_id' => $this->pizza->id,
            'par_preco_gatilho_override' => 39.90,
            'par_qtd_maxima_por_pedido' => 1,
        ], $attrsRegra));

        $regra->ofertas()->create(array_merge([
            'pao_produto_oferta_id' => $this->brotinho->id,
            'pao_valor_adicional' => 5.00,
        ], $attrsOferta));

        return $regra;
    }

    // ── Modal simples (produto sem sabores) ──────────────────────────────────

    public function test_oferta_aparece_ao_selecionar_produto_gatilho(): void
    {
        $regra = $this->regra();

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $this->pedido->id])
            ->call('selecionarProduto', $this->pizza->id)
            ->assertSet('ofertasDisponiveis.0.regra_id', $regra->id)
            ->assertSet('ofertasDisponiveis.0.valor_adicional', 5.0)
            // Convenção do sistema: preco_base é sempre o preço "cheio" (riscado
            // na UI), o abatimento vive em desconto_unit — preço final = 59,90 − 20 = 39,90.
            ->assertSet('produtoSelecionado.preco_base', 59.90)
            ->assertSet('produtoSelecionado.desconto_unit', 20.00);
    }

    public function test_sem_pedido_gravado_oferta_nao_aparece(): void
    {
        $this->regra();

        Livewire::test(PedidoProdutoSelector::class)
            ->call('selecionarProduto', $this->pizza->id)
            ->assertSet('ofertasDisponiveis', []);
    }

    public function test_aceitar_oferta_grava_as_duas_linhas_e_consome_saldo(): void
    {
        $regra = $this->regra();
        $ofertaId = $regra->ofertas->first()->id;

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $this->pedido->id])
            ->call('selecionarProduto', $this->pizza->id)
            ->call('selecionarOferta', $ofertaId)
            ->call('confirmarItem')
            ->assertSet('erroPromocao', null);

        $gatilho = ItensPedido::where('item_pedido_produto_id', $this->pizza->id)->firstOrFail();
        $oferta = ItensPedido::where('item_pedido_produto_id', $this->brotinho->id)->firstOrFail();

        $this->assertSame(39.90, (float) $gatilho->item_pedido_valor);
        $this->assertSame($regra->id, $gatilho->item_pedido_promocao_adicional_regra_id);

        $this->assertSame($gatilho->id, $oferta->item_pedido_origem_id);
        $this->assertSame($regra->id, $oferta->item_pedido_promocao_adicional_regra_id);
        $this->assertSame($ofertaId, $oferta->item_pedido_promocao_adicional_oferta_id);
        $this->assertSame(5.00, (float) $oferta->item_pedido_valor);

        $this->assertSame(1.0, (float) $regra->ofertas->first()->fresh()->pao_qtd_vendida);
    }

    public function test_recusar_oferta_grava_so_o_gatilho(): void
    {
        $this->regra();

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $this->pedido->id])
            ->call('selecionarProduto', $this->pizza->id)
            ->call('confirmarItem')
            ->assertSet('erroPromocao', null);

        $this->assertSame(1, ItensPedido::where('item_pedido_produto_id', $this->pizza->id)->count());
        $this->assertSame(0, ItensPedido::where('item_pedido_produto_id', $this->brotinho->id)->count());
    }

    public function test_selecionar_a_mesma_oferta_duas_vezes_desmarca(): void
    {
        $regra = $this->regra();
        $ofertaId = $regra->ofertas->first()->id;

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $this->pedido->id])
            ->call('selecionarProduto', $this->pizza->id)
            ->call('selecionarOferta', $ofertaId)
            ->assertSet('ofertaEscolhidaId', $ofertaId)
            ->call('selecionarOferta', $ofertaId)
            ->assertSet('ofertaEscolhidaId', null);
    }

    public function test_multiplas_ofertas_disponiveis_cliente_escolhe_uma(): void
    {
        $regra = $this->regra();
        $refrigerante = $this->produto('Refrigerante 2L', 12.00);
        $ofertaRefri = $regra->ofertas()->create([
            'pao_produto_oferta_id' => $refrigerante->id,
            'pao_valor_adicional' => 8.00,
        ]);

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $this->pedido->id])
            ->call('selecionarProduto', $this->pizza->id)
            ->assertSet('ofertasDisponiveis', fn ($ofertas) => count($ofertas) === 2)
            ->call('selecionarOferta', $ofertaRefri->id)
            ->call('confirmarItem')
            ->assertSet('erroPromocao', null);

        $this->assertSame(1, ItensPedido::where('item_pedido_produto_id', $refrigerante->id)->count());
        $this->assertSame(0, ItensPedido::where('item_pedido_produto_id', $this->brotinho->id)->count());
        $this->assertSame(1.0, (float) $ofertaRefri->fresh()->pao_qtd_vendida);
    }

    public function test_limite_por_pedido_bloqueia_segundo_aceite(): void
    {
        $regra = $this->regra(['par_qtd_maxima_por_pedido' => 1]);
        $ofertaId = $regra->ofertas->first()->id;

        $component = Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $this->pedido->id])
            ->call('selecionarProduto', $this->pizza->id)
            ->call('selecionarOferta', $ofertaId)
            ->call('confirmarItem')
            ->assertSet('erroPromocao', null);

        $component->call('selecionarProduto', $this->pizza->id)
            ->call('selecionarOferta', $ofertaId)
            ->call('confirmarItem')
            ->assertSet('erroPromocao', fn ($msg) => ! empty($msg));

        // A segunda pizza foi gravada (a oferta que falhou é que não), pois o
        // limite é só da oferta, não do produto gatilho em si.
        $this->assertSame(2, ItensPedido::where('item_pedido_produto_id', $this->pizza->id)->count());
        $this->assertSame(1, ItensPedido::where('item_pedido_produto_id', $this->brotinho->id)->count());
    }

    public function test_remover_gatilho_remove_oferta_em_cascata_e_estorna_saldo(): void
    {
        $regra = $this->regra();
        $ofertaId = $regra->ofertas->first()->id;

        $component = Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $this->pedido->id])
            ->call('selecionarProduto', $this->pizza->id)
            ->call('selecionarOferta', $ofertaId)
            ->call('confirmarItem');

        $gatilho = ItensPedido::where('item_pedido_produto_id', $this->pizza->id)->firstOrFail();
        $oferta = ItensPedido::where('item_pedido_produto_id', $this->brotinho->id)->firstOrFail();

        $component->call('removerItem', (string) $gatilho->id);

        $this->assertNull(ItensPedido::find($gatilho->id));
        $this->assertNull(ItensPedido::find($oferta->id));
        $this->assertSame(0.0, (float) PromocaoAdicionalOferta::find($ofertaId)->pao_qtd_vendida);

        // A linha do ledger tem cascadeOnDelete em pac_item_pedido_oferta_id —
        // some junto com o item (mesmo gotcha documentado no ledger do
        // relâmpago): o estorno do saldo já rodou antes, só a auditoria não
        // sobrevive à remoção do item.
        $this->assertSame(0, PromocaoAdicionalConsumo::where('pac_item_pedido_oferta_id', $oferta->id)->count());
    }

    public function test_ajustar_quantidade_nao_altera_item_de_promocao_adicional(): void
    {
        $regra = $this->regra();
        $ofertaId = $regra->ofertas->first()->id;

        $component = Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $this->pedido->id])
            ->call('selecionarProduto', $this->pizza->id)
            ->call('selecionarOferta', $ofertaId)
            ->call('confirmarItem');

        $gatilho = ItensPedido::where('item_pedido_produto_id', $this->pizza->id)->firstOrFail();
        $oferta = ItensPedido::where('item_pedido_produto_id', $this->brotinho->id)->firstOrFail();

        $component->call('incrementarQtd', (string) $gatilho->id);
        $component->call('incrementarQtd', (string) $oferta->id);

        $this->assertSame(1.0, (float) $gatilho->fresh()->item_pedido_quantidade);
        $this->assertSame(1.0, (float) $oferta->fresh()->item_pedido_quantidade);
    }

    // ── Modal de sabores (pizza inteira) ─────────────────────────────────────

    public function test_pizza_inteira_via_sabores_oferece_e_aceita_a_promocao(): void
    {
        $this->categoria->update(['categoria_permite_sabores' => true, 'categoria_max_sabores' => 2]);
        $regra = $this->regra();
        $ofertaId = $regra->ofertas->first()->id;

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $this->pedido->id])
            ->call('selecionarProduto', $this->pizza->id)
            ->assertSet('saboresModalAberta', true)
            ->assertSet('ofertasDisponiveis.0.regra_id', $regra->id)
            ->call('selecionarOferta', $ofertaId)
            ->call('confirmarSabores')
            ->assertSet('erroPromocao', null);

        $gatilho = ItensPedido::where('item_pedido_produto_id', $this->pizza->id)->firstOrFail();
        $oferta = ItensPedido::where('item_pedido_produto_id', $this->brotinho->id)->firstOrFail();

        $this->assertSame(39.90, (float) $gatilho->item_pedido_valor);
        $this->assertSame($gatilho->id, $oferta->item_pedido_origem_id);
        $this->assertSame(5.00, (float) $oferta->item_pedido_valor);
    }

    public function test_meia_a_meia_nao_oferece_promocao_adicional(): void
    {
        $this->categoria->update(['categoria_permite_sabores' => true, 'categoria_max_sabores' => 2]);
        $this->regra();
        $outraPizza = $this->produto('Marguerita', 55.00);

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $this->pedido->id])
            ->call('selecionarProduto', $this->pizza->id)
            ->call('setModoSabores', 2)
            ->assertSet('ofertasDisponiveis', [])
            ->call('toggleSabor', $this->pizza->id)
            ->assertSet('ofertasDisponiveis', [])
            ->call('toggleSabor', $outraPizza->id)
            ->assertSet('ofertasDisponiveis', []);
    }
}
