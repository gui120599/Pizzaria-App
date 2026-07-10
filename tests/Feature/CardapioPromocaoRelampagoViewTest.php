<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Models\Categoria;
use App\Models\Produto;
use App\Models\PromocaoRelampago;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CardapioPromocaoRelampagoViewTest extends TestCase
{
    use RefreshDatabase;

    private Categoria $categoria;

    protected function setUp(): void
    {
        parent::setUp();

        $this->categoria = Categoria::create([
            'categoria_nome' => 'Pizzas',
            'categoria_cardapio' => true,
        ]);
    }

    private function produto(string $nome): Produto
    {
        return Produto::create([
            'produto_descricao' => $nome,
            'produto_categoria_id' => $this->categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 55.00,
            'produto_cardapio' => true,
        ]);
    }

    private function categoriaComSabores(): void
    {
        $this->categoria->update([
            'categoria_permite_sabores' => true,
            'categoria_max_sabores' => 2,
        ]);
    }

    private function promocao(array $attrs, Produto ...$produtos): PromocaoRelampago
    {
        $promocao = PromocaoRelampago::create(array_merge([
            'promocao_nome' => 'Dia da Pizza',
            'promocao_ativa' => true,
            'promocao_inicio' => now()->subHour(),
            'promocao_fim' => now()->addHour(),
        ], $attrs));

        foreach ($produtos as $produto) {
            $promocao->promocaoProdutos()->create([
                'prp_produto_id' => $produto->id,
                'prp_preco_promocional' => 39.90,
            ]);
        }

        return $promocao;
    }

    public function test_secao_relampago_aparece_com_nome_e_preco(): void
    {
        $this->promocao(['promocao_qtd_total' => 40], $this->produto('Calabresa'));

        $this->get(route('cardapio'))
            ->assertOk()
            ->assertSee('Dia da Pizza')
            ->assertSee('RELÂMPAGO')
            ->assertSee('39,90');
    }

    public function test_contador_aparece_quando_habilitado(): void
    {
        // 40 total, 8 vendidas → restam 32; sem limiar mostra desde já.
        $promo = $this->promocao([
            'promocao_qtd_total' => 40,
            'promocao_qtd_vendida' => 8,
            'promocao_exibe_contador' => true,
        ], $this->produto('Calabresa'));

        $this->get(route('cardapio'))
            ->assertOk()
            ->assertSee('restam')
            ->assertSee('32');
    }

    public function test_contador_some_com_limiar_de_escassez_nao_atingido(): void
    {
        // Restam 30, mas só mostra quando restarem <= 5.
        $this->promocao([
            'promocao_qtd_total' => 40,
            'promocao_qtd_vendida' => 10,
            'promocao_exibe_contador' => true,
            'promocao_limiar_escassez' => 5,
        ], $this->produto('Calabresa'));

        $this->get(route('cardapio'))
            ->assertOk()
            ->assertDontSee('restam');
    }

    public function test_promocao_esgotada_nao_aparece(): void
    {
        $this->promocao([
            'promocao_qtd_total' => 10,
            'promocao_qtd_vendida' => 10,
        ], $this->produto('Calabresa'));

        $this->get(route('cardapio'))
            ->assertOk()
            ->assertDontSee('RELÂMPAGO');
    }

    public function test_promocao_fora_da_janela_nao_aparece(): void
    {
        $this->promocao([
            'promocao_qtd_total' => 40,
            'promocao_inicio' => now()->addDay(),
            'promocao_fim' => now()->addDays(2),
        ], $this->produto('Calabresa'));

        $this->get(route('cardapio'))
            ->assertOk()
            ->assertDontSee('RELÂMPAGO');
    }

    public function test_promocao_so_inteira_oferece_sabores_com_fracao_no_preco_normal(): void
    {
        $this->categoriaComSabores();
        $this->promocao(
            ['promocao_qtd_total' => 40, 'promocao_permite_sabores' => false],
            $this->produto('Calabresa'),
        );

        $html = $this->get(route('cardapio'))->assertOk()->getContent();

        // Produto em promoção "só inteira" agora é selecionável como sabor.
        $this->assertStringContainsString('$store.cart.abrirSabores(', $html);
        // A lista de sabores leva o preço promocional (inteiro) e o preço de
        // fração de volta ao normal (venda). O @js escapa aspas como ".
        $this->assertStringContainsString('preco\u0022:39.9', $html);
        $this->assertStringContainsString('precoFracao\u0022:55', $html);
    }

    public function test_promocao_que_permite_sabores_mantem_meia_a_meia(): void
    {
        $this->categoriaComSabores();
        $this->promocao(
            ['promocao_qtd_total' => 40, 'promocao_permite_sabores' => true],
            $this->produto('Calabresa'),
            $this->produto('Marguerita'),
        );

        $html = $this->get(route('cardapio'))->assertOk()->getContent();

        $this->assertStringContainsString('$store.cart.abrirSabores(', $html);
    }

    public function test_render_nao_dispara_n_mais_um_de_promocoes_vigentes(): void
    {
        // Vários produtos: se o PrecificadorService não fosse singleton, cada
        // card dispararia uma query de promoções vigentes.
        $this->promocao(
            ['promocao_qtd_total' => 40],
            $this->produto('Calabresa'),
            $this->produto('Marguerita'),
            $this->produto('Portuguesa'),
        );

        DB::enableQueryLog();
        $this->get(route('cardapio'))->assertOk();
        $queries = collect(DB::getQueryLog());

        $vigentes = $queries->filter(
            fn ($q) => str_contains($q['query'], 'promocoes_relampago')
                && str_contains($q['query'], 'promocao_inicio')
        );

        // Uma carga no controller + no máximo uma do PrecificadorService memoizado.
        $this->assertLessThanOrEqual(2, $vigentes->count(), 'N+1 de promoções vigentes no cardápio');
    }
}
