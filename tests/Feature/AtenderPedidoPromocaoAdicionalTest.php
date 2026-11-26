<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Filament\Pages\AtenderPedido;
use App\Livewire\PedidoProdutoSelector;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\OpcoesEntregas;
use App\Models\OpcoesPagamento;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\PromocaoAdicional;
use App\Models\PromocaoAdicionalRegra;
use App\Models\User;
use App\Services\PromocaoAdicionalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Preço do gatilho da Promoção Adicional ("Calabresa por R$ 39,90") no
 * Atender Pedido: a prévia do pedido novo já cobra o preço da campanha, o
 * save grava a regra e recusa preço de campanha que acabou ou que não vale
 * para a opção de entrega.
 */
class AtenderPedidoPromocaoAdicionalTest extends TestCase
{
    use RefreshDatabase;

    private Categoria $categoria;

    private Produto $pizza;

    private OpcoesEntregas $retirada;

    private OpcoesPagamento $dinheiro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        $this->categoria = Categoria::create(['categoria_nome' => 'Pizzas']);
        $this->pizza = $this->produto('Calabresa', 59.90);
        $this->retirada = OpcoesEntregas::create(['opcaoentrega_nome' => 'Retirada na loja', 'opcaoentrega_valor_frete' => 0, 'opcaoentrega_min_valor_frete' => 0]);
        $this->dinheiro = OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_desc_nfe' => 'cash']);
    }

    private function produto(string $nome, float $preco): Produto
    {
        return Produto::create([
            'produto_descricao' => $nome,
            'produto_categoria_id' => $this->categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => $preco,
            'produto_cardapio_garcom' => true,
        ]);
    }

    /** Campanha vigente: Calabresa por R$ 39,90 (+ brotinho por R$ 5). */
    private function regra(array $attrsPromocao = []): PromocaoAdicionalRegra
    {
        $promocao = PromocaoAdicional::create($attrsPromocao + [
            'promoad_nome' => 'Dia do Cliente',
            'promoad_ativa' => true,
            'promoad_inicio' => now()->subHour(),
            'promoad_fim' => now()->addHour(),
        ]);

        $regra = $promocao->regras()->create([
            'par_produto_gatilho_id' => $this->pizza->id,
            'par_preco_gatilho_override' => 39.90,
        ]);
        $regra->ofertas()->create([
            'pao_produto_oferta_id' => $this->produto('Pizza Brotinho', 20.00)->id,
            'pao_valor_adicional' => 5.00,
        ]);

        return $regra;
    }

    private function itemDaPrevia(): array
    {
        return Livewire::test(PedidoProdutoSelector::class)
            ->call('selecionarProduto', $this->pizza->id)
            ->call('confirmarItem')
            ->get('itens')[0];
    }

    private function salvar(array $item, ?OpcoesEntregas $opcao = null): Testable
    {
        return Livewire::test(AtenderPedido::class)
            ->set('itensCarrinho', [$item])
            ->set('clienteData', ['semCliente' => true])
            ->set('entregaPagamentoData', [
                'opcaoEntregaId' => ($opcao ?? $this->retirada)->id,
                'pagamentos' => [['opcaoPagamentoId' => $this->dinheiro->id, 'valor' => number_format($item['valor'], 2, ',', '.'), 'trocoPara' => null]],
            ])
            ->call('save');
    }

    public function test_previa_do_pedido_novo_mostra_e_lanca_o_preco_do_gatilho(): void
    {
        $regra = $this->regra();

        $seletor = Livewire::test(PedidoProdutoSelector::class)
            ->call('selecionarProduto', $this->pizza->id)
            ->assertSet('produtoSelecionado.desconto_unit', 20.0)
            ->call('confirmarItem');

        $item = $seletor->get('itens')[0];
        $this->assertSame(39.9, $item['valor']);
        $this->assertSame($regra->id, $item['promocao_adicional_regra_id']);
    }

    public function test_pedido_novo_grava_o_preco_e_a_regra_do_gatilho(): void
    {
        $regra = $this->regra();

        $this->salvar($this->itemDaPrevia())->assertHasNoErrors();

        $gravado = ItensPedido::sole();
        $this->assertSame('39.90', $gravado->item_pedido_valor);
        $this->assertSame($regra->id, $gravado->item_pedido_promocao_adicional_regra_id);
        $this->assertSame(39.9, (float) Pedido::sole()->pedido_valor_total);
    }

    public function test_campanha_encerrada_depois_da_previa_nao_grava_o_pedido(): void
    {
        $regra = $this->regra();
        $item = $this->itemDaPrevia();
        $regra->promocao->update(['promoad_fim' => now()->subMinute()]);

        $this->salvar($item)->assertNotified('Promoção');

        $this->assertSame(0, Pedido::count());
    }

    public function test_preco_do_gatilho_respeita_a_opcao_de_entrega_mesmo_sem_brinde(): void
    {
        $comerNoLocal = OpcoesEntregas::create(['opcaoentrega_nome' => 'Comer no Local', 'opcaoentrega_valor_frete' => 0, 'opcaoentrega_min_valor_frete' => 0]);
        $this->regra(['promoad_opcoes_entrega' => [$this->retirada->id]]);

        $this->salvar($this->itemDaPrevia(), $comerNoLocal)->assertNotified('Promoção');

        $this->assertSame(0, Pedido::count());
    }

    public function test_edicao_acusa_gatilho_com_preco_de_campanha_fora_da_entrega(): void
    {
        $comerNoLocal = OpcoesEntregas::create(['opcaoentrega_nome' => 'Comer no Local', 'opcaoentrega_valor_frete' => 0, 'opcaoentrega_min_valor_frete' => 0]);
        $regra = $this->regra(['promoad_opcoes_entrega' => [$this->retirada->id]]);
        $pedido = Pedido::create(['pedido_status' => 'INICIADO', 'pedido_datahora_abertura' => now()]);

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $pedido->id])
            ->call('selecionarProduto', $this->pizza->id)
            ->call('confirmarItem');

        $this->assertSame($regra->id, ItensPedido::sole()->item_pedido_promocao_adicional_regra_id);
        $servico = app(PromocaoAdicionalService::class);
        $this->assertSame(['Dia do Cliente'], $servico->campanhasForaDaEntrega($pedido->id, $comerNoLocal->id, false));
        $this->assertSame([], $servico->campanhasForaDaEntrega($pedido->id, $this->retirada->id, false));
    }

    public function test_modal_de_sabores_mostra_o_preco_do_gatilho_na_pizza_inteira(): void
    {
        $this->categoria->update(['categoria_permite_sabores' => true]);
        $this->categoria->sincronizarQuantidadesSabores(2);
        $this->regra();

        $sabores = Livewire::test(PedidoProdutoSelector::class)
            ->call('selecionarProduto', $this->pizza->id)
            ->assertSet('saboresModalAberta', true)
            ->get('saboresProdutos');
        $calabresa = collect($sabores)->firstWhere('id', $this->pizza->id);

        // Campanha só para pizza inteira: a fração segue o preço de tabela.
        $this->assertSame(39.9, $calabresa['precoInteira']);
        $this->assertSame(59.9, $calabresa['preco']);
    }
}
