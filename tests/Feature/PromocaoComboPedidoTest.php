<?php

namespace Tests\Feature;

use App\Enums\CanalLancamentoEnum;
use App\Enums\ProdutoTipoEnum;
use App\Enums\TipoDescontoEnum;
use App\Models\Categoria;
use App\Models\Cupom;
use App\Models\ItensPedido;
use App\Models\Mesa;
use App\Models\OpcoesEntregas;
use App\Models\Pedido;
use App\Models\PedidoComboAplicacao;
use App\Models\Produto;
use App\Models\PromocaoCombo;
use App\Models\SessaoMesa;
use App\Models\User;
use App\Models\Venda;
use App\Services\CupomPedidoService;
use App\Services\ItemSolicitado;
use App\Services\LancamentoItemPedidoService;
use App\Services\LancamentoItensVendaService;
use App\Services\TotaisPedidoService;
use App\Services\VendaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Promoção Combo "2 pizzas grandes por R$ 87,99" em pedido gravado: o
 * desconto é rateado nas linhas a cada mudança e chega à venda.
 */
class PromocaoComboPedidoTest extends TestCase
{
    use RefreshDatabase;

    private Categoria $grande;

    private OpcoesEntregas $retirada;

    protected function setUp(): void
    {
        parent::setUp();

        $this->grande = Categoria::create(['categoria_nome' => 'Pizza Grande', 'categoria_cardapio_garcom' => true]);
        $this->retirada = OpcoesEntregas::create(['opcaoentrega_nome' => 'Retirada', 'opcaoentrega_valor_frete' => 0, 'opcaoentrega_min_valor_frete' => 0]);
    }

    private function produto(string $nome, float $preco, ?Categoria $categoria = null): Produto
    {
        return Produto::create([
            'produto_descricao' => $nome,
            'produto_categoria_id' => ($categoria ?? $this->grande)->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => $preco,
            'produto_cardapio_garcom' => true,
        ]);
    }

    private function pedido(array $attrs = []): Pedido
    {
        return Pedido::create($attrs + ['pedido_status' => 'ABERTO', 'pedido_opcaoentrega_id' => $this->retirada->id]);
    }

    private function lancar(Pedido $pedido, Produto $produto, float $quantidade = 1): ItensPedido
    {
        $lancamento = app(LancamentoItemPedidoService::class);

        return $lancamento->gravar($pedido->id, $lancamento->precificar(new ItemSolicitado($produto->id, $quantidade), CanalLancamentoEnum::SALAO));
    }

    public function test_duas_grandes_saem_por_87_99_com_o_desconto_rateado_nas_linhas_e_no_historico(): void
    {
        PromocaoCombo::factory()->daCategoria($this->grande)->create();
        $pedido = $this->pedido();

        $calabresa = $this->lancar($pedido, $this->produto('Calabresa', 59.90));
        $frango = $this->lancar($pedido, $this->produto('Frango', 50.10));

        $this->assertSame('11.99', $calabresa->fresh()->item_pedido_desconto_combo);
        $this->assertSame('47.91', $calabresa->fresh()->item_pedido_valor);
        $this->assertSame('10.02', $frango->fresh()->item_pedido_desconto_combo);
        $this->assertSame('40.08', $frango->fresh()->item_pedido_valor);
        $this->assertSame('87.99', $pedido->fresh()->pedido_valor_total);
        $this->assertSame('110.00', $pedido->fresh()->pedido_valor_itens);

        $aplicacao = PedidoComboAplicacao::sole();
        $this->assertSame('2 Pizzas Grandes por R$ 87,99', $aplicacao->pca_nome);
        $this->assertSame('110.00', $aplicacao->pca_valor_cheio);
        $this->assertSame('22.01', $aplicacao->pca_desconto);
        $this->assertSame(2, $aplicacao->itens()->count());
    }

    public function test_remover_uma_pizza_desfaz_o_combo_e_a_terceira_sai_cheia(): void
    {
        PromocaoCombo::factory()->daCategoria($this->grande)->create();
        $pedido = $this->pedido();
        $primeira = $this->lancar($pedido, $this->produto('Calabresa', 50.00));
        $segunda = $this->lancar($pedido, $this->produto('Frango', 50.00));
        $terceira = $this->lancar($pedido, $this->produto('Portuguesa', 50.00));

        $this->assertSame('0.00', $terceira->fresh()->item_pedido_desconto_combo);
        $this->assertSame('137.99', $pedido->fresh()->pedido_valor_total);

        app(LancamentoItemPedidoService::class)->remover($segunda->fresh());

        $this->assertSame('6.01', $primeira->fresh()->item_pedido_desconto_combo);
        $this->assertSame('6.00', $terceira->fresh()->item_pedido_desconto_combo);
        $this->assertSame('87.99', $pedido->fresh()->pedido_valor_total);

        app(LancamentoItemPedidoService::class)->remover($terceira->fresh());

        $this->assertSame('0.00', $primeira->fresh()->item_pedido_desconto_combo);
        $this->assertSame('50.00', $primeira->fresh()->item_pedido_valor);
        $this->assertSame(0, PedidoComboAplicacao::count());
    }

