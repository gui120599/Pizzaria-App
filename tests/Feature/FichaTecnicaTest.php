<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\FichaTecnicaItem;
use App\Models\Produto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FichaTecnicaTest extends TestCase
{
    use RefreshDatabase;

    private int $categoriaId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->categoriaId = Categoria::create(['categoria_nome' => 'Teste'])->id;
    }

    private function insumo(string $nome, float $custo): Produto
    {
        return Produto::create([
            'produto_descricao' => $nome,
            'produto_categoria_id' => $this->categoriaId,
            'produto_tipo' => 'insumo',
            'produto_custo_medio' => $custo,
            'produto_unidade_estoque' => 'KG',
        ]);
    }

    private function produzido(string $nome, float $rendimento = 1): Produto
    {
        return Produto::create([
            'produto_descricao' => $nome,
            'produto_categoria_id' => $this->categoriaId,
            'produto_tipo' => 'produzido',
            'produto_unidade_estoque' => 'UN',
            'produto_ficha_rendimento' => $rendimento,
        ]);
    }

    public function test_produto_sem_ficha_usa_custo_medio(): void
    {
        $produto = $this->insumo('Farinha', 5.00);
        $this->assertEqualsWithDelta(5.0, $produto->custoUnitario(), 0.0001);
    }

    public function test_custo_medio_zero_cai_para_preco_de_custo_manual(): void
    {
        // Insumo "por consumo" (ex.: água/gás), nunca teve entrada de estoque
        // registrada — produto_custo_medio fica em 0 para sempre.
        $agua = Produto::create([
            'produto_descricao' => 'Água',
            'produto_categoria_id' => $this->categoriaId,
            'produto_tipo' => 'insumo',
            'produto_custo_medio' => 0,
            'produto_preco_custo' => 0.00950475,
            'produto_unidade_estoque' => 'L',
        ]);

        $this->assertEqualsWithDelta(0.00950475, $agua->custoUnitario(), 0.000000001);
    }

    public function test_custo_medio_zero_e_preco_custo_nulo_retorna_zero(): void
    {
        $agua = Produto::create([
            'produto_descricao' => 'Água',
            'produto_categoria_id' => $this->categoriaId,
            'produto_tipo' => 'insumo',
            'produto_custo_medio' => 0,
            'produto_preco_custo' => null,
            'produto_unidade_estoque' => 'L',
        ]);

        $this->assertSame(0.0, $agua->custoUnitario());
    }

    public function test_ficha_tecnica_usa_preco_custo_manual_de_insumo_sem_entrada_de_estoque(): void
    {
        // Água a R$ 0,00950475/L, sem nenhuma compra registrada (custo médio 0).
        $agua = Produto::create([
            'produto_descricao' => 'Água',
            'produto_categoria_id' => $this->categoriaId,
            'produto_tipo' => 'insumo',
            'produto_custo_medio' => 0,
            'produto_preco_custo' => 0.00950475,
            'produto_unidade_estoque' => 'L',
        ]);

        $pizza = $this->produzido('Pizza', rendimento: 1);
        FichaTecnicaItem::create(['fti_produto_id' => $pizza->id, 'fti_insumo_id' => $agua->id, 'fti_quantidade' => 0.133, 'fti_percentual_perda' => 0]);

        $this->assertEqualsWithDelta(0.00950475 * 0.133, $pizza->custoUnitario(), 0.000000001);
    }

    public function test_custo_da_ficha_soma_insumos_com_perda_e_rendimento(): void
    {
        $farinha = $this->insumo('Farinha', 5.00);
        $tomate = $this->insumo('Tomate', 4.00);

        $pizza = $this->produzido('Pizza', rendimento: 1);
        // 0,2 KG farinha com 10% perda => 0,2 * 5 * 1,1 = 1,10
        FichaTecnicaItem::create(['fti_produto_id' => $pizza->id, 'fti_insumo_id' => $farinha->id, 'fti_quantidade' => 0.2, 'fti_percentual_perda' => 10]);
        // 0,3 KG tomate sem perda => 0,3 * 4 = 1,20
        FichaTecnicaItem::create(['fti_produto_id' => $pizza->id, 'fti_insumo_id' => $tomate->id, 'fti_quantidade' => 0.3, 'fti_percentual_perda' => 0]);

        $this->assertEqualsWithDelta(2.30, $pizza->custoUnitario(), 0.0001);
        $this->assertEqualsWithDelta(2.30, $pizza->custoFicha(), 0.0001);
    }

    public function test_custo_multinivel_com_semiacabado(): void
    {
        $tomate = $this->insumo('Tomate', 4.00);
        $sal = $this->insumo('Sal', 2.00);

        // Molho: rendimento 2 KG = 1 KG tomate (4,00) + 0,1 KG sal (0,20) => total 4,20 => 2,10/KG
        $molho = $this->produzido('Molho', rendimento: 2);
        FichaTecnicaItem::create(['fti_produto_id' => $molho->id, 'fti_insumo_id' => $tomate->id, 'fti_quantidade' => 1, 'fti_percentual_perda' => 0]);
        FichaTecnicaItem::create(['fti_produto_id' => $molho->id, 'fti_insumo_id' => $sal->id, 'fti_quantidade' => 0.1, 'fti_percentual_perda' => 0]);

        $this->assertEqualsWithDelta(2.10, $molho->custoUnitario(), 0.0001);

        // Pizza usa 0,5 do molho => 0,5 * 2,10 = 1,05
        $pizza = $this->produzido('Pizza', rendimento: 1);
        FichaTecnicaItem::create(['fti_produto_id' => $pizza->id, 'fti_insumo_id' => $molho->id, 'fti_quantidade' => 0.5, 'fti_percentual_perda' => 0]);

        $this->assertEqualsWithDelta(1.05, $pizza->custoUnitario(), 0.0001);
    }

    public function test_protege_contra_ciclo_na_ficha(): void
    {
        $a = $this->produzido('A');
        $b = $this->produzido('B');
        // A usa B e B usa A (ciclo)
        FichaTecnicaItem::create(['fti_produto_id' => $a->id, 'fti_insumo_id' => $b->id, 'fti_quantidade' => 1, 'fti_percentual_perda' => 0]);
        FichaTecnicaItem::create(['fti_produto_id' => $b->id, 'fti_insumo_id' => $a->id, 'fti_quantidade' => 1, 'fti_percentual_perda' => 0]);

        // Não deve estourar (recursão infinita); apenas retorna um número finito.
        $this->assertIsFloat($a->custoUnitario());
    }

    public function test_depende_de_detecta_dependencia_direta_e_indireta(): void
    {
        $a = $this->produzido('A');
        $b = $this->produzido('B');
        $c = $this->produzido('C');

        // A usa B, B usa C (sem ciclo ainda).
        FichaTecnicaItem::create(['fti_produto_id' => $a->id, 'fti_insumo_id' => $b->id, 'fti_quantidade' => 1, 'fti_percentual_perda' => 0]);
        FichaTecnicaItem::create(['fti_produto_id' => $b->id, 'fti_insumo_id' => $c->id, 'fti_quantidade' => 1, 'fti_percentual_perda' => 0]);

        $this->assertTrue($a->dependeDe($b->id));
        $this->assertTrue($a->dependeDe($c->id)); // indireta, via B
        $this->assertFalse($c->dependeDe($a->id));
        $this->assertFalse($b->dependeDe($a->id));

        // Tentar colocar A como insumo de C fecharia o ciclo A -> B -> C -> A.
        // A validação do formulário checa exatamente isto: o candidato a
        // insumo (A) já depende do dono da ficha (C)?
        $this->assertTrue($a->dependeDe($c->id));
    }

    public function test_depende_de_nao_trava_com_ciclo_ja_existente_na_base(): void
    {
        $a = $this->produzido('A');
        $b = $this->produzido('B');
        FichaTecnicaItem::create(['fti_produto_id' => $a->id, 'fti_insumo_id' => $b->id, 'fti_quantidade' => 1, 'fti_percentual_perda' => 0]);
        FichaTecnicaItem::create(['fti_produto_id' => $b->id, 'fti_insumo_id' => $a->id, 'fti_quantidade' => 1, 'fti_percentual_perda' => 0]);

        $this->assertTrue($a->dependeDe($b->id));
        $this->assertIsBool($a->dependeDe(999999));
    }
}
