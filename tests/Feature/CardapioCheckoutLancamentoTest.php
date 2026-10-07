<?php

namespace Tests\Feature;

use App\Enums\EstoqueModoControleEnum;
use App\Enums\PedidoOrigemEnum;
use App\Enums\ProdutoTipoEnum;
use App\Models\AdicionaisItemPedido;
use App\Models\AdicionaisProduto;
use App\Models\Adicional;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\HorarioFuncionamento;
use App\Models\ItensPedido;
use App\Models\OpcoesEntregas;
use App\Models\Pedido;
use App\Models\Produto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Caracterização do checkout do cardápio público antes de a montagem das
 * linhas ir para um service compartilhado: pedido gravado, frete, horário e
 * estoque. Preço do servidor, promoções, cupom e sabores já têm testes
 * próprios (CardapioCheckoutPrecoServidor/PromocaoAdicional/CupomTest,
 * PizzaSaboresLinhaUnicaTest).
 */
class CardapioCheckoutLancamentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_grava_pedido_iniciado_do_cardapio_com_totais_e_observacao_das_linhas(): void
    {
        $this->abertoAgora();
        $pizza = $this->produto('Calabresa', 55.00);
        $refrigerante = $this->produto('Refrigerante', 8.00);

        $resposta = $this->checkout($this->entrega(), [
            ['id' => $pizza->id, 'qty' => 2, 'observacao' => 'Sem cebola'],
            ['id' => $refrigerante->id, 'qty' => 1],
        ]);

        $pedido = Pedido::sole();
        $resposta->assertOk()->assertJson(['pedido_id' => $pedido->id, 'total_final' => 118]);

        $this->assertSame('INICIADO', $pedido->pedido_status);
        $this->assertSame(PedidoOrigemEnum::CARDAPIO, $pedido->pedido_origem);
        $this->assertSame('118.00', $pedido->pedido_valor_itens);
        $this->assertSame('0.00', $pedido->pedido_valor_desconto);
        $this->assertSame('118.00', $pedido->pedido_valor_total);
        $this->assertSame('Dinheiro', $pedido->pedido_descricao_pagamento);
        $this->assertSame('11999998888', Cliente::findOrFail($pedido->pedido_cliente_id)->cliente_celular);

        $itens = ItensPedido::where('item_pedido_pedido_id', $pedido->id)->orderBy('id')->get();
        $this->assertSame([110.0, 8.0], $itens->map(fn ($i) => (float) $i->item_pedido_valor)->all());
        $this->assertSame(['Sem cebola', null], $itens->pluck('item_pedido_observacao')->all());
        $this->assertSame(['INSERIDO', 'INSERIDO'], $itens->pluck('item_pedido_status')->all());
    }

    /** @return array<string, array{float, float}> */
    public static function itensEFreteEsperado(): array
    {
        return [
            'abaixo do mínimo cobra o frete' => [55.00, 8.00],
            'no mínimo fica isento' => [100.00, 0.00],
        ];
    }

    #[DataProvider('itensEFreteEsperado')]
    public function test_frete_e_cobrado_so_abaixo_do_valor_minimo(float $precoPizza, float $freteEsperado): void
    {
        $this->abertoAgora();
        $pizza = $this->produto('Calabresa', $precoPizza);
        $delivery = $this->entrega(['opcaoentrega_valor_frete' => 8.00, 'opcaoentrega_min_valor_frete' => 100.00]);

        $this->checkout($delivery, [['id' => $pizza->id, 'qty' => 1]])
            ->assertOk()
            ->assertJson(['valor_frete' => $freteEsperado, 'total_final' => $precoPizza + $freteEsperado]);

        $this->assertSame($freteEsperado, (float) Pedido::sole()->pedido_valor_frete);
    }

    public function test_fora_do_horario_retorna_422_sem_gravar_pedido(): void
    {
        $pizza = $this->produto('Calabresa', 55.00);

        $this->checkout($this->entrega(), [['id' => $pizza->id, 'qty' => 1]])
            ->assertUnprocessable()
            ->assertJson(['message' => 'Não estamos aceitando pedidos no momento.']);

        $this->assertSame(0, Pedido::count());
    }

    public function test_estoque_bloqueado_e_somado_entre_linhas_do_mesmo_produto_e_retorna_422(): void
    {
        $this->abertoAgora();
        $pizza = $this->produto('Calabresa', 55.00, [
            'produto_controla_estoque' => true,
            'produto_modo_controle_estoque' => EstoqueModoControleEnum::BLOQUEAR,
            'produto_saldo_estoque' => 1,
        ]);

        $this->checkout($this->entrega(), [
            ['id' => $pizza->id, 'qty' => 1],
            ['id' => $pizza->id, 'qty' => 1, 'observacao' => 'Bem assada'],
        ])
            ->assertUnprocessable()
            ->assertJson(fn ($json) => $json->where('message', fn (string $mensagem) => str_starts_with($mensagem, 'Item sem estoque suficiente: Calabresa')));

        $this->assertSame(0, Pedido::count());
        $this->assertSame(0, ItensPedido::count());
    }

    /** @return array<string, array{array<string, mixed>, array<string, mixed>}> */
    public static function produtosQueOCardapioNaoMostra(): array
    {
        return [
            'produto fora do cardápio' => [['produto_cardapio' => false], ['categoria_cardapio' => true]],
            'categoria fora do cardápio' => [[], ['categoria_cardapio' => false]],
            'estoque zerado sem listar zerado' => [
                ['produto_controla_estoque' => true, 'produto_saldo_estoque' => 0, 'produto_lista_estoque_zerado' => false],
                ['categoria_cardapio' => true],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $produtoAttrs
     * @param  array<string, mixed>  $categoriaAttrs
     */
    #[DataProvider('produtosQueOCardapioNaoMostra')]
    public function test_produto_que_o_cardapio_nao_mostra_retorna_422_sem_gravar_pedido(array $produtoAttrs, array $categoriaAttrs): void
    {
        $this->abertoAgora();
        $categoria = Categoria::create(['categoria_nome' => 'Bebidas'] + $categoriaAttrs);
        $oculto = $this->produto('Cerveja', 12.00, ['produto_categoria_id' => $categoria->id] + $produtoAttrs);

        $this->checkout($this->entrega(), [['id' => $oculto->id, 'qty' => 1]])
            ->assertUnprocessable()
            ->assertJson(['message' => 'Cerveja não está disponível no momento.']);

        $this->assertSame(0, Pedido::count());
    }

    public function test_produto_de_subcategoria_com_a_mae_fora_do_cardapio_retorna_422(): void
    {
        $this->abertoAgora();
        $mae = Categoria::create(['categoria_nome' => 'Bebidas', 'categoria_cardapio' => false]);
        $filha = Categoria::create(['categoria_nome' => 'Cervejas', 'categoria_pai_id' => $mae->id, 'categoria_cardapio' => true]);
        $cerveja = $this->produto('Cerveja', 12.00, ['produto_categoria_id' => $filha->id]);

        $this->checkout($this->entrega(), [['id' => $cerveja->id, 'qty' => 1]])
            ->assertUnprocessable()
            ->assertJson(['message' => 'Cerveja não está disponível no momento.']);

        $this->assertSame(0, Pedido::count());
    }

    public function test_meia_a_meia_com_um_sabor_oculto_retorna_422(): void
    {
        $this->abertoAgora();
        $pizzas = Categoria::create(['categoria_nome' => 'Pizzas', 'categoria_cardapio' => true, 'categoria_permite_sabores' => true]);
        $pizzas->sincronizarQuantidadesSabores(2);
        $calabresa = $this->produto('Calabresa', 50.00, ['produto_categoria_id' => $pizzas->id]);
        $especial = $this->produto('Especial da Casa', 70.00, ['produto_categoria_id' => $pizzas->id, 'produto_cardapio' => false]);

        $this->checkout($this->entrega(), [[
            'id' => $calabresa->id,
            'qty' => 1,
            'sabores' => [['id' => $calabresa->id], ['id' => $especial->id]],
        ]])
            ->assertUnprocessable()
            ->assertJson(['message' => 'Especial da Casa não está disponível no momento.']);

        $this->assertSame(0, Pedido::count());
    }

    public function test_adicionais_escolhidos_sao_cobrados_por_unidade_com_o_valor_do_cadastro(): void
    {
        $this->abertoAgora();
        $lanche = $this->produto('X-Burguer', 30.00);
        $bacon = $this->adicionalDoProduto($lanche, 'Bacon', 6.00);

        $this->checkout($this->entrega(), [['id' => $lanche->id, 'qty' => 2, 'adicionais' => [$bacon->id]]])
            ->assertOk()
            ->assertJson(['total_final' => 72]);

        $item = ItensPedido::sole();
        $this->assertSame(12.0, (float) $item->item_pedido_valor_adicionais);
        $this->assertSame(72.0, (float) $item->item_pedido_valor);
        $this->assertSame(2.0, (float) AdicionaisItemPedido::sole()->aip_quantidade);
    }

    public function test_adicional_que_nao_e_do_produto_e_ignorado_no_checkout(): void
    {
        $this->abertoAgora();
        $lanche = $this->produto('X-Burguer', 30.00);
        $deOutroProduto = $this->adicionalDoProduto($this->produto('Pastel', 12.00), 'Catupiry', 5.00);

        $this->checkout($this->entrega(), [['id' => $lanche->id, 'qty' => 1, 'adicionais' => [$deOutroProduto->id]]])
            ->assertOk()
            ->assertJson(['total_final' => 30]);

        $this->assertSame(0, AdicionaisItemPedido::count());
    }

    public function test_meia_a_meia_so_leva_o_adicional_vinculado_a_todos_os_sabores(): void
    {
        $this->abertoAgora();
        $pizzas = Categoria::create(['categoria_nome' => 'Pizzas', 'categoria_cardapio' => true, 'categoria_permite_sabores' => true]);
        $pizzas->sincronizarQuantidadesSabores(2);
        $calabresa = $this->produto('Calabresa', 50.00, ['produto_categoria_id' => $pizzas->id]);
        $mussarela = $this->produto('Mussarela', 60.00, ['produto_categoria_id' => $pizzas->id]);
        $catupiry = $this->adicionalDoProduto($calabresa, 'Catupiry', 8.00);
        AdicionaisProduto::create(['ap_adicional_id' => $catupiry->id, 'ap_produto_id' => $mussarela->id]);
        $soDaCalabresa = $this->adicionalDoProduto($calabresa, 'Cebola extra', 3.00);

        $this->checkout($this->entrega(), [[
            'id' => $calabresa->id,
            'qty' => 1,
            'sabores' => [['id' => $calabresa->id], ['id' => $mussarela->id]],
            'adicionais' => [$catupiry->id, $soDaCalabresa->id],
        ]])->assertOk()->assertJson(['total_final' => 63]);

        $this->assertSame([$catupiry->id], AdicionaisItemPedido::pluck('aip_adicional_id')->all());
    }

    public function test_cardapio_entrega_os_adicionais_de_cada_produto(): void
    {
        $lanche = $this->produto('X-Burguer', 30.00);
        $bacon = $this->adicionalDoProduto($lanche, 'Bacon', 6.00);

        $this->get(route('cardapio'))
            ->assertOk()
            ->assertViewHas('adicionaisProdutos', fn ($porProduto) => $porProduto[$lanche->id] === [['id' => $bacon->id, 'nome' => 'Bacon', 'valor' => 6.0]]);
    }

    private function adicionalDoProduto(Produto $produto, string $nome, float $valor): Adicional
    {
        $adicional = Adicional::create(['adicional_nome' => $nome, 'adicional_valor' => $valor]);
        AdicionaisProduto::create(['ap_adicional_id' => $adicional->id, 'ap_produto_id' => $produto->id]);

        return $adicional;
    }

    private function abertoAgora(): void
    {
        HorarioFuncionamento::create([
            'horario_dia_semana' => now()->dayOfWeek,
            'horario_ativo' => true,
            'horario_abertura' => '00:00:00',
            'horario_fechamento' => '23:59:59',
        ]);
    }

    /** @param  array<string, mixed>  $attrs */
    private function entrega(array $attrs = []): OpcoesEntregas
    {
        return OpcoesEntregas::create(array_merge([
            'opcaoentrega_nome' => 'Retirada',
            'opcaoentrega_valor_frete' => 0,
            'opcaoentrega_min_valor_frete' => 0,
        ], $attrs));
    }

    /** @param  array<string, mixed>  $attrs */
    private function produto(string $nome, float $preco, array $attrs = []): Produto
    {
        return Produto::create(array_merge([
            'produto_descricao' => $nome,
            'produto_categoria_id' => Categoria::firstOrCreate(['categoria_nome' => 'Cardápio'], ['categoria_cardapio' => true])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => $preco,
            'produto_cardapio' => true,
        ], $attrs));
    }

    /** @param  array<int, array<string, mixed>>  $itens */
    private function checkout(OpcoesEntregas $entrega, array $itens): TestResponse
    {
        return $this->postJson(route('cardapio.checkout'), [
            'nome' => 'Cliente Teste',
            'telefone' => '11999998888',
            'opcao_entrega_id' => $entrega->id,
            'pagamento_nome' => 'Dinheiro',
            'itens' => $itens,
        ]);
    }
}
