<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Models\Categoria;
use App\Models\OpcoesPagamento;
use App\Models\Produto;
use App\Models\PromocaoAdicional;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CardapioPromocaoAdicionalViewTest extends TestCase
{
    use RefreshDatabase;

    private Categoria $categoria;

    private Produto $pizza;

    private Produto $brotinho;

    protected function setUp(): void
    {
        parent::setUp();

        $this->categoria = Categoria::create([
            'categoria_nome' => 'Pizzas',
            'categoria_cardapio' => true,
        ]);

        $this->pizza = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => $this->categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 59.90,
            'produto_cardapio' => true,
        ]);

        $this->brotinho = Produto::create([
            'produto_descricao' => 'Pizza Brotinho',
            'produto_categoria_id' => $this->categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 20.00,
            'produto_cardapio' => true,
        ]);
    }

    private function regra(array $attrs = []): PromocaoAdicional
    {
        $promocao = PromocaoAdicional::create(array_merge([
            'promoad_nome' => 'Dia do Cliente',
            'promoad_descricao' => 'Leve uma Pizza Brotinho por apenas +R$5,00',
            'promoad_ativa' => true,
            'promoad_inicio' => now()->subHour(),
            'promoad_fim' => now()->addHour(),
        ], $attrs));

        $regra = $promocao->regras()->create([
            'par_produto_gatilho_id' => $this->pizza->id,
            'par_preco_gatilho_override' => 39.90,
        ]);

        $regra->ofertas()->create([
            'pao_produto_oferta_id' => $this->brotinho->id,
            'pao_valor_adicional' => 5.00,
        ]);

        return $promocao;
    }

    public function test_regra_vigente_aparece_no_payload_do_cardapio(): void
    {
        $this->regra();

        $response = $this->get(route('cardapio'))->assertOk();
        $payload = $response->viewData('promocoesAdicionais');

        $this->assertTrue($payload->has($this->pizza->id));
        $ofertas = $payload->get($this->pizza->id)['ofertas'];
        $this->assertCount(1, $ofertas);
        $this->assertSame('Pizza Brotinho', $ofertas->first()['nome']);
        $this->assertSame('Pizzas: Pizza Brotinho', $ofertas->first()['nomeExibicao']);
        $this->assertSame(5.0, $ofertas->first()['valorAdicional']);
    }

    public function test_regra_com_duas_ofertas_lista_as_duas_no_payload(): void
    {
        $promocao = $this->regra();
        $refrigerante = Produto::create([
            'produto_descricao' => 'Refrigerante 2L',
            'produto_categoria_id' => $this->categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 12.00,
            'produto_cardapio' => true,
        ]);
        $regra = $promocao->regras()->first();
        $regra->ofertas()->create([
            'pao_produto_oferta_id' => $refrigerante->id,
            'pao_valor_adicional' => 8.00,
        ]);

        $response = $this->get(route('cardapio'))->assertOk();
        $ofertas = $response->viewData('promocoesAdicionais')->get($this->pizza->id)['ofertas'];

        $this->assertCount(2, $ofertas);
    }

    public function test_promocao_inativa_nao_aparece_no_payload(): void
    {
        $this->regra(['promoad_ativa' => false]);

        $response = $this->get(route('cardapio'))->assertOk();

        $this->assertTrue($response->viewData('promocoesAdicionais')->isEmpty());
    }

    public function test_promocao_fora_do_periodo_nao_aparece_no_payload(): void
    {
        $this->regra([
            'promoad_inicio' => now()->subDays(10),
            'promoad_fim' => now()->subDay(),
        ]);

        $response = $this->get(route('cardapio'))->assertOk();

        $this->assertTrue($response->viewData('promocoesAdicionais')->isEmpty());
    }

    public function test_produto_sem_regra_nao_tem_entrada_no_payload(): void
    {
        // Sem nenhuma promoção cadastrada, o payload deve ser um objeto vazio.
        $response = $this->get(route('cardapio'))->assertOk();

        $this->assertTrue($response->viewData('promocoesAdicionais')->isEmpty());
    }

    public function test_pagina_embute_o_payload_como_json_para_o_alpine(): void
    {
        $this->regra();

        $this->get(route('cardapio'))
            ->assertOk()
            ->assertSee('const _promocoesAdicionais', false);
    }

    public function test_campanha_ativa_aparece_na_secao_informativa(): void
    {
        $this->regra();

        $response = $this->get(route('cardapio'))->assertOk();
        $campanhas = $response->viewData('campanhasAdicionaisAtivas');

        $this->assertCount(1, $campanhas);
        $this->assertSame('Dia do Cliente', $campanhas->first()['nome']);
    }

    public function test_opcoes_pagamento_permitidas_aparecem_no_payload(): void
    {
        $promocao = $this->regra();
        $pix = OpcoesPagamento::create(['opcaopag_nome' => 'PIX']);
        $promocao->opcoesPagamento()->attach($pix->id);

        $response = $this->get(route('cardapio'))->assertOk();
        $entrada = $response->viewData('promocoesAdicionais')->get($this->pizza->id);

        $this->assertSame([$pix->id], $entrada['opcoesPagamentoPermitidas']);
    }
}