    public function test_bebida_nao_conta_e_mudar_quantidade_recalcula(): void
    {
        PromocaoCombo::factory()->daCategoria($this->grande)->create();
        $pedido = $this->pedido();
        $pizza = $this->lancar($pedido, $this->produto('Calabresa', 50.00));
        $this->lancar($pedido, $this->produto('Refri 2L', 12.00, Categoria::create(['categoria_nome' => 'Bebidas', 'categoria_cardapio_garcom' => true])));

        $this->assertSame(0, PedidoComboAplicacao::count());

        app(LancamentoItemPedidoService::class)->atualizarQuantidade($pizza->fresh(), 2);

        $this->assertSame('12.01', $pizza->fresh()->item_pedido_desconto_combo);
        $this->assertSame('87.99', $pizza->fresh()->item_pedido_valor);
        $this->assertSame('99.99', $pedido->fresh()->pedido_valor_total);
    }

    public function test_cupom_incide_depois_do_combo_e_a_venda_recebe_os_dois_descontos(): void
    {
        PromocaoCombo::factory()->daCategoria($this->grande)->create(['promocombo_preco' => 80.00]);
        $pedido = $this->pedido();
        $this->lancar($pedido, $this->produto('Calabresa', 50.00));
        $this->lancar($pedido, $this->produto('Frango', 50.00));
        $cupom = Cupom::create(['cupom_codigo' => 'DEZ', 'cupom_tipo_desconto' => TipoDescontoEnum::PERCENTUAL->value, 'cupom_valor_desconto' => 10]);

        app(CupomPedidoService::class)->aplicar($pedido, $cupom);
        app(TotaisPedidoService::class)->recalcular($pedido);

        $this->assertSame('72.00', $pedido->fresh()->pedido_valor_total);
        $this->assertSame('28.00', $pedido->fresh()->pedido_valor_desconto);

        $venda = Venda::create(['venda_status' => 'INICIADA']);
        app(LancamentoItensVendaService::class)->lancarPedido($venda, $pedido->fresh());
        $venda = app(VendaService::class)->atualizarValoresdaVenda($venda->id);

        $this->assertSame(72.0, round((float) $venda->venda_valor_total, 2));
        $this->assertSame(28.0, round((float) $venda->venda_valor_desconto, 2));
    }

    public function test_mesa_e_opcao_de_entrega_fora_da_lista_saem_pelo_preco_normal(): void
    {
        $delivery = OpcoesEntregas::create(['opcaoentrega_nome' => 'Delivery', 'opcaoentrega_valor_frete' => 0, 'opcaoentrega_min_valor_frete' => 0]);
        PromocaoCombo::factory()->daCategoria($this->grande)->create(['promocombo_opcoes_entrega' => [$delivery->id]]);
        $sessao = SessaoMesa::create([
            'sessao_mesa_mesa_id' => Mesa::create(['mesa_nome' => 'Mesa 1', 'mesa_status' => 'LIBERADA'])->id,
            'sessao_mesa_usuario_id' => User::factory()->admin()->create(['name_first' => 'Ana'])->id,
            'sessao_mesa_status' => 'ABERTA',
        ]);
        $pizza = $this->produto('Calabresa', 50.00);

        foreach ([$this->pedido(['pedido_opcaoentrega_id' => null, 'pedido_sessao_mesa_id' => $sessao->id]), $this->pedido()] as $pedido) {
            $this->lancar($pedido, $pizza, 2);
            $this->assertSame('100.00', $pedido->fresh()->pedido_valor_total);
        }

        $noDelivery = $this->pedido(['pedido_opcaoentrega_id' => $delivery->id]);
        $this->lancar($noDelivery, $pizza, 2);
        $this->assertSame('87.99', $noDelivery->fresh()->pedido_valor_total);
    }

    public function test_pedido_mantem_o_combo_depois_que_a_promocao_sai_da_vigencia(): void
    {
        $promocao = PromocaoCombo::factory()->daCategoria($this->grande)->create();
        $pizza = $this->produto('Calabresa', 50.00);
        $antigo = $this->pedido();
        $this->lancar($antigo, $pizza, 2);

        $promocao->update(['promocombo_fim' => now()->subMinute()]);
        $this->lancar($antigo, $this->produto('Refri', 10.00, Categoria::create(['categoria_nome' => 'Bebidas', 'categoria_cardapio_garcom' => true])));
        $novo = $this->pedido();
        $this->lancar($novo, $pizza, 2);

        $this->assertSame('97.99', $antigo->fresh()->pedido_valor_total);
        $this->assertSame('100.00', $novo->fresh()->pedido_valor_total);
    }

    public function test_linha_ja_vendida_fica_congelada_com_a_parte_do_combo(): void
    {
        PromocaoCombo::factory()->daCategoria($this->grande)->create();
        $pedido = $this->pedido();
        $vendida = $this->lancar($pedido, $this->produto('Calabresa', 50.00));
        $livre = $this->lancar($pedido, $this->produto('Frango', 50.00));
        $vendida->update(['item_pedido_venda_id' => Venda::create(['venda_status' => 'INICIADA'])->id]);

        $terceira = $this->lancar($pedido, $this->produto('Portuguesa', 50.00));

        $this->assertSame('6.01', $vendida->fresh()->item_pedido_desconto_combo);
        $this->assertSame('6.00', $livre->fresh()->item_pedido_desconto_combo);
        $this->assertSame('0.00', $terceira->fresh()->item_pedido_desconto_combo);
        $this->assertSame(1, PedidoComboAplicacao::count());
    }
}
