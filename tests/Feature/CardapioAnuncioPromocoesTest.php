<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Models\Categoria;
use App\Models\HorarioFuncionamento;
use App\Models\OpcoesEntregas;
use App\Models\Produto;
use App\Models\PromocaoAdicional;
use App\Models\PromocaoCombo;
use App\Models\PromocaoRelampago;
use App\Services\CardapioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Aviso ao abrir o cardápio: uma entrada por promoção em destaque (combo,
 * relâmpago e "leve outro produto"), na ordem da página, com o texto curto
 * da regra e a seção para onde o botão rola.
 */
class CardapioAnuncioPromocoesTest extends TestCase
{
    use RefreshDatabase;

    private Categoria $grande;

    private Produto $calabresa;

    private Produto $frango;

    private Produto $refri;

    private Produto $brotinho;

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
        $bebidas = Categoria::create(['categoria_nome' => 'Bebidas', 'categoria_cardapio' => true]);
        $broto = Categoria::create(['categoria_nome' => 'Pizza Brotinho', 'categoria_cardapio' => true]);
        $this->calabresa = $this->produto('Calabresa', 59.90, $this->grande);
        $this->frango = $this->produto('Frango', 50.10, $this->grande);
        $this->refri = $this->produto('Coca-Cola 2L', 16.00, $bebidas);
        $this->brotinho = $this->produto('Mussarela', 30.00, $broto);
    }

    private function produto(string $nome, float $preco, Categoria $categoria): Produto
    {
        return Produto::create([
            'produto_descricao' => $nome,
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => $preco,
            'produto_cardapio' => true,
        ]);
    }

    private function relampago(array $attrs = []): PromocaoRelampago
    {
        $promocao = PromocaoRelampago::create($attrs + [
            'promocao_nome' => 'Relâmpago Noite',
            'promocao_ativa' => true,
            'promocao_inicio' => now()->subHour(),
            'promocao_fim' => now()->addHour(),
        ]);
        $promocao->promocaoProdutos()->create(['prp_produto_id' => $this->frango->id, 'prp_preco_promocional' => 39.90]);
        $promocao->promocaoProdutos()->create(['prp_produto_id' => $this->calabresa->id, 'prp_preco_promocional' => 44.90]);

        return $promocao;
    }

    private function adicional(array $attrs = []): PromocaoAdicional
    {
        $promocao = PromocaoAdicional::create($attrs + [
            'promoad_nome' => 'Refri 2L = Brotinho grátis',
            'promoad_ativa' => true,
            'promoad_inicio' => now()->subHour(),
            'promoad_fim' => now()->addHour(),
            'promoad_categoria_exigida_id' => $this->grande->id,
        ]);
        $promocao->regras()->create(['par_produto_gatilho_id' => $this->refri->id])
            ->ofertas()->create(['pao_produto_oferta_id' => $this->brotinho->id, 'pao_valor_adicional' => 0]);

        return $promocao;
    }

    public function test_anuncia_combo_relampago_e_adicional_na_ordem_da_pagina(): void
    {
        $combo = PromocaoCombo::factory()->daCategoria($this->grande)->create(['promocombo_descricao' => 'Toda quinta']);
        $relampago = $this->relampago();
        $adicional = $this->adicional();

        $anuncios = app(CardapioService::class)->dados()['anunciosPromocoes'];

        $this->assertSame(['combo-'.$combo->id, 'relampago-'.$relampago->id, 'adicional-'.$adicional->id], array_column($anuncios, 'chave'));
        $this->assertSame(['secao_combo', 'secao_relampago', 'secao_promocao_adicional'], array_column($anuncios, 'secao'));

        $this->assertSame('Leve 2× Itens da promoção por R$ 87,99', $anuncios[0]['destaque']);
        $this->assertSame('Toda quinta', $anuncios[0]['descricao']);
        $this->assertSame('Preços a partir de R$ 39,90', $anuncios[1]['destaque']);
        $this->assertSame('Ganhe Pizza Brotinho: Mussarela grátis', $anuncios[2]['destaque']);
        $this->assertSame('Válido na compra de 1 Pizza Grande.', $anuncios[2]['nota']);
    }

    public function test_brinde_pago_e_relampago_com_um_produto_e_saldo(): void
    {
        $promocao = PromocaoRelampago::create([
            'promocao_nome' => 'Só a Frango',
            'promocao_ativa' => true,
            'promocao_inicio' => now()->subHour(),
            'promocao_fim' => now()->addHour(),
            'promocao_qtd_total' => 10,
            'promocao_qtd_vendida' => 4,
            'promocao_exibe_contador' => true,
        ]);
        $promocao->promocaoProdutos()->create(['prp_produto_id' => $this->frango->id, 'prp_preco_promocional' => 39.90]);
        $adicional = $this->adicional(['promoad_categoria_exigida_id' => null]);
        $adicional->regras->first()->ofertas->first()->update(['pao_valor_adicional' => 5]);

        [$relampago, $leveMais] = app(CardapioService::class)->dados()['anunciosPromocoes'];

        $this->assertSame('Frango por R$ 39,90', $relampago['destaque']);
        $this->assertSame('Quantidade limitada.', $relampago['nota']);
        $this->assertEquals(6, $relampago['saldo']);
        $this->assertSame('Leve Pizza Brotinho: Mussarela por +R$ 5,00', $leveMais['destaque']);
        $this->assertNull($leveMais['nota']);
    }

    public function test_sem_promocao_vigente_nao_anuncia_e_a_pagina_monta_o_aviso(): void
    {
        $this->relampago(['promocao_fim' => now()->subMinute()]);

        $this->assertSame([], app(CardapioService::class)->dados()['anunciosPromocoes']);

        $this->relampago();

        $this->get(route('cardapio'))
            ->assertOk()
            ->assertSee('id="titulo-anuncio-promocao"', false)
            ->assertSee('"anunciosPromocoes":[{"chave":"relampago-', false);
    }

    public function test_mesa_nao_anuncia_promocao_restrita_a_entrega(): void
    {
        $retirada = OpcoesEntregas::create(['opcaoentrega_nome' => 'Retirada', 'opcaoentrega_valor_frete' => 0, 'opcaoentrega_min_valor_frete' => 0]);
        $this->relampago(['promocao_opcoes_entrega' => [$retirada->id]]);
        $this->adicional(['promoad_opcoes_entrega' => [$retirada->id]]);

        $this->assertSame([], app(CardapioService::class)->dados(mesa: true)['anunciosPromocoes']);
        $this->assertCount(2, app(CardapioService::class)->dados()['anunciosPromocoes']);
    }
}
