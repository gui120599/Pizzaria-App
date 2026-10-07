<?php

namespace Tests\Feature;

use App\Enums\EstoqueModoControleEnum;
use App\Enums\ProdutoTipoEnum;
use App\Livewire\PedidoProdutoSelector;
use App\Models\AdicionaisItemPedido;
use App\Models\AdicionaisProduto;
use App\Models\Adicional;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\Pedido;
use App\Models\Produto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Caracterização do lançamento de item pelo seletor (garçom, balcão, mesa)
 * antes de a regra ir para um service compartilhado: o que é gravado em
 * itens_pedidos/adicionais_itens_pedidos em cada caminho de confirmação.
 * Promoção relâmpago, oferta e estoque do item simples já têm testes próprios
 * (PedidoProdutoSelectorPreco/PromocaoAdicional/EstoqueTest).
 */
class PedidoProdutoSelectorLancamentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_item_simples_grava_a_linha_com_o_preco_do_produto_e_a_observacao(): void
    {
        $pedido = $this->pedido();
        $refrigerante = $this->produto('Refrigerante', 8.50);

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $pedido->id])
            ->call('selecionarProduto', $refrigerante->id)
            ->call('incrementarQuantidadeModal')
            ->set('observacao', 'Bem gelado')
            ->call('confirmarItem')
            ->assertSet('modalAberta', false)
            ->assertDispatched('itens-pedido-atualizados');

        $item = ItensPedido::sole();
        $this->assertSame($pedido->id, $item->item_pedido_pedido_id);
        $this->assertSame($refrigerante->id, $item->item_pedido_produto_id);
        $this->assertSame(2.0, (float) $item->item_pedido_quantidade);
        $this->assertSame(8.5, (float) $item->item_pedido_valor_unitario);
        $this->assertSame(0.0, (float) $item->item_pedido_desconto);
        $this->assertSame(0.0, (float) $item->item_pedido_valor_adicionais);
        $this->assertSame(17.0, (float) $item->item_pedido_valor);
        $this->assertSame('Bem gelado', $item->item_pedido_observacao);
        $this->assertSame('INSERIDO', $item->item_pedido_status);
        $this->assertNull($item->item_pedido_cliente_id);
        $this->assertNull($item->item_pedido_sabores);
    }

    public function test_adicionais_escolhidos_viram_linhas_proprias_cobradas_por_unidade_do_item(): void
    {
        $pedido = $this->pedido();
        $lanche = $this->produto('X-Burguer', 30.00);
        $bacon = $this->adicionalDoProduto($lanche, 'Bacon', 6.00);
        $cheddar = $this->adicionalDoProduto($lanche, 'Cheddar', 4.00);

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $pedido->id])
            ->call('selecionarProduto', $lanche->id)
            ->call('incrementarQuantidadeModal')
            ->call('toggleAdicional', $bacon->id)
            ->call('toggleAdicional', $cheddar->id)
            ->call('confirmarItem');

        $item = ItensPedido::sole();
        $this->assertSame(20.0, (float) $item->item_pedido_valor_adicionais);
        $this->assertSame(80.0, (float) $item->item_pedido_valor);

        $adicionais = AdicionaisItemPedido::where('aip_item_pedido_id', $item->id)->orderBy('aip_adicional_id')->get();
        $this->assertSame([$bacon->id, $cheddar->id], $adicionais->pluck('aip_adicional_id')->all());
        $this->assertSame([2.0, 2.0], $adicionais->map(fn ($a) => (float) $a->aip_quantidade)->all());
        $this->assertSame([12.0, 8.0], $adicionais->map(fn ($a) => (float) $a->aip_valor_total)->all());
    }

    public function test_meia_porcao_leva_o_adicional_inteiro(): void
    {
        $pedido = $this->pedido();
        $porcao = $this->produto('Porção de Fritas', 30.00);
        $cheddar = $this->adicionalDoProduto($porcao, 'Cheddar', 6.00);

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $pedido->id])
            ->call('selecionarProduto', $porcao->id)
            ->call('setQuantidadeModal', 0.5)
            ->call('toggleAdicional', $cheddar->id)
            ->call('confirmarItem');

        $item = ItensPedido::sole();
        $this->assertSame(21.0, (float) $item->item_pedido_valor);
        $this->assertSame(1.0, (float) AdicionaisItemPedido::sole()->aip_quantidade);
    }

    public function test_mudar_a_quantidade_do_item_gravado_leva_os_adicionais_junto(): void
    {
        $pedido = $this->pedido();
        $lanche = $this->produto('X-Burguer', 30.00);
        $bacon = $this->adicionalDoProduto($lanche, 'Bacon', 6.00);

        $componente = Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $pedido->id])
            ->call('selecionarProduto', $lanche->id)
            ->call('toggleAdicional', $bacon->id)
            ->call('confirmarItem');
        $componente->call('incrementarQtd', (string) ItensPedido::sole()->id);

        $item = ItensPedido::sole();
        $this->assertSame(2.0, (float) $item->item_pedido_quantidade);
        $this->assertSame(12.0, (float) $item->item_pedido_valor_adicionais);
        $this->assertSame(72.0, (float) $item->item_pedido_valor);
        $this->assertSame(12.0, (float) AdicionaisItemPedido::sole()->aip_valor_total);
        $this->assertSame(72.0, (float) $componente->get('itens')[0]['valor']);
    }

    public function test_editar_adicionais_do_item_gravado_cobra_por_unidade_e_mantem_o_preco_da_linha(): void
    {
        $pedido = $this->pedido();
        $lanche = $this->produto('X-Burguer', 30.00);
        $bacon = $this->adicionalDoProduto($lanche, 'Bacon', 6.00);

        $componente = Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $pedido->id])
            ->call('selecionarProduto', $lanche->id)
            ->call('incrementarQuantidadeModal')
            ->call('confirmarItem');
        $lanche->update(['produto_preco_venda' => 99.00]);
        $componente
            ->call('abrirEditModal', (string) ItensPedido::sole()->id)
            ->call('toggleEditAdicional', $bacon->id)
            ->set('editObservacao', 'Bacon crocante')
            ->call('salvarEdicaoItem');

        $item = ItensPedido::sole();
        $this->assertSame(12.0, (float) $item->item_pedido_valor_adicionais);
        $this->assertSame(72.0, (float) $item->item_pedido_valor);
        $this->assertSame('Bacon crocante', $item->item_pedido_observacao);
        $this->assertSame(2.0, (float) AdicionaisItemPedido::sole()->aip_quantidade);
    }

    public function test_previa_sem_pedido_cobra_adicional_por_unidade_ao_mudar_a_quantidade(): void
    {
        $lanche = $this->produto('X-Burguer', 30.00);
        $bacon = $this->adicionalDoProduto($lanche, 'Bacon', 6.00);

        $componente = Livewire::test(PedidoProdutoSelector::class)
            ->call('selecionarProduto', $lanche->id)
            ->call('toggleAdicional', $bacon->id)
            ->call('confirmarItem');
        $componente->call('incrementarQtd', $componente->get('itens')[0]['id']);

        $this->assertSame(12.0, $componente->get('itens')[0]['adicionais_valor']);
        $this->assertSame(72.0, $componente->get('itens')[0]['valor']);
        $this->assertSame(0, ItensPedido::count());
    }

    public function test_produto_fora_do_cardapio_do_garcom_nao_e_lancado(): void
    {
        $pedido = $this->pedido();
        $oculto = $this->produto('Insumo de Cozinha', 5.00, attrs: ['produto_cardapio_garcom' => false]);

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $pedido->id])
            ->call('selecionarProduto', $oculto->id)
            ->call('confirmarItem')
            ->assertSet('erroPromocao', 'Insumo de Cozinha não está disponível no momento.')
            ->assertSet('modalAberta', true);

        $this->assertSame(0, ItensPedido::count());
    }

    public function test_desmarcar_adicional_tira_o_valor_dele_do_item(): void
    {
        $pedido = $this->pedido();
        $lanche = $this->produto('X-Burguer', 30.00);
        $bacon = $this->adicionalDoProduto($lanche, 'Bacon', 6.00);

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $pedido->id])
            ->call('selecionarProduto', $lanche->id)
            ->call('toggleAdicional', $bacon->id)
            ->call('toggleAdicional', $bacon->id)
            ->call('confirmarItem');

        $this->assertSame(30.0, (float) ItensPedido::sole()->item_pedido_valor);
        $this->assertSame(0, AdicionaisItemPedido::count());
    }

    public function test_adicional_que_nao_pertence_ao_produto_e_ignorado(): void
    {
        $pedido = $this->pedido();
        $lanche = $this->produto('X-Burguer', 30.00);
        $deOutroProduto = $this->adicionalDoProduto($this->produto('Pastel', 12.00), 'Catupiry', 5.00);

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $pedido->id])
            ->call('selecionarProduto', $lanche->id)
            ->call('toggleAdicional', $deOutroProduto->id)
            ->call('confirmarItem');

        $this->assertSame(30.0, (float) ItensPedido::sole()->item_pedido_valor);
        $this->assertSame(0, AdicionaisItemPedido::count());
    }

    public function test_adicional_desvinculado_do_produto_nao_e_oferecido(): void
    {
        $lanche = $this->produto('X-Burguer', 30.00);
        $this->adicionalDoProduto($lanche, 'Bacon', 6.00);
        AdicionaisProduto::where('ap_produto_id', $lanche->id)->delete();

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $this->pedido()->id])
            ->call('selecionarProduto', $lanche->id)
            ->assertSet('adicionaisDisponiveis', []);
    }

    public function test_quantidade_fracionada_cobra_o_preco_proporcional(): void
    {
        $pedido = $this->pedido();
        $porcao = $this->produto('Porção de Fritas', 30.00);

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $pedido->id])
            ->call('selecionarProduto', $porcao->id)
            ->call('setQuantidadeModal', 0.5)
            ->call('confirmarItem');

        $item = ItensPedido::sole();
        $this->assertSame(0.5, (float) $item->item_pedido_quantidade);
        $this->assertSame(15.0, (float) $item->item_pedido_valor);
    }

    public function test_meia_a_meia_sem_saldo_de_um_sabor_em_modo_bloquear_nao_grava_nada(): void
    {
        $pedido = $this->pedido();
        $categoria = $this->categoriaDePizzas();
        $calabresa = $this->produto('Calabresa', 50.00, $categoria, $this->estoqueBloqueado(0.4));
        $mussarela = $this->produto('Mussarela', 60.00, $categoria);

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $pedido->id])
            ->call('selecionarProduto', $calabresa->id)
            ->call('setModoSabores', 2)
            ->call('toggleSabor', $calabresa->id)
            ->call('toggleSabor', $mussarela->id)
            ->call('confirmarSabores')
            ->assertSet('erroEstoque', fn (?string $mensagem) => str_contains((string) $mensagem, 'Calabresa'))
            ->assertSet('saboresModalAberta', true);

        $this->assertSame(0, ItensPedido::count());
    }

    public function test_meia_a_meia_com_saldo_para_a_metade_do_sabor_e_aceita(): void
    {
        $pedido = $this->pedido();
        $categoria = $this->categoriaDePizzas();
        $calabresa = $this->produto('Calabresa', 50.00, $categoria, $this->estoqueBloqueado(0.5));
        $mussarela = $this->produto('Mussarela', 60.00, $categoria);

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $pedido->id])
            ->call('selecionarProduto', $calabresa->id)
            ->call('setModoSabores', 2)
            ->call('toggleSabor', $calabresa->id)
            ->call('toggleSabor', $mussarela->id)
            ->call('confirmarSabores')
            ->assertSet('erroEstoque', null);

        $item = ItensPedido::sole();
        $this->assertSame(55.0, (float) $item->item_pedido_valor);
        $this->assertSame(0.0, (float) $item->item_pedido_valor_adicionais);
    }

    public function test_sem_pedido_gravado_confirmar_item_so_monta_a_previa(): void
    {
        $refrigerante = $this->produto('Refrigerante', 8.50);

        $componente = Livewire::test(PedidoProdutoSelector::class)
            ->call('selecionarProduto', $refrigerante->id)
            ->call('confirmarItem');

        $this->assertSame(0, ItensPedido::count());
        $this->assertStringStartsWith('tmp_', $componente->get('itens')[0]['id']);
        $this->assertSame(8.5, (float) $componente->get('itens')[0]['valor']);
    }

    private function pedido(): Pedido
    {
        return Pedido::create([
            'pedido_status' => 'INICIADO',
            'pedido_datahora_abertura' => now(),
        ]);
    }

    private function categoriaDePizzas(): Categoria
    {
        $categoria = Categoria::create(['categoria_nome' => 'Pizzas', 'categoria_permite_sabores' => true]);
        $categoria->sincronizarQuantidadesSabores(2);

        return $categoria;
    }

    /** @return array<string, mixed> */
    private function estoqueBloqueado(float $saldo): array
    {
        return [
            'produto_controla_estoque' => true,
            'produto_modo_controle_estoque' => EstoqueModoControleEnum::BLOQUEAR,
            'produto_saldo_estoque' => $saldo,
        ];
    }

    /** @param  array<string, mixed>  $attrs */
    private function produto(string $nome, float $preco, ?Categoria $categoria = null, array $attrs = []): Produto
    {
        $categoria ??= Categoria::firstOrCreate(['categoria_nome' => 'Diversos']);

        return Produto::create(array_merge([
            'produto_descricao' => $nome,
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => $preco,
            'produto_cardapio' => true,
        ], $attrs));
    }

    private function adicionalDoProduto(Produto $produto, string $nome, float $valor): Adicional
    {
        $adicional = Adicional::create(['adicional_nome' => $nome, 'adicional_valor' => $valor]);
        AdicionaisProduto::create(['ap_adicional_id' => $adicional->id, 'ap_produto_id' => $produto->id]);

        return $adicional;
    }
}
