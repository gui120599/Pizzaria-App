<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Enums\RegraPrecoSaboresEnum;
use App\Models\Categoria;
use App\Models\Produto;
use App\Services\PrecificadorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regra de preço da pizza de vários sabores configurada por categoria
 * (categoria_regra_preco_sabores): MEDIA é o comportamento histórico, MAIOR
 * cobra o sabor mais caro.
 */
class PrecificadorRegraPrecoSaboresTest extends TestCase
{
    use RefreshDatabase;

    private function categoria(array $attrs = []): Categoria
    {
        $categoria = Categoria::create(array_merge([
            'categoria_nome' => 'Pizza Grande',
            'categoria_permite_sabores' => true,
        ], $attrs));
        $categoria->sincronizarQuantidadesSabores(3);

        return $categoria;
    }

    private function produto(Categoria $categoria, string $nome, float $preco): Produto
    {
        return Produto::create([
            'produto_descricao' => $nome,
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => $preco,
            'produto_cardapio' => true,
        ]);
    }

    /**
     * @param  array<int, Produto>  $sabores
     * @return array<string, mixed>
     */
    private function precificar(array $sabores): array
    {
        $precificador = app(PrecificadorService::class);
        $sabores = array_map(fn (Produto $p) => $p->fresh('categoria'), $sabores);

        return $precificador->precificarCombo($sabores, 1, $precificador->opcaoDoCombo($sabores));
    }

    public function test_categoria_sem_regra_definida_mantem_a_media(): void
    {
        $categoria = $this->categoria();

        $linha = $this->precificar([
            $this->produto($categoria, 'Calabresa', 50.00),
            $this->produto($categoria, 'Portuguesa', 70.00),
        ]);

        $this->assertSame(60.0, $linha['valor']);
    }

    public function test_regra_maior_cobra_o_sabor_mais_caro_e_rateia_as_fatias(): void
    {
        $categoria = $this->categoria(['categoria_regra_preco_sabores' => RegraPrecoSaboresEnum::MAIOR]);

        $linha = $this->precificar([
            $this->produto($categoria, 'Calabresa', 50.00),
            $this->produto($categoria, 'Mussarela', 60.00),
            $this->produto($categoria, 'Portuguesa', 70.00),
        ]);

        $this->assertSame(70.0, $linha['valor']);
        $this->assertEquals(70.0, $linha['valor_unitario']);
        $this->assertSame(70.0, round(array_sum(array_column($linha['sabores'], 'valor_fatia')), 2));
        // Rateio proporcional ao preço cheio: o sabor mais caro leva a maior fatia.
        $this->assertGreaterThan($linha['sabores'][0]['valor_fatia'], $linha['sabores'][2]['valor_fatia']);
    }

    public function test_subcategoria_que_herda_sabores_herda_a_regra_do_pai(): void
    {
        $pai = $this->categoria(['categoria_regra_preco_sabores' => RegraPrecoSaboresEnum::MAIOR]);
        $filha = Categoria::create([
            'categoria_nome' => 'Pizza Especial',
            'categoria_pai_id' => $pai->id,
            'categoria_permite_sabores' => true,
            'categoria_herda_sabores' => true,
        ]);

        $linha = $this->precificar([
            $this->produto($filha, 'Camarão', 90.00),
            $this->produto($filha, 'Bacalhau', 100.00),
        ]);

        $this->assertSame(100.0, $linha['valor']);
    }

    public function test_cardapio_publico_recebe_a_regra_da_categoria(): void
    {
        $categoria = $this->categoria([
            'categoria_regra_preco_sabores' => RegraPrecoSaboresEnum::MAIOR,
            'categoria_cardapio' => true,
        ]);
        $this->produto($categoria, 'Calabresa', 50.00);

        $this->get(route('cardapio'))
            ->assertOk()
            ->assertViewHas('categoriasComSabores', fn ($categorias) => collect($categorias)
                ->firstWhere('id', $categoria->id)['regraPreco'] === 'MAIOR');
    }
}
