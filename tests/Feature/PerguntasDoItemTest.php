<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Livewire\PedidoProdutoSelector;
use App\Models\Categoria;
use App\Models\HorarioFuncionamento;
use App\Models\ItensPedido;
use App\Models\ItensVenda;
use App\Models\OpcoesEntregas;
use App\Models\Pedido;
use App\Models\Pergunta;
use App\Models\Produto;
use App\Models\Venda;
use App\Services\LancamentoItensVendaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Perguntas do item ("Escolha a borda", "Ponto da carne") nos canais que
 * lançam item — seletor do garçom/balcão e checkout do cardápio — e o
 * caminho da resposta congelada até a venda e a comanda.
 */
class PerguntasDoItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_pergunta_obrigatoria_sem_resposta_recusa_o_item_no_seletor(): void
    {
        $pedido = $this->pedido();
        $lanche = $this->produto('X-Burguer', 30.00);
        Pergunta::factory()->doProduto($lanche)->obrigatoria()->comOpcoes(['Mal passado' => 0, 'Ao ponto' => 0])
            ->create(['pergunta_texto' => 'Ponto da carne']);

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $pedido->id])
            ->call('selecionarProduto', $lanche->id)
            ->call('confirmarItem')
            ->assertSet('erroPromocao', 'Responda "Ponto da carne".')
            ->assertSet('modalAberta', true);

        $this->assertSame(0, ItensPedido::count());
    }

    public function test_resposta_fica_congelada_no_item_e_soma_o_valor_por_unidade(): void
    {
        $pedido = $this->pedido();
        $pizzas = $this->categoria('Pizzas');
        $calabresa = $this->produto('Calabresa', 50.00, $pizzas);
        $borda = Pergunta::factory()->daCategoria($pizzas)->obrigatoria()
            ->comOpcoes(['Sem borda' => 0, 'Catupiry' => 10.00])
            ->create(['pergunta_texto' => 'Borda']);
        $catupiry = $borda->opcoes()->where('pergunta_opcao_nome', 'Catupiry')->sole();

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $pedido->id])
            ->call('selecionarProduto', $calabresa->id)
            ->call('incrementarQuantidadeModal')
            ->call('alternarResposta', $borda->id, $catupiry->id)
            ->call('confirmarItem')
            ->assertSet('erroPromocao', null);

        $item = ItensPedido::sole();
        $this->assertSame(20.0, (float) $item->item_pedido_valor_adicionais);
        $this->assertSame(120.0, (float) $item->item_pedido_valor);
        $this->assertSame(['Borda: Catupiry'], $item->linhasRespostas());
        $this->assertSame(10.0, $item->valorUnitarioRespostas());
        $this->assertSame($borda->id, $item->respostas()[0]['pergunta_id']);
        $this->assertSame($catupiry->id, $item->respostas()[0]['opcoes'][0]['id']);
    }

    public function test_escolha_unica_troca_a_opcao_e_multipla_para_no_maximo(): void
    {
        $lanche = $this->produto('X-Burguer', 30.00);
        $ponto = Pergunta::factory()->doProduto($lanche)->comOpcoes(['Mal passado' => 0, 'Ao ponto' => 0])->create();
        $molhos = Pergunta::factory()->doProduto($lanche)->comOpcoes(['Alho' => 0, 'Barbecue' => 0, 'Mostarda' => 0])
            ->create(['pergunta_maximo' => 2]);
        [$malPassado, $aoPonto] = $ponto->opcoes->all();
        [$alho, $barbecue, $mostarda] = $molhos->opcoes->all();

        $componente = Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $this->pedido()->id])
            ->call('selecionarProduto', $lanche->id)
            ->call('alternarResposta', $ponto->id, $malPassado->id)
            ->call('alternarResposta', $ponto->id, $aoPonto->id)
            ->call('alternarResposta', $molhos->id, $alho->id)
            ->call('alternarResposta', $molhos->id, $barbecue->id)
            ->call('alternarResposta', $molhos->id, $mostarda->id);

        $this->assertSame([$aoPonto->id], $componente->get('respostas')[$ponto->id]);
        $this->assertSame([$alho->id, $barbecue->id], $componente->get('respostas')[$molhos->id]);
    }

    public function test_pergunta_da_categoria_vale_para_meia_a_meia_e_a_do_produto_nao(): void
    {
        $pedido = $this->pedido();
        $pizzas = $this->categoria('Pizzas', ['categoria_permite_sabores' => true]);
        $pizzas->sincronizarQuantidadesSabores(2);
        $calabresa = $this->produto('Calabresa', 50.00, $pizzas);
        $mussarela = $this->produto('Mussarela', 60.00, $pizzas);
        $borda = Pergunta::factory()->daCategoria($pizzas)->obrigatoria()->comOpcoes(['Cheddar' => 8.00])
            ->create(['pergunta_texto' => 'Borda']);
        Pergunta::factory()->doProduto($calabresa)->obrigatoria()->comOpcoes(['Com cebola' => 0])
            ->create(['pergunta_texto' => 'Cebola']);

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $pedido->id])
            ->call('selecionarProduto', $calabresa->id)
            ->call('setModoSabores', 2)
            ->call('toggleSabor', $calabresa->id)
            ->call('toggleSabor', $mussarela->id)
            ->assertSet('perguntasDisponiveis', fn (array $perguntas) => array_column($perguntas, 'texto') === ['Borda'])
            ->call('alternarResposta', $borda->id, $borda->opcoes->sole()->id)
            ->call('confirmarSabores')
            ->assertSet('erroPromocao', null);

        $item = ItensPedido::sole();
        $this->assertSame(63.0, (float) $item->item_pedido_valor);
        $this->assertSame(['Borda: Cheddar'], $item->linhasRespostas());
    }

    public function test_pergunta_da_categoria_mae_vale_para_a_subcategoria(): void
    {
        $pedido = $this->pedido();
        $pizzas = $this->categoria('Pizzas');
        $especiais = $this->categoria('Especiais', ['categoria_pai_id' => $pizzas->id]);
        $lombo = $this->produto('Lombo', 70.00, $especiais);
        Pergunta::factory()->daCategoria($pizzas)->obrigatoria()->comOpcoes(['Cheddar' => 8.00])
            ->create(['pergunta_texto' => 'Borda']);

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $pedido->id])
            ->call('selecionarProduto', $lombo->id)
            ->call('confirmarItem')
            ->assertSet('erroPromocao', 'Responda "Borda".');

        $this->assertSame(0, ItensPedido::count());
    }

    public function test_pergunta_inativa_nao_e_cobrada(): void
    {
        $pedido = $this->pedido();
        $lanche = $this->produto('X-Burguer', 30.00);
        Pergunta::factory()->doProduto($lanche)->obrigatoria()->comOpcoes(['Ao ponto' => 0])
            ->create(['pergunta_ativa' => false]);

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $pedido->id])
            ->call('selecionarProduto', $lanche->id)
            ->assertSet('perguntasDisponiveis', [])
            ->call('confirmarItem');

        $this->assertSame(30.0, (float) ItensPedido::sole()->item_pedido_valor);
    }

    public function test_mudar_a_quantidade_leva_o_valor_das_respostas_junto(): void
    {
        $pedido = $this->pedido();
        $lanche = $this->produto('X-Burguer', 30.00);
        $queijo = Pergunta::factory()->doProduto($lanche)->comOpcoes(['Cheddar' => 5.00])->create();

        $componente = Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $pedido->id])
            ->call('selecionarProduto', $lanche->id)
            ->call('alternarResposta', $queijo->id, $queijo->opcoes->sole()->id)
            ->call('confirmarItem');
        $componente->call('incrementarQtd', (string) ItensPedido::sole()->id);

        $item = ItensPedido::sole();
        $this->assertSame(10.0, (float) $item->item_pedido_valor_adicionais);
        $this->assertSame(70.0, (float) $item->item_pedido_valor);
    }

    public function test_checkout_sem_resposta_obrigatoria_retorna_422_sem_gravar_pedido(): void
    {
        $pizzas = $this->categoria('Pizzas');
        $calabresa = $this->produto('Calabresa', 50.00, $pizzas);
        Pergunta::factory()->daCategoria($pizzas)->obrigatoria()->comOpcoes(['Cheddar' => 8.00])
            ->create(['pergunta_texto' => 'Borda']);

        $this->checkout([['id' => $calabresa->id, 'qty' => 1]])
            ->assertUnprocessable()
            ->assertJson(['message' => 'Responda "Borda".']);

        $this->assertSame(0, Pedido::count());
    }

    public function test_checkout_grava_as_respostas_e_cobra_as_opcoes_no_total(): void
    {
        $pizzas = $this->categoria('Pizzas');
        $calabresa = $this->produto('Calabresa', 50.00, $pizzas);
        $borda = Pergunta::factory()->daCategoria($pizzas)->obrigatoria()->comOpcoes(['Cheddar' => 8.00])
            ->create(['pergunta_texto' => 'Borda']);

        $this->checkout([[
            'id' => $calabresa->id,
            'qty' => 2,
            'respostas' => [$borda->id => [$borda->opcoes->sole()->id]],
        ]])->assertOk()->assertJson(['total_final' => 116]);

        $this->assertSame(['Borda: Cheddar'], ItensPedido::sole()->linhasRespostas());
        $this->assertSame('116.00', Pedido::sole()->pedido_valor_total);
    }

    public function test_checkout_com_mais_opcoes_que_o_maximo_retorna_422(): void
    {
        $lanche = $this->produto('X-Burguer', 30.00);
        $molho = Pergunta::factory()->doProduto($lanche)->comOpcoes(['Alho' => 0, 'Barbecue' => 0])
            ->create(['pergunta_texto' => 'Molho']);

        $this->checkout([[
            'id' => $lanche->id,
            'qty' => 1,
            'respostas' => [$molho->id => $molho->opcoes->pluck('id')->all()],
        ]])
            ->assertUnprocessable()
            ->assertJson(['message' => 'Escolha só uma opção em "Molho".']);
    }

    public function test_checkout_ignora_opcao_que_nao_e_da_pergunta(): void
    {
        $lanche = $this->produto('X-Burguer', 30.00);
        $ponto = Pergunta::factory()->doProduto($lanche)->obrigatoria()->comOpcoes(['Ao ponto' => 0])
            ->create(['pergunta_texto' => 'Ponto da carne']);
        $deOutroProduto = Pergunta::factory()->doProduto($this->produto('Pastel', 12.00))->comOpcoes(['Catupiry' => 5.00])->create();

        $this->checkout([[
            'id' => $lanche->id,
            'qty' => 1,
            'respostas' => [$ponto->id => [$deOutroProduto->opcoes->sole()->id]],
        ]])
            ->assertUnprocessable()
            ->assertJson(['message' => 'Responda "Ponto da carne".']);
    }

    public function test_venda_recebe_as_respostas_sem_juntar_com_o_mesmo_produto_sem_resposta(): void
    {
        $pedido = $this->pedido();
        $lanche = $this->produto('X-Burguer', 30.00);
        $ponto = Pergunta::factory()->doProduto($lanche)->comOpcoes(['Ao ponto' => 0])->create(['pergunta_texto' => 'Ponto da carne']);
        $comResposta = $this->item($pedido, $lanche, [['pergunta_id' => $ponto->id, 'pergunta' => 'Ponto da carne', 'opcoes' => [['id' => 1, 'nome' => 'Ao ponto', 'valor' => 0.0]]]]);
        $semResposta = $this->item($pedido, $lanche);
        $venda = Venda::create(['venda_status' => 'INICIADA']);

        app(LancamentoItensVendaService::class)->lancarItens($venda, collect([$semResposta, $comResposta]));

        $linhas = ItensVenda::where('item_venda_venda_id', $venda->id)->orderBy('id')->get();
        $this->assertCount(2, $linhas);
        $this->assertSame([], $linhas[0]->linhasRespostas());
        $this->assertSame(['Ponto da carne: Ao ponto'], $linhas[1]->linhasRespostas());
    }

    public function test_comanda_imprime_as_respostas_do_item(): void
    {
        $pedido = $this->pedido();
        $this->item($pedido, $this->produto('Calabresa', 50.00), [
            ['pergunta_id' => 1, 'pergunta' => 'Borda', 'opcoes' => [['id' => 1, 'nome' => 'Catupiry', 'valor' => 10.0]]],
        ]);

        $this->get(route('pedido.imprimir', ['id' => $pedido->id]))
            ->assertOk()
            ->assertSee('Borda: Catupiry');
    }

    public function test_modal_do_seletor_mostra_a_pergunta_com_o_selo_de_obrigatoria(): void
    {
        $lanche = $this->produto('X-Burguer', 30.00);
        Pergunta::factory()->doProduto($lanche)->obrigatoria()->comOpcoes(['Ao ponto' => 0, 'Com bacon' => 4.50])
            ->create(['pergunta_texto' => 'Ponto da carne']);

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $this->pedido()->id])
            ->call('selecionarProduto', $lanche->id)
            ->assertSeeText('Ponto da carne')
            ->assertSeeText('Obrigatório')
            ->assertSeeText('+ R$ 4,50');
    }

    public function test_cardapio_entrega_as_perguntas_por_produto_e_por_categoria_de_sabores(): void
    {
        $pizzas = $this->categoria('Pizzas', ['categoria_permite_sabores' => true]);
        $pizzas->sincronizarQuantidadesSabores(2);
        $calabresa = $this->produto('Calabresa', 50.00, $pizzas);
        Pergunta::factory()->daCategoria($pizzas)->obrigatoria()->comOpcoes(['Cheddar' => 8.00])->create(['pergunta_texto' => 'Borda']);
        Pergunta::factory()->doProduto($calabresa)->comOpcoes(['Com cebola' => 0])->create(['pergunta_texto' => 'Cebola']);

        $this->get(route('cardapio'))
            ->assertOk()
            ->assertViewHas('perguntasProdutos', fn ($porProduto) => array_column($porProduto[$calabresa->id], 'texto') === ['Borda', 'Cebola'])
            ->assertViewHas('perguntasCombos', fn ($porCategoria) => array_column($porCategoria[$pizzas->id], 'texto') === ['Borda']);
    }

    private function pedido(): Pedido
    {
        return Pedido::create(['pedido_status' => 'INICIADO', 'pedido_datahora_abertura' => now()]);
    }

    /** @param  array<string, mixed>  $attrs */
    private function categoria(string $nome, array $attrs = []): Categoria
    {
        return Categoria::create(['categoria_nome' => $nome, 'categoria_cardapio' => true] + $attrs);
    }

    private function produto(string $nome, float $preco, ?Categoria $categoria = null): Produto
    {
        return Produto::create([
            'produto_descricao' => $nome,
            'produto_categoria_id' => ($categoria ?? Categoria::firstOrCreate(['categoria_nome' => 'Lanches'], ['categoria_cardapio' => true]))->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => $preco,
            'produto_cardapio' => true,
        ]);
    }

    /** @param  ?list<array<string, mixed>>  $respostas */
    private function item(Pedido $pedido, Produto $produto, ?array $respostas = null): ItensPedido
    {
        return ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $produto->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => $produto->produto_preco_venda,
            'item_pedido_valor' => $produto->produto_preco_venda,
            'item_pedido_desconto' => 0,
            'item_pedido_valor_adicionais' => 0,
            'item_pedido_respostas' => $respostas,
            'item_pedido_status' => 'INSERIDO',
        ]);
    }

    /** @param  array<int, array<string, mixed>>  $itens */
    private function checkout(array $itens): TestResponse
    {
        HorarioFuncionamento::create([
            'horario_dia_semana' => now()->dayOfWeek,
            'horario_ativo' => true,
            'horario_abertura' => '00:00:00',
            'horario_fechamento' => '23:59:59',
        ]);

        return $this->postJson(route('cardapio.checkout'), [
            'nome' => 'Cliente Teste',
            'telefone' => '11999998888',
            'opcao_entrega_id' => OpcoesEntregas::create(['opcaoentrega_nome' => 'Retirada', 'opcaoentrega_valor_frete' => 0, 'opcaoentrega_min_valor_frete' => 0])->id,
            'pagamento_nome' => 'Dinheiro',
            'itens' => $itens,
        ]);
    }
}
