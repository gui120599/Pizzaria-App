<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Livewire\PedidoProdutoSelector;
use App\Models\Categoria;
use App\Models\HorarioFuncionamento;
use App\Models\ItensPedido;
use App\Models\OpcoesEntregas;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\PromocaoAdicional;
use App\Models\PromocaoAdicionalOferta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pizza de sabores ganha o produto oferecido quando todos os sabores são
 * gatilho da mesma campanha e ela marca "Vale também para meia a meia"
 * (promoad_aplica_fracionado). Sem isso, segue só pizza inteira.
 */
class PromocaoAdicionalMeiaAMeiaTest extends TestCase
{
    use RefreshDatabase;

    private Produto $calabresa;

    private Produto $frango;

    private Produto $portuguesa;

    private Produto $refri;

    private OpcoesEntregas $retirada;

    protected function setUp(): void
    {
        parent::setUp();

        HorarioFuncionamento::create([
            'horario_dia_semana' => now()->dayOfWeek,
            'horario_ativo' => true,
            'horario_abertura' => '00:00:00',
            'horario_fechamento' => '23:59:59',
        ]);

        $grande = Categoria::create(['categoria_nome' => 'Pizza Grande', 'categoria_cardapio' => true, 'categoria_permite_sabores' => true]);
        $grande->sincronizarQuantidadesSabores(2);
        $bebidas = Categoria::create(['categoria_nome' => 'Refrigerante', 'categoria_cardapio' => true]);

        $this->calabresa = $this->produto('Calabresa', $grande, 59.90);
        $this->frango = $this->produto('Frango', $grande, 62.90);
        $this->portuguesa = $this->produto('Portuguesa', $grande, 64.90);
        $this->refri = $this->produto('Guaraná Kuat 2L', $bebidas, 12.00);

        $this->retirada = OpcoesEntregas::create([
            'opcaoentrega_nome' => 'Retirada',
            'opcaoentrega_valor_frete' => 0,
            'opcaoentrega_min_valor_frete' => 0,
        ]);
    }

    private function produto(string $nome, Categoria $categoria, float $preco): Produto
    {
        return Produto::create([
            'produto_descricao' => $nome,
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => $preco,
            'produto_cardapio' => true,
        ]);
    }

    /** Calabresa e Frango são gatilho (refri grátis); Portuguesa não. */
    private function campanha(bool $valeMeiaAMeia, ?int $qtdTotal = 20): PromocaoAdicional
    {
        $promocao = PromocaoAdicional::create([
            'promoad_nome' => 'Pizza Grande + Kuat',
            'promoad_ativa' => true,
            'promoad_inicio' => now()->subHour(),
            'promoad_fim' => now()->addHour(),
            'promoad_aplica_fracionado' => $valeMeiaAMeia,
            'promoad_qtd_total' => $qtdTotal,
        ]);

        foreach ([$this->calabresa, $this->frango] as $pizza) {
            $promocao->regras()->create(['par_produto_gatilho_id' => $pizza->id])
                ->ofertas()->create(['pao_produto_oferta_id' => $this->refri->id, 'pao_valor_adicional' => 0]);
        }

        return $promocao;
    }

    private function checkoutMeiaAMeia(Produto $primeiro, Produto $segundo): TestResponse
    {
        return $this->postJson(route('cardapio.checkout'), [
            'nome' => 'Cliente Teste',
            'telefone' => '11999998888',
            'opcao_entrega_id' => $this->retirada->id,
            'pagamento_nome' => 'Dinheiro',
            'itens' => [[
                'id' => $primeiro->id,
                'qty' => 1,
                'oferta_produto_id' => $this->refri->id,
                'sabores' => [['id' => $primeiro->id], ['id' => $segundo->id]],
            ]],
        ]);
    }

    public function test_meia_a_meia_de_gatilhos_ganha_o_brinde_e_consome_o_teto(): void
    {
        $promocao = $this->campanha(valeMeiaAMeia: true);

        $this->checkoutMeiaAMeia($this->calabresa, $this->frango)
            ->assertOk()
            ->assertJsonPath('itens_sem_brinde', []);

        $pizza = ItensPedido::whereNotNull('item_pedido_sabores')->sole();
        $brinde = ItensPedido::where('item_pedido_produto_id', $this->refri->id)->sole();

        $this->assertSame($pizza->id, $brinde->item_pedido_origem_id);
        $this->assertTrue((bool) $brinde->item_pedido_cortesia);
        $this->assertSame(1.0, (float) $promocao->fresh()->promoad_qtd_vendida);
    }

    public function test_campanha_que_nao_vale_para_meia_a_meia_nao_da_brinde_na_pizza_de_sabores(): void
    {
        $promocao = $this->campanha(valeMeiaAMeia: false);

        $this->checkoutMeiaAMeia($this->calabresa, $this->frango)->assertOk();

        $this->assertSame(0, ItensPedido::where('item_pedido_produto_id', $this->refri->id)->count());
        $this->assertSame(0.0, (float) $promocao->fresh()->promoad_qtd_vendida);
    }

    public function test_meia_a_meia_com_sabor_que_nao_e_gatilho_nao_da_brinde(): void
    {
        $this->campanha(valeMeiaAMeia: true);

        $this->checkoutMeiaAMeia($this->calabresa, $this->portuguesa)->assertOk();

        $this->assertSame(0, ItensPedido::where('item_pedido_produto_id', $this->refri->id)->count());
    }

    public function test_cardapio_informa_que_a_campanha_vale_para_meia_a_meia(): void
    {
        $this->campanha(valeMeiaAMeia: true);

        $payload = $this->get(route('cardapio'))->assertOk()->viewData('promocoesAdicionais');

        $this->assertTrue($payload[$this->calabresa->id]['aplicaFracionado']);
    }

    public function test_balcao_oferece_e_grava_o_brinde_na_meia_a_meia_completa(): void
    {
        $promocao = $this->campanha(valeMeiaAMeia: true);
        $oferta = PromocaoAdicionalOferta::firstOrFail();
        $pedido = Pedido::create(['pedido_status' => 'INICIADO', 'pedido_datahora_abertura' => now()]);
        $ids = [$this->calabresa->id, $this->frango->id];

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $pedido->id])
            ->call('selecionarProduto', $this->calabresa->id)
            ->call('setModoSabores', 2)
            ->call('sincronizarSabores', [$this->calabresa->id])
            ->assertSet('ofertasDisponiveis', [])
            ->call('sincronizarSabores', $ids)
            ->assertCount('ofertasDisponiveis', 1)
            ->call('selecionarOferta', $oferta->id)
            ->call('confirmarSabores', $ids, []);

        $pizza = ItensPedido::whereNotNull('item_pedido_sabores')->sole();
        $brinde = ItensPedido::where('item_pedido_produto_id', $this->refri->id)->sole();

        $this->assertSame($pizza->id, $brinde->item_pedido_origem_id);
        $this->assertSame(1.0, (float) $promocao->fresh()->promoad_qtd_vendida);
    }

    public function test_balcao_nao_oferece_na_meia_a_meia_com_sabor_que_nao_e_gatilho(): void
    {
        $this->campanha(valeMeiaAMeia: true);
        $pedido = Pedido::create(['pedido_status' => 'INICIADO', 'pedido_datahora_abertura' => now()]);

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $pedido->id])
            ->call('selecionarProduto', $this->calabresa->id)
            ->call('setModoSabores', 2)
            ->call('sincronizarSabores', [$this->calabresa->id, $this->portuguesa->id])
            ->assertSet('ofertasDisponiveis', []);
    }
}
