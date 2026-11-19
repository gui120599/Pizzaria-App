<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Enums\TipoDescontoEnum;
use App\Models\Categoria;
use App\Models\Cupom;
use App\Models\HorarioFuncionamento;
use App\Models\ItensPedido;
use App\Models\OpcoesEntregas;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\PromocaoCombo;
use App\Services\CardapioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Promoção Combo no cardápio web: prévia da sacola calculada no servidor,
 * convite com os produtos participantes e checkout com o combo nas linhas.
 */
class CardapioPromocaoComboTest extends TestCase
{
    use RefreshDatabase;

    private Categoria $grande;

    private Produto $calabresa;

    private Produto $frango;

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

        $this->grande = Categoria::create(['categoria_nome' => 'Pizza Grande', 'categoria_cardapio' => true]);
        $this->calabresa = $this->produto('Calabresa', 59.90);
        $this->frango = $this->produto('Frango', 50.10);
        $this->retirada = OpcoesEntregas::create(['opcaoentrega_nome' => 'Retirada', 'opcaoentrega_valor_frete' => 0, 'opcaoentrega_min_valor_frete' => 0, 'opcaoentrega_requer_endereco' => false]);
    }

    private function produto(string $nome, float $preco, ?Categoria $categoria = null): Produto
    {
        return Produto::create([
            'produto_descricao' => $nome,
            'produto_categoria_id' => ($categoria ?? $this->grande)->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => $preco,
            'produto_cardapio' => true,
        ]);
    }

    /** @param  list<Produto>  $produtos */
    private function previa(array $produtos, array $extra = []): TestResponse
    {
        return $this->postJson(route('cardapio.carrinho.precificar'), $extra + [
            'itens' => array_map(fn (Produto $p): array => ['id' => $p->id, 'qty' => 1], $produtos),
        ]);
    }

    /** @param  list<array<string, mixed>>  $itens */
    private function checkout(array $itens, array $extra = []): TestResponse
    {
        return $this->postJson(route('cardapio.checkout'), $extra + [
            'nome' => 'Cliente Teste',
            'telefone' => '64999998888',
            'opcao_entrega_id' => $this->retirada->id,
            'pagamento_nome' => 'Dinheiro',
            'itens' => $itens,
        ]);
    }

    public function test_previa_da_sacola_traz_o_combo_fechado_e_o_desconto_de_cada_linha(): void
    {
        PromocaoCombo::factory()->daCategoria($this->grande)->create();

        $this->previa([$this->calabresa, $this->frango])
            ->assertOk()
            ->assertJsonPath('desconto', 22.01)
            ->assertJsonPath('linhas', ['0' => 11.99, '1' => 10.02])
            ->assertJsonPath('aplicacoes.0.nome', '2 Pizzas Grandes por R$ 87,99')
            ->assertJsonPath('aplicacoes.0.valor_cheio', 110)
            ->assertJsonPath('aplicacoes.0.itens', [0, 1])
            ->assertJsonPath('pendencias', []);

        $this->previa([$this->calabresa])
            ->assertOk()
            ->assertJsonPath('aplicacoes', [])
            ->assertJsonPath('pendencias.0.faltam', 1);
    }

    public function test_previa_sem_combo_na_mesa_quando_a_promocao_restringe_a_entrega(): void
    {
        PromocaoCombo::factory()->daCategoria($this->grande)->create(['promocombo_opcoes_entrega' => [$this->retirada->id]]);

        $this->previa([$this->calabresa, $this->frango], ['mesa' => true])->assertOk()->assertJsonPath('desconto', 0);
        $this->previa([$this->calabresa, $this->frango], ['opcao_entrega_id' => $this->retirada->id])->assertOk()->assertJsonPath('desconto', 22.01);
    }

    public function test_cardapio_lista_os_produtos_participantes_para_o_convite(): void
    {
        $promocao = PromocaoCombo::factory()->daCategoria($this->grande)->create(['promocombo_descricao' => 'Só hoje']);
        $promocao->participantes()->create(['pcp_produto_id' => $this->frango->id, 'pcp_excluir' => true]);
        $this->produto('Refri 2L', 12.00, Categoria::create(['categoria_nome' => 'Bebidas', 'categoria_cardapio' => true]));

        $combos = app(CardapioService::class)->dados()['promocoesCombo'];

        $this->assertCount(1, $combos);
        $this->assertSame([$this->calabresa->id], $combos[0]['produtos']);
        $this->assertSame(87.99, $combos[0]['preco']);
        $this->assertSame('Só hoje', $combos[0]['descricao']);
    }

    public function test_card_de_destaque_e_anuncio_mostram_a_imagem_e_os_participantes(): void
    {
        PromocaoCombo::factory()->daCategoria($this->grande)->create([
            'promocombo_imagem' => 'promocoes_combo/banner.jpg',
            'promocombo_imagem_mobile' => 'promocoes_combo/quadrada.jpg',
            'promocombo_descricao' => 'Toda quinta à noite',
        ]);

        $combo = app(CardapioService::class)->dados()['promocoesCombo'][0];
        $this->assertSame(asset('storage/promocoes_combo/banner.jpg'), $combo['imagem']);
        $this->assertSame(asset('storage/promocoes_combo/quadrada.jpg'), $combo['imagem_mobile']);

        $this->get(route('cardapio'))
            ->assertOk()
            ->assertSee('id="secao_combo"', false)
            ->assertSee('<source media="(min-width: 1024px)" srcset="'.asset('storage/promocoes_combo/banner.jpg').'"', false)
            ->assertSee('src="'.asset('storage/promocoes_combo/quadrada.jpg').'"', false)
            ->assertSee('Toda quinta à noite')
            ->assertSee('Promoção de hoje');
    }

    public function test_pagina_do_cardapio_monta_com_o_convite_e_a_comemoracao(): void
    {
        PromocaoCombo::factory()->daCategoria($this->grande)->create();

        $this->get(route('cardapio'))
            ->assertOk()
            ->assertSee('Que tal aproveitar nossa promoção?')
            ->assertSee('Parabéns! Você ganhou uma promoção!')
            ->assertSee('const _comboRoute', false);
    }

    public function test_checkout_grava_o_combo_nas_linhas_e_ignora_preco_do_navegador(): void
    {
        PromocaoCombo::factory()->daCategoria($this->grande)->create();

        $this->checkout([
            ['id' => $this->calabresa->id, 'qty' => 1, 'preco' => 1.00],
            ['id' => $this->frango->id, 'qty' => 1, 'preco' => 1.00],
        ])
            ->assertOk()
            ->assertJsonPath('total_final', 87.99)
            ->assertJsonPath('total_desconto', 22.01)
            ->assertJsonPath('combos.0.desconto', 22.01);

        $pedido = Pedido::sole();
        $this->assertSame('87.99', $pedido->pedido_valor_total);
        $this->assertSame('110.00', $pedido->pedido_valor_itens);
        $this->assertSame(['11.99', '10.02'], ItensPedido::orderBy('id')->pluck('item_pedido_desconto_combo')->all());
    }

    public function test_cupom_do_checkout_e_da_previa_incide_sobre_o_valor_com_combo(): void
    {
        PromocaoCombo::factory()->daCategoria($this->grande)->create(['promocombo_preco' => 80.00]);
        Cupom::create(['cupom_codigo' => 'DEZ', 'cupom_tipo_desconto' => TipoDescontoEnum::PERCENTUAL->value, 'cupom_valor_desconto' => 10]);
        $itens = [['id' => $this->calabresa->id, 'qty' => 1], ['id' => $this->frango->id, 'qty' => 1]];

        $this->postJson(route('cardapio.cupom.validar'), ['codigo_cupom' => 'DEZ', 'itens' => $itens])
            ->assertOk()
            ->assertJsonPath('valor_desconto', 8);

        $this->checkout($itens, ['codigo_cupom' => 'DEZ'])
            ->assertOk()
            ->assertJsonPath('valor_desconto_cupom', 8)
            ->assertJsonPath('total_final', 72);
    }

    public function test_grande_mais_brotinho_convida_so_para_o_grupo_que_falta_e_fecha_no_checkout(): void
    {
        $brotinhos = Categoria::create(['categoria_nome' => 'Pizza Brotinho', 'categoria_cardapio' => true]);
        $brotinho = $this->produto('Brotinho Calabresa', 30.00, $brotinhos);
        $promocao = PromocaoCombo::factory()->comGrupos([['Pizza Grande', 1, $this->grande], ['Pizza Brotinho', 1, $brotinhos]])
            ->create(['promocombo_nome' => 'Grande + Brotinho', 'promocombo_preco' => 60.00]);
        $grupoBrotinho = $promocao->grupos()->where('pcg_nome', 'Pizza Brotinho')->value('id');

        $combo = app(CardapioService::class)->dados()['promocoesCombo'][0];
        $this->assertSame('1× Pizza Grande + 1× Pizza Brotinho', $combo['composicao']);
        $this->assertSame([$brotinho->id], collect($combo['grupos'])->firstWhere('id', $grupoBrotinho)['produtos']);

        $this->previa([$this->calabresa, $this->frango])
            ->assertOk()
            ->assertJsonPath('aplicacoes', [])
            ->assertJsonPath('pendencias.0.grupos', [['grupo_id' => $grupoBrotinho, 'faltam' => 1]]);

        $this->checkout([['id' => $this->calabresa->id, 'qty' => 1], ['id' => $brotinho->id, 'qty' => 1]])
            ->assertOk()
            ->assertJsonPath('total_final', 60)
            ->assertJsonPath('combos.0.nome', 'Grande + Brotinho');
    }

    public function test_checkout_com_opcao_fora_da_lista_sai_pelo_preco_normal(): void
    {
        $delivery = OpcoesEntregas::create(['opcaoentrega_nome' => 'Delivery', 'opcaoentrega_valor_frete' => 0, 'opcaoentrega_min_valor_frete' => 0, 'opcaoentrega_requer_endereco' => true]);
        PromocaoCombo::factory()->daCategoria($this->grande)->create(['promocombo_opcoes_entrega' => [$delivery->id]]);

        $this->checkout([['id' => $this->calabresa->id, 'qty' => 2]])
            ->assertOk()
            ->assertJsonPath('total_final', 119.8)
            ->assertJsonPath('combos', []);
    }
}
